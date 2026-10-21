import 'dart:convert';

import 'package:sqflite_sqlcipher/sqflite.dart';
import 'package:uuid/uuid.dart';

/// Sync state of a queued change, as shown to people (spec §13.3).
enum SyncState { pendingSync, retryRequired, conflict, rejected }

class OutboxItem {
  OutboxItem({
    required this.id,
    required this.clientTxnId,
    required this.operation,
    required this.entityRef,
    required this.payload,
    required this.status,
    required this.attempts,
    required this.createdAt,
    this.failureKind,
    this.lastError,
    this.lastAttemptAt,
  });

  final int id;

  /// Stable across retries: the server's idempotency key for this change.
  final String clientTxnId;

  /// visit_check_in | visit_check_out | visit_cancel | order_capture | order_resubmit | follow_up_complete | pos_sale
  final String operation;

  /// client_reference (or server id for follow-ups) of the record the operation belongs to.
  final String entityRef;
  final Map<String, dynamic> payload;

  /// pending | failed
  final String status;
  final int attempts;
  final DateTime createdAt;

  /// conflict | rejected, when the server refused it.
  final String? failureKind;
  final String? lastError;
  final DateTime? lastAttemptAt;

  SyncState get state => status == 'failed'
      ? (failureKind == 'conflict' ? SyncState.conflict : SyncState.rejected)
      : (attempts > 0 ? SyncState.retryRequired : SyncState.pendingSync);

  static OutboxItem fromRow(Map<String, Object?> r) => OutboxItem(
        id: r['id'] as int,
        clientTxnId: r['client_txn_id'] as String,
        operation: r['operation'] as String,
        entityRef: r['entity_ref'] as String,
        payload: Map<String, dynamic>.from(jsonDecode(r['payload'] as String) as Map),
        status: r['status'] as String,
        attempts: r['attempts'] as int,
        createdAt: DateTime.parse(r['created_at'] as String),
        failureKind: r['failure_kind'] as String?,
        lastError: r['last_error'] as String?,
        lastAttemptAt: r['last_attempt_at'] == null ? null : DateTime.parse(r['last_attempt_at'] as String),
      );
}

/// Durable, ordered queue of changes for the server. Items survive app
/// restarts and forced shutdowns (each is written in the same transaction as
/// the record it belongs to) and are sent first-in first-out. An item the
/// server refuses stays visible with the reason, and blocks the later items
/// of the same record (they depend on it) but not unrelated ones. Nothing is
/// deleted until the server has acknowledged it.
class Outbox {
  Outbox(this.db);

  final DatabaseExecutor db;

  static const _uuid = Uuid();

  static String _now() => DateTime.now().toUtc().toIso8601String();

  Future<int> enqueue(String operation, String entityRef, Map<String, dynamic> payload, {DatabaseExecutor? txn}) =>
      (txn ?? db).insert('outbox', {
        'client_txn_id': _uuid.v7(),
        'operation': operation,
        'entity_ref': entityRef,
        'payload': jsonEncode(payload),
        'status': 'pending',
        'created_at': _now(),
        'updated_at': _now(),
      });

  Future<OutboxItem?> nextPending() async => (await pendingBatch(1)).firstOrNull;

  /// The next items to send, in capture order.
  Future<List<OutboxItem>> pendingBatch(int limit) async =>
      (await db.query('outbox', where: "status = 'pending'", orderBy: 'id', limit: limit)).map(OutboxItem.fromRow).toList();

  /// Acknowledged by the server: the item has done its job.
  Future<void> complete(int id) => db.delete('outbox', where: 'id = ?', whereArgs: [id]);

  Future<void> recordAttempt(int id, String error) => db.rawUpdate(
      'UPDATE outbox SET attempts = attempts + 1, last_error = ?, last_attempt_at = ?, updated_at = ? WHERE id = ?', [error, _now(), _now(), id]);

  Future<void> fail(int id, String error, {String kind = 'rejected'}) => db.update(
      'outbox', {'status': 'failed', 'failure_kind': kind, 'last_error': error, 'last_attempt_at': _now(), 'updated_at': _now()},
      where: 'id = ?', whereArgs: [id]);

  /// Later operations on a record whose earlier operation was refused cannot succeed either.
  Future<int> failDependents(String entityRef, String reason) => db.update('outbox', {'status': 'failed', 'failure_kind': 'rejected', 'last_error': reason, 'updated_at': _now()},
      where: "entity_ref = ? AND status = 'pending'", whereArgs: [entityRef]);

  Future<int> removePendingFor(String entityRef, {DatabaseExecutor? txn}) =>
      (txn ?? db).delete('outbox', where: "entity_ref = ? AND status = 'pending'", whereArgs: [entityRef]);

  Future<bool> hasPendingFor(String entityRef, {String? operation}) async =>
      (await db.query('outbox',
              where: "entity_ref = ? AND status = 'pending'${operation == null ? '' : ' AND operation = ?'}",
              whereArgs: [entityRef, ?operation],
              limit: 1))
          .isNotEmpty;

  Future<int> count(String status, {String? operation}) async => Sqflite.firstIntValue(await db.rawQuery(
          'SELECT COUNT(*) FROM outbox WHERE status = ?${operation == null ? '' : ' AND operation = ?'}', [status, ?operation])) ??
      0;

  Future<List<OutboxItem>> all() async => (await db.query('outbox', orderBy: 'id')).map(OutboxItem.fromRow).toList();

  Future<List<OutboxItem>> failed() async =>
      (await db.query('outbox', where: "status = 'failed'", orderBy: 'id')).map(OutboxItem.fromRow).toList();

  /// Dismiss a refused item after the person has seen why (the server keeps its own record).
  Future<void> dismiss(int id) => db.delete('outbox', where: "id = ? AND status = 'failed'", whereArgs: [id]);
}
