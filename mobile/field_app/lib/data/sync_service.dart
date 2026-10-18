import '../core/api.dart';
import '../core/config.dart';
import 'field_repository.dart';
import 'outbox.dart';

class SyncResult {
  int sent = 0;
  int refused = 0;
  bool offline = false;
  bool unauthenticated = false;
  String? message;
}

/// A step that cannot be sent because an earlier one never reached the server.
class DependencyException implements Exception {
  DependencyException(this.message);

  final String message;

  @override
  String toString() => message;
}

/// Sends the outbox in order. Every request carries the record's
/// client_reference (or targets its server id), so a retry after a lost
/// response cannot create a duplicate. Connection problems and server errors
/// stop the run and keep the item for later; a refusal (4xx business rule)
/// marks the item and the steps that depend on it as needing attention.
class SyncService {
  SyncService(this.repo);

  final FieldRepository repo;
  bool _running = false;

  bool get running => _running;

  Future<SyncResult> run() async {
    final result = SyncResult();
    if (_running) return result..message = 'Sync already in progress.';
    _running = true;
    try {
      while (true) {
        final item = await repo.outbox.nextPending();
        if (item == null) break;
        try {
          await _send(item);
          await repo.outbox.complete(item.id);
          await repo.settle(item.operation, item.entityRef);
          result.sent++;
        } on OfflineException catch (e) {
          await repo.outbox.recordAttempt(item.id, e.message);
          result.offline = true;
          result.message = e.message;
          break;
        } on ApiException catch (e) {
          if (e.isUnauthenticated) {
            await repo.outbox.recordAttempt(item.id, e.message);
            result.unauthenticated = true;
            result.message = 'Your session has expired. Sign in again to continue syncing.';
            break;
          }
          if (e.isRetryable) {
            await repo.outbox.recordAttempt(item.id, e.message);
            result.message = e.message;
            break;
          }
          await _refuse(item, e.message);
          result.refused++;
        } on DependencyException catch (e) {
          await _refuse(item, e.message);
          result.refused++;
        }
      }
    } finally {
      _running = false;
    }
    return result;
  }

  Future<void> _refuse(OutboxItem item, String message) async {
    await repo.outbox.fail(item.id, message);
    await repo.outbox.failDependents(item.entityRef, 'Not sent because an earlier step was refused: $message');
    await repo.markFailed(item.operation, item.entityRef, message);
  }

  bool _late(String? capturedAt) {
    final at = capturedAt == null ? null : DateTime.tryParse(capturedAt);
    return at != null && DateTime.now().difference(at) > AppConfig.offlineCaptureThreshold;
  }

  Future<void> _send(OutboxItem item) async {
    final p = Map<String, dynamic>.of(item.payload);
    switch (item.operation) {
      case 'visit_check_in':
        p['captured_offline'] = _late((p['check_in'] as Map)['occurred_at'] as String?);
        final res = await repo.api.post('field-force/visits', p);
        await repo.applyVisit(item.entityRef, Map<String, dynamic>.from(res['data'] as Map));

      case 'visit_check_out':
        final id = await repo.visitServerId(item.entityRef) ?? (throw DependencyException('The check-in for this visit was not accepted by the server.'));
        final res = await repo.api.post('field-force/visits/$id/check-out', p);
        await repo.applyVisit(item.entityRef, Map<String, dynamic>.from(res['data'] as Map));

      case 'visit_cancel':
        final id = await repo.visitServerId(item.entityRef) ?? (throw DependencyException('The check-in for this visit was not accepted by the server.'));
        final res = await repo.api.post('field-force/visits/$id/cancel', p);
        await repo.applyVisit(item.entityRef, Map<String, dynamic>.from(res['data'] as Map));

      case 'order_capture':
        final visitRef = p.remove('visit_reference') as String?;
        final visitId = visitRef == null ? null : await repo.visitServerId(visitRef);
        if (visitId != null) p['field_visit_id'] = visitId;
        p['captured_offline'] = _late(p['captured_at'] as String?);
        final res = await repo.api.post('field-force/orders', p);
        await repo.applyOrder(item.entityRef, Map<String, dynamic>.from(res['data'] as Map));

      case 'order_resubmit':
        final id = await repo.orderServerId(item.entityRef) ?? (throw DependencyException('The original order never reached the server.'));
        await repo.api.patch('field-force/orders/$id', p);
        final res = await repo.api.post('field-force/orders/$id/submit');
        await repo.applyOrder(item.entityRef, Map<String, dynamic>.from(res['data'] as Map));

      case 'follow_up_complete':
        final res = await repo.api.post('field-force/follow-ups/${item.entityRef}/complete', p);
        await repo.applyFollowUp(item.entityRef, Map<String, dynamic>.from(res['data'] as Map));

      default:
        throw DependencyException('Unknown operation ${item.operation}.');
    }
  }
}
