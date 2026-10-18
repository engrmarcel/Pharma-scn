import 'dart:convert';

import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import '../core/api.dart';
import '../location/location_capture.dart';
import 'local_db.dart';
import 'models.dart';
import 'outbox.dart';

/// Device-side operations. Everything the representative does is written to
/// the local database first, together with an outbox item, in one
/// transaction, so it survives restarts and works without a connection.
/// Server-derived facts (verification, ERP status) are only ever set from
/// server responses.
class FieldRepository {
  FieldRepository({
    required this.local,
    required this.api,
    required this.location,
    required this.userId,
    Map<String, String> Function()? device,
    Uuid? uuid,
  })  : device = device ?? deviceContext,
        _uuid = uuid ?? const Uuid();

  final LocalDb local;
  final Api api;
  final LocationCapture location;
  final int userId;
  final Map<String, String> Function() device;
  final Uuid _uuid;

  Database get db => local.db;

  Outbox get outbox => Outbox(db);

  /// Time-ordered UUID used as the server-side idempotency key.
  String newReference() => _uuid.v7();

  static String _now() => DateTime.now().toUtc().toIso8601String();

  // ---- Customers --------------------------------------------------------------------

  Future<List<Map<String, dynamic>>> _allPages(String path, [Map<String, String> query = const {}]) async {
    final rows = <Map<String, dynamic>>[];
    var page = 1;
    var last = 1;
    do {
      final res = await api.get(path, query: {...query, 'page': '$page'});
      rows.addAll((res['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      last = (res['last_page'] as num?)?.toInt() ?? (res['meta'] is Map ? ((res['meta'] as Map)['last_page'] as num?)?.toInt() ?? 1 : 1);
      page++;
    } while (page <= last);
    return rows;
  }

  /// Replaces the cached directory. Customers that are no longer visible to
  /// this representative are removed from the device (spec §12.5).
  Future<int> refreshCustomers() async {
    final rows = await _allPages('field-force/customers');
    await db.transaction((txn) async {
      final ids = <String>[];
      for (final j in rows) {
        final c = Customer.fromApi(j);
        ids.add(c.id);
        await txn.insert('customers', c.toRow(j), conflictAlgorithm: ConflictAlgorithm.replace);
      }
      final keep = ids.isEmpty ? '' : 'WHERE id NOT IN (${List.filled(ids.length, '?').join(',')})';
      final keepItems = ids.isEmpty ? '' : 'WHERE customer_id NOT IN (${List.filled(ids.length, '?').join(',')})';
      await txn.rawDelete('DELETE FROM customers $keep', ids);
      await txn.rawDelete('DELETE FROM catalogue_items $keepItems', ids);
    });
    await local.setMeta('customers_synced_at', _now());
    return rows.length;
  }

  Future<List<Customer>> customers({String? query}) async {
    final q = query?.trim() ?? '';
    final rows = await db.query('customers',
        where: q.isEmpty ? null : '(name LIKE ? OR code LIKE ?)', whereArgs: q.isEmpty ? null : ['%$q%', '$q%'], orderBy: 'name');
    return rows.map(Customer.fromRow).toList();
  }

  Future<Customer?> customer(String id) async {
    final rows = await db.query('customers', where: 'id = ?', whereArgs: [id]);
    return rows.isEmpty ? null : Customer.fromRow(rows.first);
  }

  // ---- Catalogue ----------------------------------------------------------------------

  Future<void> refreshCatalogue(String customerId) async {
    final items = <Map<String, dynamic>>[];
    String? asOf;
    var page = 1;
    var last = 1;
    do {
      final res = await api.get('field-force/catalogue', query: {'customer_id': customerId, 'page': '$page'});
      items.addAll((res['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      final meta = Map<String, dynamic>.from(res['meta'] as Map);
      asOf = meta['stock_as_of'] as String?;
      last = (meta['last_page'] as num?)?.toInt() ?? 1;
      page++;
    } while (page <= last);

    await db.transaction((txn) async {
      await txn.delete('catalogue_items', where: 'customer_id = ?', whereArgs: [customerId]);
      for (final j in items) {
        await txn.insert('catalogue_items', {
          'customer_id': customerId,
          'product_id': j['product_id'],
          'display_name': j['display_name'],
          'generic_name': j['generic_name'],
          'sku': j['sku'],
          'currency_code': j['currency_code'],
          'package_levels': jsonEncode(j['package_levels']),
          'sellable_base_units': j['sellable_base_units']?.toString(),
          'stock_as_of': asOf ?? _now(),
        });
      }
    });
  }

  Future<List<CatalogueItem>> catalogue(String customerId, {String? query}) async {
    final q = query?.trim() ?? '';
    final rows = await db.query('catalogue_items',
        where: q.isEmpty ? 'customer_id = ?' : 'customer_id = ? AND (display_name LIKE ? OR generic_name LIKE ? OR sku = ?)',
        whereArgs: q.isEmpty ? [customerId] : [customerId, '%$q%', '%$q%', q],
        orderBy: 'display_name');
    return rows.map(CatalogueItem.fromRow).toList();
  }

  // ---- Visits ---------------------------------------------------------------------------

  Future<LocalVisit?> openVisit() async {
    final rows = await db.query('visits', where: "status = 'in_progress' AND sync_state != 'failed'", orderBy: 'created_at DESC', limit: 1);
    return rows.isEmpty ? null : LocalVisit.fromRow(rows.first);
  }

  Future<LocalVisit?> visit(String ref) async {
    final rows = await db.query('visits', where: 'client_reference = ?', whereArgs: [ref]);
    return rows.isEmpty ? null : LocalVisit.fromRow(rows.first);
  }

  Future<List<LocalVisit>> visits({String? customerId}) async =>
      (await db.query('visits', where: customerId == null ? null : 'customer_id = ?', whereArgs: customerId == null ? null : [customerId], orderBy: 'created_at DESC'))
          .map(LocalVisit.fromRow)
          .toList();

  Future<LocalVisit> checkIn(Customer customer, {required String visitType, String? objectives}) async {
    final open = await openVisit();
    if (open != null) {
      throw StateError('Check out of the visit to ${open.customerName ?? 'the current customer'} first.');
    }
    final evidence = await location.capture();
    final ref = newReference();
    final checkIn = evidence.toJson(device());

    await db.transaction((txn) async {
      await txn.insert('visits', {
        'client_reference': ref,
        'customer_id': customer.id,
        'customer_name': customer.name,
        'visit_type': visitType,
        'objectives': objectives,
        'status': 'in_progress',
        'check_in': jsonEncode(checkIn),
        'sync_state': 'pending',
        'created_at': _now(),
        'updated_at': _now(),
      });
      await Outbox(txn).enqueue('visit_check_in', ref, {
        'client_reference': ref,
        'customer_id': customer.id,
        'visit_type': visitType,
        if (objectives != null && objectives.isNotEmpty) 'objectives': objectives,
        'check_in': checkIn,
      });
    });
    return (await visit(ref))!;
  }

  /// Details and activities stay on the device until check-out sends them.
  Future<void> saveVisitDetails(String ref, Map<String, dynamic> details, List<Map<String, dynamic>> activities) =>
      db.update('visits', {'details': jsonEncode(details), 'activities': jsonEncode(activities), 'updated_at': _now()},
          where: "client_reference = ? AND status = 'in_progress'", whereArgs: [ref]);

  Future<List<Map<String, dynamic>>> visitActivities(String ref) async {
    final row = (await db.query('visits', columns: ['activities'], where: 'client_reference = ?', whereArgs: [ref])).firstOrNull;
    final raw = row?['activities'] as String?;
    return raw == null ? [] : (jsonDecode(raw) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  }

  Future<LocalVisit> checkOut(String ref, {required String outcome}) async {
    final current = await visit(ref);
    if (current == null || current.status != 'in_progress') {
      throw StateError('This visit is not in progress.');
    }
    final evidence = await location.capture();
    final checkOut = evidence.toJson(device());
    final activities = await visitActivities(ref);
    final details = Map<String, dynamic>.of(current.details)..removeWhere((k, v) => v == null || (v is String && v.isEmpty));

    await db.transaction((txn) async {
      await txn.update('visits', {'status': 'completed', 'check_out': jsonEncode(checkOut), 'sync_state': 'pending', 'updated_at': _now()},
          where: 'client_reference = ?', whereArgs: [ref]);
      await Outbox(txn).enqueue('visit_check_out', ref, {
        ...details,
        'check_out': checkOut,
        'outcome': outcome,
        if (activities.isNotEmpty) 'activities': activities,
      });
    });
    return (await visit(ref))!;
  }

  Future<void> cancelVisit(String ref, String reason) async {
    await db.transaction((txn) async {
      final neverSent = (await txn.query('outbox', where: "entity_ref = ? AND operation = 'visit_check_in' AND status = 'pending'", whereArgs: [ref])).isNotEmpty;
      if (neverSent) {
        // The server never saw it: drop it locally instead of sending a check-in just to cancel it.
        await Outbox(txn).removePendingFor(ref, txn: txn);
        await txn.update('visits', {'status': 'cancelled', 'sync_state': 'synced', 'last_error': null, 'updated_at': _now()}, where: 'client_reference = ?', whereArgs: [ref]);
      } else {
        await txn.update('visits', {'status': 'cancelled', 'sync_state': 'pending', 'updated_at': _now()}, where: 'client_reference = ?', whereArgs: [ref]);
        await Outbox(txn).enqueue('visit_cancel', ref, {'reason': reason});
      }
    });
  }

  Future<String?> visitServerId(String ref) async =>
      (await db.query('visits', columns: ['server_id'], where: 'client_reference = ?', whereArgs: [ref])).firstOrNull?['server_id'] as String?;

  Future<void> applyVisit(String ref, Map<String, dynamic> data) => db.update(
      'visits',
      {
        'server_id': data['id'],
        'number': data['number'],
        'status': data['status'],
        'check_in_verification': data['check_in_verification'],
        'check_out_verification': data['check_out_verification'],
        'last_error': null,
        'updated_at': _now(),
      },
      where: 'client_reference = ?',
      whereArgs: [ref]);

  // ---- Orders ---------------------------------------------------------------------------

  Future<LocalOrder?> order(String ref) async {
    final rows = await db.query('orders', where: 'client_reference = ?', whereArgs: [ref]);
    return rows.isEmpty ? null : LocalOrder.fromRow(rows.first);
  }

  Future<List<LocalOrder>> orders({String? customerId}) async =>
      (await db.query('orders', where: customerId == null ? null : 'customer_id = ?', whereArgs: customerId == null ? null : [customerId], orderBy: 'captured_at DESC'))
          .map(LocalOrder.fromRow)
          .toList();

  /// Saves (or updates) a draft that exists only on this device.
  Future<LocalOrder> saveOrderDraft({String? ref, required Customer customer, required List<OrderLine> lines, String? visitReference, String? notes}) async {
    final existing = ref == null ? null : await order(ref);
    if (existing != null && !existing.editable) {
      throw StateError('This order has already been sent and can no longer be edited here.');
    }
    final reference = ref ?? newReference();
    await db.insert(
        'orders',
        {
          'client_reference': reference,
          'server_id': existing?.serverId,
          'number': existing?.number,
          'customer_id': customer.id,
          'customer_name': customer.name,
          'visit_reference': visitReference ?? existing?.visitReference,
          'captured_at': (existing?.capturedAt ?? DateTime.now()).toUtc().toIso8601String(),
          'notes': notes,
          'lines': jsonEncode(lines.map((l) => l.toJson()).toList()),
          'local_state': 'draft',
          'server_status': existing?.serverStatus,
          'sales_order': existing?.salesOrder == null ? null : jsonEncode(existing!.salesOrder),
          'stock_reserved': 0,
          'rejection': existing?.rejection == null ? null : jsonEncode(existing!.rejection),
          'sync_state': 'synced',
          'created_at': _now(),
          'updated_at': _now(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
    return (await order(reference))!;
  }

  /// Queues the order for submission to the ERP.
  Future<void> queueOrder(String ref) async {
    final o = await order(ref);
    if (o == null || !o.editable) throw StateError('Only drafts and rejected orders can be submitted.');
    if (o.lines.isEmpty) throw StateError('Add at least one product.');

    await db.transaction((txn) async {
      await txn.update('orders', {'local_state': 'queued', 'sync_state': 'pending', 'last_error': null, 'updated_at': _now()}, where: 'client_reference = ?', whereArgs: [ref]);
      final lines = o.lines.map((l) => l.toApi()).toList();
      if (o.serverId == null) {
        await Outbox(txn).enqueue('order_capture', ref, {
          'client_reference': ref,
          'customer_id': o.customerId,
          if (o.visitReference != null) 'visit_reference': o.visitReference,
          'captured_at': o.capturedAt.toUtc().toIso8601String(),
          if (o.notes != null && o.notes!.isNotEmpty) 'notes': o.notes,
          'lines': lines,
          'submit': true,
        });
      } else {
        await Outbox(txn).enqueue('order_resubmit', ref, {'lines': lines, if (o.notes != null) 'notes': o.notes});
      }
    });
  }

  Future<void> deleteDraft(String ref) => db.delete('orders', where: "client_reference = ? AND local_state = 'draft' AND server_id IS NULL", whereArgs: [ref]);

  Future<String?> orderServerId(String ref) async =>
      (await db.query('orders', columns: ['server_id'], where: 'client_reference = ?', whereArgs: [ref])).firstOrNull?['server_id'] as String?;

  /// Records the server's view of an order. Only this method sets ERP status.
  Future<void> applyOrder(String ref, Map<String, dynamic> data) => db.update(
      'orders',
      {
        'server_id': data['id'],
        'number': data['number'],
        'local_state': 'sent',
        'server_status': data['status'],
        'sales_order': data['sales_order'] == null ? null : jsonEncode(data['sales_order']),
        'stock_reserved': data['stock_reserved'] == true ? 1 : 0,
        'rejection': data['rejection_reasons'] == null ? null : jsonEncode(data['rejection_reasons']),
        'last_error': null,
        'updated_at': _now(),
      },
      where: 'client_reference = ?',
      whereArgs: [ref]);

  /// Pulls the latest ERP status for orders that have nothing waiting in the outbox.
  Future<void> refreshOrders() async {
    final res = await api.get('field-force/orders', query: {'representative_id': '$userId'});
    for (final raw in res['data'] as List) {
      final j = Map<String, dynamic>.from(raw as Map);
      final ref = j['client_reference'] as String;
      if (await outbox.hasPendingFor(ref)) continue;
      final existing = await order(ref);
      if (existing == null) {
        final customerId = j['customer_id'] as String;
        await db.insert('orders', {
          'client_reference': ref,
          'customer_id': customerId,
          'customer_name': (await customer(customerId))?.name,
          'captured_at': j['captured_at'],
          'notes': j['notes'],
          'lines': jsonEncode((j['lines'] as List? ?? const []).map((l) => {
                'product_id': l['product_id'],
                'product_name': '',
                'package_level_code': l['package_level_code'] ?? '',
                'quantity': l['quantity'].toString(),
              }).toList()),
          'local_state': 'sent',
          'sync_state': 'synced',
          'created_at': _now(),
          'updated_at': _now(),
        });
      }
      await applyOrder(ref, j);
    }
  }

  // ---- Follow-ups -------------------------------------------------------------------------

  Future<void> refreshFollowUps() async {
    final rows = await _allPages('field-force/follow-ups', {'assigned_to': '$userId'});
    await db.transaction((txn) async {
      // Keep completions recorded on the device until the server confirms them.
      await txn.delete('follow_ups', where: 'local_status IS NULL');
      for (final j in rows) {
        await txn.insert(
            'follow_ups',
            {
              'id': j['id'],
              'customer_id': j['customer_id'],
              'title': j['title'],
              'notes': j['notes'],
              'due_on': j['due_on'],
              'status': j['status'],
              'cached_at': _now(),
            },
            conflictAlgorithm: ConflictAlgorithm.ignore);
      }
    });
  }

  Future<List<FollowUp>> followUps({bool openOnly = true}) async =>
      (await db.query('follow_ups', where: openOnly ? "status = 'open'" : null, orderBy: 'due_on')).map(FollowUp.fromRow).toList();

  Future<void> completeFollowUp(String id, String outcome) => db.transaction((txn) async {
        await txn.update('follow_ups', {'local_status': 'done', 'sync_state': 'pending'}, where: 'id = ?', whereArgs: [id]);
        await Outbox(txn).enqueue('follow_up_complete', id, {'outcome': outcome});
      });

  Future<void> applyFollowUp(String id, Map<String, dynamic> data) => db.update('follow_ups', {'status': data['status'], 'local_status': null, 'sync_state': 'synced', 'last_error': null},
      where: 'id = ?', whereArgs: [id]);

  // ---- Sync bookkeeping ------------------------------------------------------------------------

  static const _tables = {
    'visit_check_in': ('visits', 'client_reference'),
    'visit_check_out': ('visits', 'client_reference'),
    'visit_cancel': ('visits', 'client_reference'),
    'order_capture': ('orders', 'client_reference'),
    'order_resubmit': ('orders', 'client_reference'),
    'follow_up_complete': ('follow_ups', 'id'),
  };

  /// After an item is sent: the record is "synced" once nothing else is waiting for it.
  Future<void> settle(String operation, String ref) async {
    final (table, key) = _tables[operation]!;
    if (!await outbox.hasPendingFor(ref)) {
      await db.update(table, {'sync_state': 'synced'}, where: '$key = ?', whereArgs: [ref]);
    }
  }

  Future<void> markFailed(String operation, String ref, String error) async {
    final (table, key) = _tables[operation]!;
    await db.update(table, {'sync_state': 'failed', 'last_error': error}, where: '$key = ?', whereArgs: [ref]);
  }
}
