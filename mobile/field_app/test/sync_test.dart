import 'package:flutter_test/flutter_test.dart';
import 'package:pharma_field/data/outbox.dart';
import 'package:pharma_field/data/status_text.dart';
import 'package:pharma_field/data/sync_service.dart';

import 'support/fakes.dart';

void main() {
  test('work captured offline is kept, then sent as one ordered batch that references records by client reference', () async {
    final (repo, api, customer) = await fieldSetup();
    api.online = false;

    final visit = await repo.checkIn(customer, visitType: 'order_taking', objectives: 'Restock');
    await repo.saveVisitDetails(visit.clientReference, {'discussion_notes': 'Wants the 20-pack'}, [
      {'activity_type': 'detailing'},
    ]);
    final draft = await repo.saveOrderDraft(customer: customer, lines: [line()], visitReference: visit.clientReference);
    expect(describeOrder(draft).label, 'Draft on this device');
    await repo.queueOrder(draft.clientReference);
    await repo.checkOut(visit.clientReference, outcome: 'successful');

    final offline = await SyncService(repo).run();
    expect(offline.offline, isTrue);
    expect((await repo.outbox.all()).map((i) => i.state).toSet(), {SyncState.retryRequired}, reason: 'kept, with the failed attempt recorded');
    expect(await repo.outbox.count('pending'), 3);

    api.online = true;
    api.onEnvelope = (type, p) => switch (type) {
          'field.visit.check_in' => {'id': 'V1', 'number': 'VIS-000001', 'status': 'in_progress', 'check_in_verification': 'verified'},
          'field.order.capture' => {'id': 'O1', 'number': 'FO-000001', 'status': 'accepted', 'sales_order': {'number': 'SO-000001', 'status': 'confirmed'}, 'stock_reserved': true},
          'field.visit.check_out' => {'id': 'V1', 'number': 'VIS-000001', 'status': 'completed', 'check_in_verification': 'verified', 'check_out_verification': 'verified'},
          _ => throw StateError(type),
        };

    final online = await SyncService(repo).run();
    expect(online.sent, 3);
    expect(api.calls.where((c) => c == 'POST sync/batches').length, 1, reason: 'one batch for the whole queue');
    expect(api.processedTypes, ['field.visit.check_in', 'field.order.capture', 'field.visit.check_out'], reason: 'capture order is kept');

    final order = api.processed[1]['payload'] as Map;
    expect(order['field_visit_client_reference'], visit.clientReference);
    expect(order.containsKey('visit_reference'), isFalse);
    expect(order['captured_offline'], isFalse, reason: 'sent within minutes of capture');
    expect((api.processed[2]['payload'] as Map)['visit_client_reference'], visit.clientReference);
    expect((api.processed[2]['payload'] as Map)['discussion_notes'], 'Wants the 20-pack');

    final synced = (await repo.visit(visit.clientReference))!;
    expect([synced.syncState, synced.status, synced.checkInVerification], ['synced', 'completed', 'verified']);
    expect(describeOrder((await repo.order(draft.clientReference))!).label, 'Accepted by ERP · SO-000001');
    expect(await repo.outbox.count('pending'), 0);
  });

  test('a lost response is resent with the same transaction ids and nothing is applied twice', () async {
    final (repo, api, customer) = await fieldSetup();
    api.onEnvelope = (type, p) => {'id': 'V1', 'number': 'VIS-1', 'status': 'in_progress', 'check_in_verification': 'verified'};
    final visit = await repo.checkIn(customer, visitType: 'routine');
    final txn = (await repo.outbox.nextPending())!.clientTxnId;

    api.loseNextResponse = true;
    final first = await SyncService(repo).run();
    expect(first.offline, isTrue);
    expect(api.processed, hasLength(1), reason: 'the server did process it');
    expect(await repo.outbox.count('pending'), 1, reason: 'the device does not know yet');

    final second = await SyncService(repo).run();
    expect(second.sent, 1);
    expect(api.processed, hasLength(1), reason: 'the replay was answered from the inbox');
    expect((api.bodies.last!['envelopes'] as List).single['client_txn_id'], txn);
    expect((await repo.visit(visit.clientReference))!.number, 'VIS-1');
  });

  test('conflicts and rejections are kept with the reason; dependent steps fail with them; unrelated work goes through', () async {
    final (repo, api, customer) = await fieldSetup();
    final visit = await repo.checkIn(customer, visitType: 'routine');
    await repo.checkOut(visit.clientReference, outcome: 'successful');
    final order = await repo.saveOrderDraft(customer: customer, lines: [line()]);
    await repo.queueOrder(order.clientReference);

    api.onEnvelope = (type, p) => switch (type) {
          'field.visit.check_in' => throw Refusal('conflict', 'visit_in_progress', 'Check out of visit VIS-9 first.'),
          'field.visit.check_out' => throw Refusal('rejected', 'visit_not_found', 'The visit this step belongs to was not found on the server.'),
          _ => {'id': 'O1', 'number': 'FO-1', 'status': 'accepted', 'sales_order': {'number': 'SO-1', 'status': 'pending_approval'}, 'stock_reserved': false},
        };
    final result = await SyncService(repo).run();
    expect(result.refused, 2);
    expect(result.sent, 1);

    final failed = await repo.outbox.failed();
    expect(failed.map((i) => i.state), [SyncState.conflict, SyncState.rejected]);
    expect(failed.first.lastError, 'Check out of visit VIS-9 first.');
    expect((await repo.visit(visit.clientReference))!.syncState, 'failed');
    expect(await repo.openVisit(), isNull, reason: 'a refused visit does not block the next check-in');
    expect(describeOrder((await repo.order(order.clientReference))!).tone, StatusTone.warning, reason: 'accepted but awaiting approval: stock not reserved');
  });

  test('retry_required keeps the item pending and stops the run; backoff grows to at most 15 minutes', () async {
    final (repo, api, customer) = await fieldSetup();
    await repo.checkIn(customer, visitType: 'routine');
    api.onEnvelope = (type, p) => {'_retry': true};

    final result = await SyncService(repo).run();
    expect(result.retryLater, 1);
    expect((await repo.outbox.nextPending())!.state, SyncState.retryRequired);

    expect(SyncService.backoff(1).inSeconds, inInclusiveRange(2, 3));
    expect(SyncService.backoff(4).inSeconds, inInclusiveRange(16, 20));
    expect(SyncService.backoff(30).inMinutes, inInclusiveRange(15, 19));
  });

  test('an ERP rejection is a business answer, shown with its reason, and the order can be corrected and resubmitted', () async {
    final (repo, api, customer) = await fieldSetup();
    final draft = await repo.saveOrderDraft(customer: customer, lines: [line('500')]);
    await repo.queueOrder(draft.clientReference);
    api.onEnvelope = (type, p) => type == 'field.order.capture'
        ? {
            'id': 'O9',
            'number': 'FO-9',
            'status': 'rejected',
            'stock_reserved': false,
            'rejection_reasons': [
              {'code': 'insufficient_stock', 'message': 'Not enough sellable stock.'},
            ],
          }
        : {'id': 'O9', 'number': 'FO-9', 'status': 'accepted', 'sales_order': {'number': 'SO-2', 'status': 'confirmed'}, 'stock_reserved': true};

    await SyncService(repo).run();
    final rejected = (await repo.order(draft.clientReference))!;
    expect(describeOrder(rejected).label, 'Rejected by ERP');
    expect(describeOrder(rejected).detail, 'Not enough sellable stock.');

    await repo.saveOrderDraft(ref: rejected.clientReference, customer: customer, lines: [line('1')]);
    await repo.queueOrder(rejected.clientReference);
    await SyncService(repo).run();
    final resubmit = api.processed.last;
    expect(resubmit['type'], 'field.order.resubmit');
    expect((resubmit['payload'] as Map)['order_client_reference'], draft.clientReference);
    expect(describeOrder((await repo.order(draft.clientReference))!).label, 'Accepted by ERP · SO-2');
  });

  test('without a location fix the check-in carries the status and no coordinates', () async {
    final (repo, api, customer) = await fieldSetup(location: FakeLocation(denied));
    await repo.checkIn(customer, visitType: 'routine');
    api.onEnvelope = (type, p) => {'id': 'V2', 'number': 'VIS-2', 'status': 'in_progress', 'check_in_verification': 'no_location'};
    await SyncService(repo).run();

    final sent = (api.processed.single['payload'] as Map)['check_in'] as Map;
    expect(sent['location_status'], 'denied');
    expect(sent.containsKey('latitude'), isFalse);
  });

  test('cancelling an unsent visit never contacts the server; customers no longer assigned are removed', () async {
    final (repo, api, customer) = await fieldSetup();
    final visit = await repo.checkIn(customer, visitType: 'routine');
    expect(() => repo.checkIn(customer, visitType: 'routine'), throwsStateError);
    await repo.cancelVisit(visit.clientReference, 'Wrong customer');
    expect(await repo.outbox.count('pending'), 0);

    api.handler = (method, path, body) => {
          'data': [
            {'id': 'C2', 'name': 'Ikeja Pharmacy', 'code': 'B', 'latitude': '6.6018000', 'longitude': '3.3515000', 'currency_code': 'NGN'},
          ],
          'last_page': 1,
        };
    await repo.refreshCustomers();
    expect((await repo.customers()).map((c) => c.id), ['C2']);
  });
}
