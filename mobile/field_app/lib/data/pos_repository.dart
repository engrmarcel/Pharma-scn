import 'dart:convert';

import 'package:sqflite_sqlcipher/sqflite.dart';
import 'package:uuid/uuid.dart';

import '../core/api.dart';
import '../core/money.dart';
import 'local_db.dart';
import 'outbox.dart';

class PosLevel {
  PosLevel({required this.code, required this.name, required this.baseUnitQuantity, this.unitPrice});

  final String code;
  final String name;
  final D4 baseUnitQuantity;
  final D4? unitPrice;

  static PosLevel fromJson(Map<String, dynamic> j) => PosLevel(
        code: j['code'] as String,
        name: j['name'] as String,
        baseUnitQuantity: D4.parse(j['base_unit_quantity'].toString()),
        unitPrice: j['unit_price'] == null ? null : D4.parse(j['unit_price'].toString()),
      );
}

class PosBatch {
  PosBatch({required this.batchId, required this.batchNumber, required this.free, this.expiryDate});

  final String batchId;
  final String batchNumber;
  final String? expiryDate;
  final D4 free;

  static PosBatch fromJson(Map<String, dynamic> j) => PosBatch(
      batchId: j['batch_id'] as String, batchNumber: j['batch_number'] as String, expiryDate: j['expiry_date'] as String?, free: D4.parse(j['free_base_units'].toString()));

  Map<String, dynamic> toJson() => {'batch_id': batchId, 'batch_number': batchNumber, 'expiry_date': expiryDate, 'free_base_units': free.toString()};
}

class PosItem {
  PosItem({
    required this.productId,
    required this.displayName,
    required this.levels,
    required this.batches,
    required this.barcodes,
    this.genericName,
    this.sku,
    this.classification,
    this.taxRate,
  });

  final String productId;
  final String displayName;
  final String? genericName;
  final String? sku;
  final List<String> barcodes;
  final String? classification;

  /// Null when no tax is configured: such products cannot be priced and are not sold offline.
  final D4? taxRate;
  final List<PosLevel> levels;
  final List<PosBatch> batches;

  bool get prescriptionControlled => classification == 'prescription_only' || classification == 'controlled';

  D4 get sellableBaseUnits => batches.fold(D4.zero, (sum, b) => sum + b.free);

  static PosItem fromRow(Map<String, Object?> r) => PosItem(
        productId: r['product_id'] as String,
        displayName: r['display_name'] as String,
        genericName: r['generic_name'] as String?,
        sku: r['sku'] as String?,
        barcodes: List<String>.from(jsonDecode(r['barcodes'] as String) as List),
        classification: r['classification'] as String?,
        taxRate: r['tax_rate'] == null ? null : D4.parse(r['tax_rate'] as String),
        levels: (jsonDecode(r['package_levels'] as String) as List).map((e) => PosLevel.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        batches: (jsonDecode(r['batches'] as String) as List).map((e) => PosBatch.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
      );
}

/// One line in the till's cart, priced exactly as the ERP prices it.
class CartLine {
  CartLine({
    required this.item,
    required this.level,
    required this.quantity,
    required this.unitPrice,
    D4? discountPercent,
    this.batch,
    this.prescription,
  }) : discountPercent = discountPercent ?? D4.zero;

  final PosItem item;
  final PosLevel level;
  D4 quantity;
  final D4 unitPrice;
  final D4 discountPercent;
  final PosBatch? batch;
  final Map<String, String>? prescription;

  D4 get baseQuantity => quantity * level.baseUnitQuantity;

  LineAmounts get amounts => LineAmounts.of(quantity: quantity, unitPrice: unitPrice, discountPercent: discountPercent, taxRate: item.taxRate ?? D4.zero);

  Map<String, dynamic> toJson() => {
        'product_id': item.productId,
        'product_name': item.displayName,
        'package_level_code': level.code,
        'level_name': level.name,
        'quantity': quantity.toString(),
        'unit_price': unitPrice.toString(),
        'discount_percent': discountPercent.toString(),
        'tax_rate': (item.taxRate ?? D4.zero).toString(),
        'line_total': amounts.total.toString(),
        if (batch != null) 'batch_id': batch!.batchId,
        if (batch != null) 'batch_number': batch!.batchNumber,
        'prescription': ?prescription,
      };
}

class CartTotals {
  CartTotals(this.subtotal, this.discount, this.tax, this.total);

  final D4 subtotal;
  final D4 discount;
  final D4 tax;
  final D4 total;

  factory CartTotals.of(List<CartLine> lines) {
    var s = D4.zero, d = D4.zero, t = D4.zero, total = D4.zero;
    for (final l in lines) {
      final a = l.amounts;
      s += a.subtotal;
      d += a.discount;
      t += a.tax;
      total += a.total;
    }
    return CartTotals(s, d, t, total);
  }
}

class PosPaymentInput {
  PosPaymentInput.cash({required this.amount, required D4 this.tendered})
      : method = 'cash',
        approvalCode = null;

  PosPaymentInput.terminal({required this.method, required this.amount, required String this.approvalCode}) : tendered = null;

  final String method;
  final D4 amount;
  final D4? tendered;
  final String? approvalCode;

  Map<String, dynamic> toJson() => {
        'method': method,
        'amount': amount.toString(),
        if (tendered != null) 'tendered': tendered.toString(),
        'approval_code': ?approvalCode,
      };
}

class LocalPosSale {
  LocalPosSale(this.row);

  final Map<String, Object?> row;

  String get clientReference => row['client_reference'] as String;
  String? get number => row['number'] as String?;
  String get shiftId => row['shift_id'] as String;
  List<Map<String, dynamic>> get lines => (jsonDecode(row['lines'] as String) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  List<Map<String, dynamic>> get payments => (jsonDecode(row['payments'] as String) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  D4 get subtotal => D4.parse(row['subtotal'] as String);
  D4 get discount => D4.parse(row['discount'] as String);
  D4 get tax => D4.parse(row['tax'] as String);
  D4 get total => D4.parse(row['total'] as String);
  D4 get changeDue => D4.parse(row['change_due'] as String);
  String? get serverStatus => row['server_status'] as String?;
  String get syncState => row['sync_state'] as String;
  String? get lastError => row['last_error'] as String?;
  DateTime get completedAt => DateTime.parse(row['completed_at'] as String);
  List<String> get paymentStates => row['payment_states'] == null ? const [] : List<String>.from(jsonDecode(row['payment_states'] as String) as List);
  List<dynamic> get warnings => row['warnings'] == null ? const [] : jsonDecode(row['warnings'] as String) as List;
}

/// The till's offline side (spec §13.1): sells from the cached catalogue,
/// writes each sale together with its outbox item in one transaction (so a
/// forced shutdown cannot lose a sale that was completed on screen), and
/// keeps its own cached stock figures down so it does not oversell its
/// snapshot. The server re-checks prices, stock and payments on sync.
class PosRepository {
  PosRepository({required this.local, required this.api});

  final LocalDb local;
  final Api api;
  static const _uuid = Uuid();

  Database get db => local.db;

  static String _now() => DateTime.now().toUtc().toIso8601String();

  Future<void> refreshCatalogue(String terminalId) async {
    final data = Map<String, dynamic>.from((await api.get('pos/terminals/$terminalId/offline-catalogue'))['data'] as Map);
    final asOf = data['as_of'] as String;
    await db.transaction((txn) async {
      await txn.delete('pos_catalogue');
      for (final raw in data['items'] as List) {
        final j = Map<String, dynamic>.from(raw as Map);
        await txn.insert('pos_catalogue', {
          'product_id': j['product_id'],
          'display_name': j['display_name'],
          'generic_name': j['generic_name'],
          'sku': j['sku'],
          'barcodes': jsonEncode(j['barcodes'] ?? const []),
          'classification': j['classification'],
          'tax_category_code': j['tax_category_code'],
          'tax_rate': j['tax_rate']?.toString(),
          'package_levels': jsonEncode(j['package_levels'] ?? const []),
          'batches': jsonEncode(j['batches'] ?? const []),
          'as_of': asOf,
        });
      }
    });
    await local.setMeta('pos_settings', jsonEncode(data['settings']));
    await local.setMeta('pos_currency', data['currency_code'] as String);
    await local.setMeta('pos_catalogue_as_of', asOf);
  }

  Future<DateTime?> catalogueAsOf() async {
    final v = await local.meta('pos_catalogue_as_of');
    return v == null ? null : DateTime.parse(v);
  }

  Future<Map<String, dynamic>> settings() async {
    final v = await local.meta('pos_settings');
    return v == null ? const {'max_discount_percent': '0', 'enforce_prescription_controls': true} : Map<String, dynamic>.from(jsonDecode(v) as Map);
  }

  Future<String> currency() async => await local.meta('pos_currency') ?? '';

  Future<List<PosItem>> search(String query) async {
    final q = query.trim();
    final rows = await db.query('pos_catalogue',
        where: q.isEmpty ? null : '(display_name LIKE ? OR generic_name LIKE ? OR sku = ? OR barcodes LIKE ?)',
        whereArgs: q.isEmpty ? null : ['%$q%', '%$q%', q, '%"$q"%'],
        orderBy: 'display_name',
        limit: 100);
    return rows.map(PosItem.fromRow).toList();
  }

  /// A scanner code: SKU, barcode or batch number. Returns the item and, for a batch, which batch.
  Future<(PosItem, PosBatch?)?> scan(String code) async {
    final c = code.trim();
    if (c.isEmpty) return null;
    final rows = await db.query('pos_catalogue', where: 'sku = ? OR barcodes LIKE ? OR batches LIKE ?', whereArgs: [c, '%"$c"%', '%"${c.toUpperCase()}"%'], limit: 5);
    for (final r in rows) {
      final item = PosItem.fromRow(r);
      final batch = item.batches.where((b) => b.batchNumber == c.toUpperCase()).firstOrNull;
      if (batch != null || item.sku == c || item.barcodes.contains(c)) return (item, batch);
    }
    return null;
  }

  /// Records a completed sale on the device. Cash is final at the till; a card or
  /// transfer on a standalone terminal is recorded as pending for a supervisor's check.
  Future<LocalPosSale> recordSale({required String shiftId, required List<CartLine> lines, required List<PosPaymentInput> payments}) async {
    if (lines.isEmpty) throw StateError('The cart is empty.');
    if (lines.any((l) => l.item.taxRate == null)) throw StateError('A product without a tax setting cannot be sold offline.');
    final totals = CartTotals.of(lines);
    final paid = payments.fold(D4.zero, (s, p) => s + p.amount);
    if (paid < totals.total) throw StateError('Payments (${paid.money()}) do not cover the total (${totals.total.money()}).');
    if (paid > totals.total) throw StateError('Payments exceed the total; record cash as tendered to give change.');
    var change = D4.zero;
    for (final p in payments.where((p) => p.method == 'cash')) {
      if (p.tendered! < p.amount) throw StateError('Cash tendered is less than the cash amount.');
      change += p.tendered! - p.amount;
    }

    final ref = _uuid.v7();
    final completedAt = _now();
    await db.transaction((txn) async {
      await txn.insert('pos_sales', {
        'client_reference': ref,
        'shift_id': shiftId,
        'lines': jsonEncode(lines.map((l) => l.toJson()).toList()),
        'payments': jsonEncode(payments.map((p) => p.toJson()).toList()),
        'subtotal': totals.subtotal.toString(),
        'discount': totals.discount.toString(),
        'tax': totals.tax.toString(),
        'total': totals.total.toString(),
        'change_due': change.toString(),
        'sync_state': 'pending',
        'completed_at': completedAt,
        'updated_at': completedAt,
      });
      await Outbox(txn).enqueue('pos_sale', ref, {
        'client_reference': ref,
        'shift_id': shiftId,
        'completed_at': completedAt,
        'device_total': totals.total.toString(),
        'lines': [
          for (final l in lines)
            {
              'product_id': l.item.productId,
              'package_level_code': l.level.code,
              'quantity': l.quantity.toString(),
              'unit_price': l.unitPrice.toString(),
              'discount_percent': l.discountPercent.toString(),
              if (l.batch != null) 'batch_id': l.batch!.batchId,
              'prescription': ?l.prescription,
            },
        ],
        'payments': payments.map((p) => p.toJson()).toList(),
      });
      for (final l in lines) {
        await _consumeCached(txn, l);
      }
    });
    return (await sale(ref))!;
  }

  /// Lower the cached free stock (scanned batch first, otherwise earliest expiry).
  Future<void> _consumeCached(DatabaseExecutor txn, CartLine line) async {
    final row = (await txn.query('pos_catalogue', where: 'product_id = ?', whereArgs: [line.item.productId])).firstOrNull;
    if (row == null) return;
    final batches = (jsonDecode(row['batches'] as String) as List).map((e) => PosBatch.fromJson(Map<String, dynamic>.from(e as Map))).toList();
    var remaining = line.baseQuantity;
    final ordered = [...batches.where((b) => b.batchId == line.batch?.batchId), ...batches.where((b) => b.batchId != line.batch?.batchId)];
    final updated = <String, D4>{};
    for (final b in ordered) {
      if (!(remaining > D4.zero)) break;
      final take = b.free < remaining ? b.free : remaining;
      updated[b.batchId] = b.free - take;
      remaining -= take;
    }
    final next = batches.map((b) => PosBatch(batchId: b.batchId, batchNumber: b.batchNumber, expiryDate: b.expiryDate, free: updated[b.batchId] ?? b.free).toJson()).toList();
    await txn.update('pos_catalogue', {'batches': jsonEncode(next)}, where: 'product_id = ?', whereArgs: [line.item.productId]);
  }

  Future<LocalPosSale?> sale(String ref) async =>
      (await db.query('pos_sales', where: 'client_reference = ?', whereArgs: [ref])).map(LocalPosSale.new).firstOrNull;

  Future<List<LocalPosSale>> sales({String? shiftId}) async =>
      (await db.query('pos_sales', where: shiftId == null ? null : 'shift_id = ?', whereArgs: shiftId == null ? null : [shiftId], orderBy: 'completed_at DESC'))
          .map(LocalPosSale.new)
          .toList();

  /// The server's answer for a synced sale: its number, status, payment states and any replay warnings.
  Future<void> applySale(String ref, Map<String, dynamic> result, List warnings) => db.update(
      'pos_sales',
      {
        'server_id': result['id'],
        'number': result['number'],
        'server_status': result['status'],
        'payment_states': jsonEncode([for (final p in (result['payments'] as List? ?? const [])) (p as Map)['state']]),
        'warnings': warnings.isEmpty ? null : jsonEncode(warnings),
        'last_error': null,
        'updated_at': _now(),
      },
      where: 'client_reference = ?',
      whereArgs: [ref]);

  Future<int> unsentSales() => Outbox(db).count('pending', operation: 'pos_sale');

  /// Close the shift online once every sale has reached the server (the count must include them all).
  Future<Map<String, dynamic>> closeShift(String shiftId, D4 countedCash) async {
    if (await unsentSales() > 0) throw StateError('Sync all sales before closing the shift.');
    if (await Outbox(db).count('failed', operation: 'pos_sale') > 0) throw StateError('Some sales need attention. Resolve them with a supervisor before closing.');
    return Map<String, dynamic>.from((await api.post('pos/shifts/$shiftId/close', {'counted_cash': countedCash.toString()}))['data'] as Map);
  }

  Future<Map<String, dynamic>> openShift(String terminalId, D4 openingFloat) async =>
      Map<String, dynamic>.from((await api.post('pos/shifts', {'pos_terminal_id': terminalId, 'opening_float': openingFloat.toString()}))['data'] as Map);
}
