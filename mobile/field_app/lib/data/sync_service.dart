import 'dart:math';

import '../core/api.dart';
import '../core/config.dart';
import 'field_repository.dart';
import 'outbox.dart';
import 'pos_repository.dart';

class SyncResult {
  int sent = 0;
  int refused = 0;
  int retryLater = 0;
  bool offline = false;
  bool unauthenticated = false;

  /// The server deauthorized this device; [wipe] asks the app to erase its local data.
  bool revoked = false;
  bool wipe = false;
  String? message;

  bool get failedToReachServer => offline || retryLater > 0 || unauthenticated;
}

/// The device side of the sync contract (ADR-0011). The outbox is sent in
/// capture order as envelopes `{client_txn_id, type, schema_version,
/// client_created_at, attempt, payload}` in batches of up to 50. Each
/// acknowledgement is applied on its own:
///
///   synced          the server's result is recorded and the item removed
///   conflict        kept as "needs attention" with the server's reason
///   rejected        kept as "needs attention" with the server's reason
///   retry_required  kept as pending; the run stops and backs off
///
/// A lost response is harmless: resending the same client_txn_id returns
/// the stored acknowledgement and nothing is applied twice.
class SyncService {
  SyncService(this.repo, {this.pos, this.batchSize = 50});

  final FieldRepository repo;
  final PosRepository? pos;
  final int batchSize;
  bool _running = false;

  bool get running => _running;

  static const types = {
    'visit_check_in': 'field.visit.check_in',
    'visit_check_out': 'field.visit.check_out',
    'visit_cancel': 'field.visit.cancel',
    'order_capture': 'field.order.capture',
    'order_resubmit': 'field.order.resubmit',
    'follow_up_complete': 'field.follow_up.complete',
    'pos_sale': 'pos.sale',
  };

  /// Exponential backoff with jitter: 2 s doubling up to 15 minutes.
  static Duration backoff(int failures, {Random? random}) {
    final base = min(2 * pow(2, max(0, failures - 1)).toInt(), 15 * 60);
    final jitter = (random ?? Random()).nextDouble() * 0.25 * base;
    return Duration(milliseconds: ((base + jitter) * 1000).round());
  }

  Future<SyncResult> run() async {
    final result = SyncResult();
    if (_running) return result..message = 'Sync already in progress.';
    _running = true;
    try {
      while (true) {
        final batch = await repo.outbox.pendingBatch(batchSize);
        if (batch.isEmpty) break;

        final envelopes = <Map<String, dynamic>>[];
        for (final item in batch) {
          envelopes.add({
            'client_txn_id': item.clientTxnId,
            'type': types[item.operation] ?? item.operation,
            'schema_version': 1,
            'client_created_at': item.createdAt.toUtc().toIso8601String(),
            'attempt': item.attempts + 1,
            'payload': _payload(item),
          });
        }

        final List acks;
        try {
          acks = (await repo.api.post('sync/batches', {'envelopes': envelopes}))['data'] as List;
        } on OfflineException catch (e) {
          await _attempted(batch, e.message);
          result.offline = true;
          result.message = e.message;
          break;
        } on ApiException catch (e) {
          await _attempted(batch, e.message);
          if (e.code == 'device_revoked') {
            result
              ..revoked = true
              ..wipe = e.details['wipe'] == true
              ..message = e.message;
          } else if (e.isUnauthenticated) {
            result
              ..unauthenticated = true
              ..message = 'Your session has expired. Sign in again to continue syncing.';
          } else {
            result
              ..retryLater = batch.length
              ..message = e.message;
          }
          break;
        }

        final byTxn = {for (final item in batch) item.clientTxnId: item};
        var stop = false;
        for (final raw in acks) {
          final ack = Map<String, dynamic>.from(raw as Map);
          final item = byTxn[ack['client_txn_id']];
          if (item == null) continue;
          switch (ack['status']) {
            case 'synced':
              await _apply(item, Map<String, dynamic>.from((ack['result'] ?? const {}) as Map), (ack['warnings'] as List?) ?? const []);
              await repo.outbox.complete(item.id);
              await repo.settle(item.operation, item.entityRef);
              result.sent++;
            case 'conflict':
            case 'rejected':
              final error = Map<String, dynamic>.from((ack['error'] ?? const {}) as Map);
              await _refuse(item, error['message']?.toString() ?? 'Refused by the server.', ack['status'] as String);
              result.refused++;
            default: // retry_required
              await repo.outbox.recordAttempt(item.id, 'The server will process this later.');
              result.retryLater++;
              stop = true;
          }
        }
        if (stop) break;
      }
    } finally {
      _running = false;
    }
    return result;
  }

  Future<void> _attempted(List<OutboxItem> batch, String error) async {
    for (final item in batch) {
      await repo.outbox.recordAttempt(item.id, error);
    }
  }

  Future<void> _refuse(OutboxItem item, String message, String kind) async {
    await repo.outbox.fail(item.id, message, kind: kind);
    await repo.outbox.failDependents(item.entityRef, 'Not sent because an earlier step was refused: $message');
    await repo.markFailed(item.operation, item.entityRef, message);
  }

  bool _late(String? capturedAt) {
    final at = capturedAt == null ? null : DateTime.tryParse(capturedAt);
    return at != null && DateTime.now().difference(at) > AppConfig.offlineCaptureThreshold;
  }

  /// Payloads reference other records by client reference, so a device never needs a server id.
  Map<String, dynamic> _payload(OutboxItem item) {
    final p = Map<String, dynamic>.of(item.payload);
    switch (item.operation) {
      case 'visit_check_in':
        p['captured_offline'] = _late((p['check_in'] as Map)['occurred_at'] as String?);
      case 'visit_check_out':
      case 'visit_cancel':
        p['visit_client_reference'] = item.entityRef;
      case 'order_capture':
        final visitRef = p.remove('visit_reference');
        if (visitRef != null) p['field_visit_client_reference'] = visitRef;
        p['captured_offline'] = _late(p['captured_at'] as String?);
      case 'order_resubmit':
        p['order_client_reference'] = item.entityRef;
      case 'follow_up_complete':
        p['follow_up_id'] = item.entityRef;
    }
    return p;
  }

  Future<void> _apply(OutboxItem item, Map<String, dynamic> result, List warnings) async {
    switch (item.operation) {
      case 'visit_check_in':
      case 'visit_check_out':
      case 'visit_cancel':
        await repo.applyVisit(item.entityRef, result);
      case 'order_capture':
      case 'order_resubmit':
        await repo.applyOrder(item.entityRef, result);
      case 'follow_up_complete':
        await repo.applyFollowUp(item.entityRef, result);
      case 'pos_sale':
        await pos?.applySale(item.entityRef, result, warnings);
    }
  }
}
