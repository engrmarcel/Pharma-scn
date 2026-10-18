import 'package:flutter_test/flutter_test.dart';
import 'package:pharma_field/core/api.dart';
import 'package:pharma_field/data/status_text.dart';
import 'package:pharma_field/data/sync_service.dart';

import 'support/fakes.dart';

void main() {
  test('work captured offline is kept, then sent in order once online, linking the order to the synced visit', () async {
    final (repo, api, customer) = await fieldSetup();
    api.online = false;

    final visit = await repo.checkIn(customer, visitType: 'order_taking', objectives: 'Restock');
    await repo.saveVisitDetails(visit.clientReference, {'discussion_notes': 'Wants the 20-pack'}, [
      {'activity_type': 'detailing'},
    ]);
    await repo.checkOut(visit.clientReference, outcome: 'successful');
    final draft = await repo.saveOrderDraft(customer: customer, lines: [line()], visitReference: visit.clientReference);
    expect(describeOrder(draft).label, 'Draft on this device');
    await repo.queueOrder(draft.clientReference);
    expect(describeOrder((await repo.order(draft.clientReference))!).label, 'Waiting to sync');

    final offline = await SyncService(repo).run();
    expect(offline.offline, isTrue);
    expect(offline.sent, 0);
    expect(api.calls, isEmpty);
    expect(await repo.outbox.count('pending'), 3, reason: 'nothing is lost while offline');

    api.online = true;
    api.handler = (method, path, body) => switch ('$method $path') {
          'POST field-force/visits' => {
              'data': {'id': 'V1', 'number': 'VIS-000001', 'status': 'in_progress', 'check_in_verification': 'verified'},
            },
          'POST field-force/visits/V1/check-out' => {
              'data': {'id': 'V1', 'number': 'VIS-000001', 'status': 'completed', 'check_in_verification': 'verified', 'check_out_verification': 'verified'},
            },
          'POST field-force/orders' => {
              'data': {
                'id': 'O1',
                'number': 'FO-000001',
                'status': 'accepted',
                'sales_order': {'number': 'SO-000001', 'status': 'confirmed'},
                'stock_reserved': true,
              },
            },
          _ => throw StateError('unexpected $method $path'),
        };

    final online = await SyncService(repo).run();
    expect(online.sent, 3);
    expect(api.calls, ['POST field-force/visits', 'POST field-force/visits/V1/check-out', 'POST field-force/orders']);

    final checkIn = api.bodies[0]!;
    expect(checkIn['client_reference'], visit.clientReference);
    expect((checkIn['check_in'] as Map)['location_status'], 'granted');
    final checkOut = api.bodies[1]!;
    expect(checkOut['discussion_notes'], 'Wants the 20-pack');
    expect((checkOut['activities'] as List).single['activity_type'], 'detailing');
    final order = api.bodies[2]!;
    expect(order['field_visit_id'], 'V1');
    expect(order.containsKey('visit_reference'), isFalse);
    expect((order['lines'] as List).single.containsKey('product_name'), isFalse, reason: 'display-only fields stay on the device');

    final synced = (await repo.visit(visit.clientReference))!;
    expect([synced.syncState, synced.status, synced.checkInVerification], ['synced', 'completed', 'verified']);
    final accepted = describeOrder((await repo.order(draft.clientReference))!);
    expect(accepted.label, 'Accepted by ERP · SO-000001');
    expect(accepted.tone, StatusTone.success);
    expect(await repo.outbox.count('pending'), 0);
  });

  test('a refused check-in fails the steps that depend on it without blocking unrelated work', () async {
    final (repo, api, customer) = await fieldSetup();
    final visit = await repo.checkIn(customer, visitType: 'routine');
    await repo.checkOut(visit.clientReference, outcome: 'successful');
    final order = await repo.saveOrderDraft(customer: customer, lines: [line()], visitReference: visit.clientReference);
    await repo.queueOrder(order.clientReference);

    api.handler = (method, path, body) {
      if (path == 'field-force/visits') throw ApiException(422, 'capture_too_old', 'This capture is older than the offline limit.');
      return {
        'data': {'id': 'O1', 'number': 'FO-000001', 'status': 'accepted', 'sales_order': {'number': 'SO-1', 'status': 'pending_approval'}, 'stock_reserved': false},
      };
    };

    final result = await SyncService(repo).run();
    expect(result.refused, 1);
    expect(api.calls, ['POST field-force/visits', 'POST field-force/orders'], reason: 'the check-out is never attempted');
    expect(api.bodies[1]!.containsKey('field_visit_id'), isFalse);

    final v = (await repo.visit(visit.clientReference))!;
    expect(v.syncState, 'failed');
    expect(v.lastError, contains('older than the offline limit'));
    expect(await repo.outbox.count('failed'), 2);
    expect(await repo.openVisit(), isNull, reason: 'a refused visit does not block the next check-in');

    final status = describeOrder((await repo.order(order.clientReference))!);
    expect(status.tone, StatusTone.warning, reason: 'accepted but awaiting approval: stock not reserved');
    expect(status.detail, contains('not reserved'));
  });

  test('server errors keep the item for a later retry', () async {
    final (repo, api, customer) = await fieldSetup();
    await repo.checkIn(customer, visitType: 'routine');
    api.handler = (method, path, body) => throw ApiException(503, 'http_503', 'Service unavailable');

    final result = await SyncService(repo).run();
    expect(result.sent + result.refused, 0);
    final pending = (await repo.outbox.nextPending())!;
    expect([pending.attempts, pending.lastError], [1, 'Service unavailable']);
  });

  test('an ERP rejection is shown with its reason and the order can be corrected and resubmitted', () async {
    final (repo, api, customer) = await fieldSetup();
    final draft = await repo.saveOrderDraft(customer: customer, lines: [line('500')]);
    await repo.queueOrder(draft.clientReference);

    api.handler = (method, path, body) => switch ('$method $path') {
          'POST field-force/orders' => {
              'data': {
                'id': 'O9',
                'number': 'FO-000009',
                'status': 'rejected',
                'stock_reserved': false,
                'rejection_reasons': [
                  {'code': 'insufficient_stock', 'message': 'Not enough sellable stock.'},
                ],
              },
            },
          'PATCH field-force/orders/O9' => {'data': {}},
          'POST field-force/orders/O9/submit' => {
              'data': {'id': 'O9', 'number': 'FO-000009', 'status': 'accepted', 'sales_order': {'number': 'SO-2', 'status': 'confirmed'}, 'stock_reserved': true},
            },
          _ => throw StateError('unexpected $method $path'),
        };

    await SyncService(repo).run();
    final rejected = (await repo.order(draft.clientReference))!;
    expect(describeOrder(rejected).label, 'Rejected by ERP');
    expect(describeOrder(rejected).detail, 'Not enough sellable stock.');
    expect(rejected.editable, isTrue);

    await repo.saveOrderDraft(ref: rejected.clientReference, customer: customer, lines: [line('1')]);
    await repo.queueOrder(rejected.clientReference);
    await SyncService(repo).run();

    expect(api.calls.skip(1), ['PATCH field-force/orders/O9', 'POST field-force/orders/O9/submit']);
    expect(((api.bodies[1]!['lines'] as List).single as Map)['quantity'], '1');
    expect(describeOrder((await repo.order(rejected.clientReference))!).label, 'Accepted by ERP · SO-2');
  });

  test('without a location fix the check-in carries the status and no coordinates', () async {
    final (repo, api, customer) = await fieldSetup(location: FakeLocation(denied));
    final visit = await repo.checkIn(customer, visitType: 'routine');
    expect(visit.checkIn.describe(), 'Location permission denied');

    api.handler = (method, path, body) => {
          'data': {'id': 'V2', 'number': 'VIS-2', 'status': 'in_progress', 'check_in_verification': 'no_location'},
        };
    await SyncService(repo).run();
    final sent = api.bodies.single!['check_in'] as Map;
    expect(sent['location_status'], 'denied');
    expect(sent.containsKey('latitude'), isFalse);
    expect(sent.containsKey('longitude'), isFalse);
    expect(describeVerification((await repo.visit(visit.clientReference))!.checkInVerification, synced: true).label, 'No location recorded');
  });

  test('one open visit at a time, and cancelling an unsent visit never contacts the server', () async {
    final (repo, api, customer) = await fieldSetup();
    final visit = await repo.checkIn(customer, visitType: 'routine');
    expect(() => repo.checkIn(customer, visitType: 'routine'), throwsStateError);

    await repo.cancelVisit(visit.clientReference, 'Wrong customer');
    expect(await repo.outbox.count('pending'), 0);
    expect((await repo.visit(visit.clientReference))!.status, 'cancelled');
    expect(await repo.openVisit(), isNull);
  });

  test('customers that are no longer assigned are removed from the device', () async {
    final (repo, api, customer) = await fieldSetup();
    api.handler = (method, path, body) => {
          'data': [
            {'id': 'C2', 'name': 'Ikeja Pharmacy', 'code': 'B', 'latitude': '6.6018000', 'longitude': '3.3515000', 'currency_code': 'NGN'},
          ],
          'last_page': 1,
        };
    await repo.refreshCustomers();
    final all = await repo.customers();
    expect(all.map((c) => c.id), ['C2']);
    expect(all.single.hasLocation, isTrue);
    expect(await repo.customer(customer.id), isNull);
  });
}
