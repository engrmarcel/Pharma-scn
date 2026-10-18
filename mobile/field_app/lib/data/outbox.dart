import 'dart:convert';

import 'package:sqflite/sqflite.dart';

class OutboxItem {
  OutboxItem({required this.id, required this.operation, required this.entityRef, required this.payload, required this.status, required this.attempts, this.lastError});

  final int id;

  /// visit_check_in | visit_details | visit_check_out | visit_cancel | order_capture | order_resubmit | follow_up_complete
  final String operation;

  /// client_reference (or server id for follow-ups) of the record the operation belongs to.
  final String entityRef;
  final Map<String, dynamic> payload;
  final String status;
  final int attempts;
  final String? lastError;

  static OutboxItem fromRow(Map<String, Object?> r) => OutboxItem(
        id: r['id'] as int,
        operation: r['operation'] as String,
        entityRef: r['entity_ref'] as String,
        payload: Map<String, dynamic>.from(jsonDecode(r['payload'] as String) as Map),
        status: r['status'] as String,
        attempts: r['attempts'] as int,
        lastError: r['last_error'] as String?,
      );
}

/// Durable, ordered queue of changes for the server. Items survive app
/// restarts and are sent first-in first-out; a failed item blocks later
/// items for the same record (they depend on it) but not unrelated ones.
class Outbox {
  Outbox(this.db);

  final DatabaseExecutor db;

  static String _now() => DateTime.now().toUtc().toIso8601String();

  Future<int> enqueue(String operation, String entityRef, Map<String, dynamic> payload, {DatabaseExecutor? txn}) =>
      (txn ?? db).insert('outbox', {
        'operation': operation,
        'entity_ref': entityRef,
        'payload': jsonEncode(payload),
        'status': 'pending',
        'created_at': _now(),
        'updated_at': _now(),
      });

  Future<OutboxItem?> nextPending({Set<int> skip = const {}}) async {
    final rows = await db.query('outbox',
        where: "status = 'pending'${skip.isEmpty ? '' : ' AND id NOT IN (${skip.join(',')})'}", orderBy: 'id', limit: 1);
    return rows.isEmpty ? null : OutboxItem.fromRow(rows.first);
  }

  Future<void> complete(int id) => db.delete('outbox', where: 'id = ?', whereArgs: [id]);

  Future<void> recordAttempt(int id, String error) =>
      db.rawUpdate('UPDATE outbox SET attempts = attempts + 1, last_error = ?, updated_at = ? WHERE id = ?', [error, _now(), id]);

  Future<void> fail(int id, String error) =>
      db.update('outbox', {'status': 'failed', 'last_error': error, 'updated_at': _now()}, where: 'id = ?', whereArgs: [id]);

  /// Later operations on a record whose earlier operation was refused cannot succeed either.
  Future<int> failDependents(String entityRef, String reason) => db.update('outbox', {'status': 'failed', 'last_error': reason, 'updated_at': _now()},
      where: "entity_ref = ? AND status = 'pending'", whereArgs: [entityRef]);

  Future<int> removePendingFor(String entityRef, {DatabaseExecutor? txn}) =>
      (txn ?? db).delete('outbox', where: "entity_ref = ? AND status = 'pending'", whereArgs: [entityRef]);

  Future<bool> hasPendingFor(String entityRef, {String? operation}) async =>
      (await db.query('outbox',
              where: "entity_ref = ? AND status = 'pending'${operation == null ? '' : ' AND operation = ?'}",
              whereArgs: [entityRef, ?operation],
              limit: 1))
          .isNotEmpty;

  Future<int> count(String status) async =>
      Sqflite.firstIntValue(await db.rawQuery('SELECT COUNT(*) FROM outbox WHERE status = ?', [status])) ?? 0;

  Future<List<OutboxItem>> failed() async =>
      (await db.query('outbox', where: "status = 'failed'", orderBy: 'id')).map(OutboxItem.fromRow).toList();

  /// Dismiss a failed item after the representative has seen why it was refused.
  Future<void> dismiss(int id) => db.delete('outbox', where: "id = ? AND status = 'failed'", whereArgs: [id]);
}
