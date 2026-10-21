// End-to-end contract check against a running Laravel API seeded with the
// synthetic demo data (php artisan migrate:fresh --seed). Skipped unless
// FIELD_E2E_BASE_URL is set, e.g.
//   FIELD_E2E_BASE_URL=http://127.0.0.1:8124/api/v1 flutter test test/e2e
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:pharma_field/core/db_keys.dart';
import 'package:pharma_field/core/money.dart';
import 'package:pharma_field/core/session.dart';
import 'package:pharma_field/data/models.dart';
import 'package:pharma_field/data/pos_repository.dart';
import 'package:pharma_field/data/status_text.dart';
import 'package:pharma_field/location/location_capture.dart';
import 'package:pharma_field/state/app_state.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import '../support/fakes.dart';

AppState _app({LocationCapture? location}) {
  sqfliteFfiInit();
  return AppState(
    sessionStore: MemorySessionStore(),
    keyStore: MemoryDbKeyStore(),
    location: location ?? FakeLocation(fix),
    databaseFactoryOverride: databaseFactoryFfi,
    databaseDirectory: Directory.systemTemp.createTempSync('field_e2e').path,
    tick: const Duration(hours: 1),
  );
}

void main() {
  final baseUrl = Platform.environment['FIELD_E2E_BASE_URL'];
  final skip = baseUrl == null ? 'Set FIELD_E2E_BASE_URL to run against a live API' : false;

  test('representative: registers the device, works, and syncs through the batch endpoint', () async {
    // DEMO-CUS-1 is recorded at 6.4474, 3.3903 in the demo data; this fix is about 30 m away.
    final app = _app(location: FakeLocation(() => LocationEvidence(occurredAt: DateTime.now(), status: 'granted', latitude: 6.4477, longitude: 3.3903, accuracyM: 10, mock: false)));

    await app.signIn(baseUrl: baseUrl!, email: 'dist.rep1@demo.test', password: 'Password123!');
    expect(app.error, isNull);
    expect(app.workspaceReady, isTrue, reason: 'single organization and role selected automatically');
    expect(app.session!.deviceId, isNotNull, reason: 'the device is registered and holds a device-bound token');
    final repo = app.repository!;

    final customers = await repo.customers();
    expect(customers.map((c) => c.code), ['DEMO-CUS-1'], reason: 'only the assigned customer reaches the device');
    final customer = customers.single;

    await repo.refreshCatalogue(customer.id);
    final item = (await repo.catalogue(customer.id)).firstWhere((i) => i.levels.any((l) => l.indicativePrice != null) && double.parse(i.sellableBaseUnits ?? '0') > 0);
    final level = item.levels.firstWhere((l) => l.indicativePrice != null && double.parse(l.baseUnitQuantity) <= double.parse(item.sellableBaseUnits!));

    final visit = await repo.checkIn(customer, visitType: 'order_taking', objectives: 'E2E contract check');
    await repo.saveVisitDetails(visit.clientReference, {'discussion_notes': 'E2E', 'order_opportunity': true}, [
      {'activity_type': 'detailing', 'recorded_at': DateTime.now().toUtc().toIso8601String()},
    ]);
    final draft = await repo.saveOrderDraft(
        customer: customer,
        visitReference: visit.clientReference,
        lines: [OrderLine(productId: item.productId, productName: item.displayName, packageLevelCode: level.code, quantity: '1', indicativeUnitPrice: level.indicativePrice)]);
    await repo.queueOrder(draft.clientReference);
    await repo.checkOut(visit.clientReference, outcome: 'successful');

    await app.syncNow();
    expect(app.pending, 0, reason: app.syncMessage);
    expect(app.needsAttention, 0, reason: app.syncMessage);
    expect(app.lastSyncAt, isNotNull);

    final synced = (await repo.visit(visit.clientReference))!;
    expect(synced.number, startsWith('VIS-'));
    expect([synced.checkInVerification, synced.checkOutVerification], ['verified', 'verified']);
    expect(describeOrder((await repo.order(draft.clientReference))!).label, startsWith('Accepted by ERP · SO-'));

    await app.signOut();
  }, skip: skip, timeout: const Timeout(Duration(minutes: 3)));

  test('cashier: registers a till, opens a shift, sells from the offline catalogue, syncs and closes', () async {
    final app = _app();
    await app.signIn(baseUrl: baseUrl!, email: 'ph1.cashier@demo.test', password: 'Password123!');
    expect(app.error, isNull);
    expect(app.isPos, isTrue);
    expect(app.workspaceReady, isTrue, reason: 'the pharmacy has one terminal');

    if (app.posShift == null) await app.openShift('5000');
    expect(app.error, isNull);
    final shiftId = app.posShift!['id'] as String;
    final pos = app.pos!;

    // Sell something that has a price, tax, stock and no prescription control.
    final item = (await pos.search('')).firstWhere((i) => i.taxRate != null && !i.prescriptionControlled && i.sellableBaseUnits > D4.parse('5') && i.levels.any((l) => l.unitPrice != null));
    final level = item.levels.where((l) => l.unitPrice != null).reduce((a, b) => a.baseUnitQuantity < b.baseUnitQuantity ? a : b);
    final lines = [CartLine(item: item, level: level, quantity: D4.parse('1'), unitPrice: level.unitPrice!)];
    final total = CartTotals.of(lines).total;
    final sale = await pos.recordSale(shiftId: shiftId, lines: lines, payments: [PosPaymentInput.cash(amount: total, tendered: total)]);
    expect(sale.number, isNull, reason: 'recorded on the device first');

    await app.syncNow();
    expect(app.pending, 0, reason: app.syncMessage);
    final synced = (await pos.sale(sale.clientReference))!;
    expect(synced.number, startsWith('POS-'));
    expect(synced.serverStatus, 'completed');
    expect(synced.paymentStates, ['offline_cash_recorded'], reason: 'cash taken offline is not shown as reconciled');
    expect(synced.warnings, isEmpty, reason: 'device and server agree on the price and total');

    final closed = await app.closeShift((D4.parse('5000') + total).toString());
    expect(app.error, isNull);
    expect(closed!['status'], 'closed');

    await app.signOut();
  }, skip: skip, timeout: const Timeout(Duration(minutes: 3)));
}
