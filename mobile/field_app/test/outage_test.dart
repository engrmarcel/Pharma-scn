// Device half of the six-hour outage scenario (spec Phase 3 acceptance;
// docs/OFFLINE_OUTAGE_PLAYBOOK.md). Workload profile:
//   - one till: 60 sales over 6 hours (one every 6 minutes), every 10th paid by card terminal;
//   - one representative: 6 visits with check-in, notes and check-out, 3 of them with an order;
//   - the app is restarted twice during the outage, once cleanly and once as a forced
//     shutdown (the database is reopened without being closed);
//   - sync attempts keep failing while offline (with backoff);
//   - when the connection returns the queue is sent in batches of 50 and one response is lost.
// Expected: nothing captured is lost, the server receives every item exactly once and in
// capture order, and the device ends with nothing pending.
import 'package:flutter_test/flutter_test.dart';
import 'package:pharma_field/core/money.dart';
import 'package:pharma_field/data/field_repository.dart';
import 'package:pharma_field/data/local_db.dart';
import 'package:pharma_field/data/pos_repository.dart';
import 'package:pharma_field/data/sync_service.dart';

import 'support/fakes.dart';

void main() {
  test('six hours offline with two restarts: nothing is lost and everything syncs exactly once', () async {
    final tillPath = tempDbPath('till.db');
    final repPath = tempDbPath('rep.db');
    final api = FakeApi()..online = false;

    var tillDb = await openTestDb(tillPath);
    var pos = await _offlineCatalogue(tillDb, api);
    var repDb = await openTestDb(repPath);
    var (rep, _, customer) = await fieldSetup(db: repDb, api: api);

    var attempts = 0;
    Future<void> captureHour(int hour) async {
      for (var i = 0; i < 10; i++) {
        final n = hour * 10 + i;
        final item = (await pos.search('AMX')).single;
        final capsule = item.levels.firstWhere((l) => l.code == 'capsule');
        final lines = [CartLine(item: item, level: capsule, quantity: D4.parse('2'), unitPrice: capsule.unitPrice!)];
        final total = CartTotals.of(lines).total;
        await pos.recordSale(
          shiftId: 'S1',
          lines: lines,
          payments: [n % 10 == 9 ? PosPaymentInput.terminal(method: 'card', amount: total, approvalCode: 'A$n') : PosPaymentInput.cash(amount: total, tendered: D4.parse('500'))],
        );
      }
      final visit = await rep.checkIn(customer, visitType: 'routine');
      await rep.saveVisitDetails(visit.clientReference, {'discussion_notes': 'Hour $hour'}, const []);
      if (hour.isEven) {
        final order = await rep.saveOrderDraft(customer: customer, lines: [line('1')], visitReference: visit.clientReference);
        await rep.queueOrder(order.clientReference);
      }
      await rep.checkOut(visit.clientReference, outcome: 'successful');

      // The apps keep trying while offline; every attempt fails without losing anything.
      final r1 = await SyncService(rep).run();
      final r2 = await SyncService(_repo(tillDb, api), pos: pos).run();
      expect(r1.offline && r2.offline, isTrue);
      attempts++;
    }

    for (var hour = 0; hour < 6; hour++) {
      await captureHour(hour);
      if (hour == 1) {
        // Clean restart.
        await tillDb.close();
        await repDb.close();
        tillDb = await openTestDb(tillPath);
        repDb = await openTestDb(repPath);
        pos = PosRepository(local: tillDb, api: api);
        (rep, _, customer) = await fieldSetup(db: repDb, api: api);
      }
      if (hour == 3) {
        // Forced shutdown: the old handles are abandoned, not closed.
        tillDb = await openTestDb(tillPath);
        repDb = await openTestDb(repPath);
        pos = PosRepository(local: tillDb, api: api);
        (rep, _, customer) = await fieldSetup(db: repDb, api: api);
      }
    }

    final tillRepo = _repo(tillDb, api);
    expect(await tillRepo.outbox.count('pending'), 60, reason: 'every sale survived both restarts');
    expect(await rep.outbox.count('pending'), 6 * 2 + 3);
    expect(attempts, 6);
    expect(await pos.sales(), hasLength(60));

    // Connection restored.
    api.online = true;
    var saleNo = 0;
    api.onEnvelope = (type, p) => switch (type) {
          'pos.sale' => {
              'id': 'S${++saleNo}',
              'number': 'POS-${saleNo.toString().padLeft(6, '0')}',
              'status': (p['payments'] as List).single['method'] == 'cash' ? 'completed' : 'awaiting_payment',
              'payments': [
                {'state': (p['payments'] as List).single['method'] == 'cash' ? 'offline_cash_recorded' : 'payment_pending'},
              ],
            },
          'field.visit.check_in' => {'id': 'V-${p['client_reference']}', 'status': 'in_progress', 'check_in_verification': 'verified'},
          'field.visit.check_out' => {'id': 'V-${p['visit_client_reference']}', 'status': 'completed', 'check_in_verification': 'verified', 'check_out_verification': 'verified'},
          'field.order.capture' => {'id': 'O-${p['client_reference']}', 'status': 'accepted', 'sales_order': {'number': 'SO', 'status': 'confirmed'}, 'stock_reserved': true},
          _ => throw StateError(type),
        };

    api.loseNextResponse = true; // the first batch is processed but its response never arrives
    final lost = await SyncService(tillRepo, pos: pos).run();
    expect(lost.offline, isTrue);
    final till = await SyncService(tillRepo, pos: pos).run();
    final field = await SyncService(rep).run();

    expect(till.sent + field.sent, 60 + 15);
    expect(api.processed, hasLength(75), reason: 'exactly once, although the first 50 sales were sent twice');
    expect(api.processedTypes.where((t) => t == 'pos.sale'), hasLength(60));
    expect(api.calls.where((c) => c == 'POST sync/batches').length, greaterThanOrEqualTo(4), reason: 'batches of at most 50');

    // Capture order is preserved per device.
    final saleTimes = api.processed.where((e) => e['type'] == 'pos.sale').map((e) => (e['payload'] as Map)['completed_at'] as String).toList();
    expect(saleTimes, [...saleTimes]..sort());
    final repTypes = api.processedTypes.where((t) => t.startsWith('field.')).toList();
    expect(repTypes.first, 'field.visit.check_in');

    expect(await tillRepo.outbox.count('pending') + await rep.outbox.count('pending'), 0);
    expect(await tillRepo.outbox.count('failed') + await rep.outbox.count('failed'), 0);
    final sales = await pos.sales();
    expect(sales.where((s) => s.number != null), hasLength(60));
    expect(sales.where((s) => s.serverStatus == 'awaiting_payment'), hasLength(6), reason: 'card payments stay pending until confirmed');
    expect((await rep.visits()).every((v) => v.syncState == 'synced' && v.checkInVerification == 'verified'), isTrue);
  });
}

FieldRepository _repo(LocalDb db, FakeApi api) => FieldRepository(local: db, api: api, location: FakeLocation(fix), userId: 7);

/// The catalogue is downloaded before the outage begins.
Future<PosRepository> _offlineCatalogue(LocalDb db, FakeApi api) async {
  api.online = true;
  final pos = await posSetup(db, api);
  api.online = false;
  return pos;
}
