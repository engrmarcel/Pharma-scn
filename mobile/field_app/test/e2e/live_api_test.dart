// End-to-end contract check against a running Laravel API seeded with the
// synthetic demo data (php artisan migrate:fresh --seed). Skipped unless
// FIELD_E2E_BASE_URL is set, e.g.
//   FIELD_E2E_BASE_URL=http://127.0.0.1:8124/api/v1 flutter test test/e2e
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:pharma_field/core/session.dart';
import 'package:pharma_field/data/models.dart';
import 'package:pharma_field/data/status_text.dart';
import 'package:pharma_field/location/location_capture.dart';
import 'package:pharma_field/state/app_state.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import '../support/fakes.dart';

void main() {
  final baseUrl = Platform.environment['FIELD_E2E_BASE_URL'];

  test('representative signs in, visits an assigned customer and gets an order accepted by the ERP', () async {
    sqfliteFfiInit();
    // DEMO-CUS-1 is recorded at 6.4474, 3.3903 in the demo data; this fix is about 30 m away.
    final app = AppState(
      sessionStore: MemorySessionStore(),
      location: FakeLocation(() => LocationEvidence(occurredAt: DateTime.now(), status: 'granted', latitude: 6.4477, longitude: 3.3903, accuracyM: 10, mock: false)),
      databaseFactoryOverride: databaseFactoryFfi,
      databaseDirectory: Directory.systemTemp.createTempSync('field_e2e').path,
    );

    await app.signIn(baseUrl: baseUrl!, email: 'dist.rep1@demo.test', password: 'Password123!');
    expect(app.error, isNull);
    expect(app.workspaceReady, isTrue, reason: 'single organization selected automatically');
    expect(app.session!.can('field_force.visits.capture'), isTrue);
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
    await repo.checkOut(visit.clientReference, outcome: 'successful');
    await repo.queueOrder(draft.clientReference);

    await app.syncNow();
    expect(app.pending, 0, reason: app.syncMessage);
    expect(app.needsAttention, 0, reason: app.syncMessage);

    final synced = (await repo.visit(visit.clientReference))!;
    expect(synced.number, startsWith('VIS-'));
    expect(synced.checkInVerification, 'verified');
    expect(synced.checkOutVerification, 'verified');

    final order = (await repo.order(draft.clientReference))!;
    expect(describeOrder(order).label, startsWith('Accepted by ERP · SO-'), reason: '${order.serverStatus} ${order.rejection}');

    await app.signOut();
  }, skip: baseUrl == null ? 'Set FIELD_E2E_BASE_URL to run against a live API' : false, timeout: const Timeout(Duration(minutes: 2)));
}
