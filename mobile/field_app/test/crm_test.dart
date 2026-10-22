import 'package:flutter_test/flutter_test.dart';
import 'package:pharma_field/data/sync_service.dart';

import 'support/fakes.dart';

Map<String, dynamic> _myDay() => {
      'data': {
        'date': '2026-10-25',
        'calls': [
          {'id': 'I2', 'customer_id': 'C2', 'customer_name': 'Yaba Chemist', 'city': 'Lagos', 'sequence': 2, 'objective': null, 'status': 'planned', 'plan_status': 'approved'},
          {'id': 'I1', 'customer_id': 'C1', 'customer_name': 'Lekki Clinic', 'city': 'Lagos', 'sequence': 1, 'objective': 'Detail Testmox', 'status': 'planned', 'plan_status': 'approved'},
        ],
        'follow_ups': [],
        'detailing_messages': [
          {'id': 'M1', 'product_id': 'P1', 'title': 'Dosing', 'key_message': 'Twice daily with food.', 'valid_until': null},
          {'id': 'M2', 'product_id': 'P1', 'title': 'Storage', 'key_message': 'Store below 25 °C.', 'valid_until': null},
        ],
      },
    };

void main() {
  test('my day caches the planned calls in route order and the approved key messages', () async {
    final (repo, api, _) = await fieldSetup();
    api.handler = (method, path, body) => path == 'crm/my-day' ? _myDay() : throw StateError(path);

    await repo.refreshMyDay();
    final calls = await repo.myDay();
    expect(calls.map((c) => c.customerName).toList(), ['Lekki Clinic', 'Yaba Chemist']);
    expect(calls.first.objective, 'Detail Testmox');
    expect((await repo.detailingMessages(productId: 'P1')).map((m) => m.title).toList(), ['Dosing', 'Storage']);

    // Offline the cached day stays available.
    api.online = false;
    expect(await repo.myDay(), hasLength(2));
  });

  test('detailing and feedback captured offline are sent after the visit check-in, referencing it by client reference', () async {
    final (repo, api, customer) = await fieldSetup();
    api.handler = (method, path, body) => _myDay();
    await repo.refreshMyDay();

    api.online = false;
    final visit = await repo.checkIn(customer, visitType: 'detailing');
    await expectLater(repo.recordDetailing(visit.clientReference, productId: 'P1', messageIds: ['M9']), throwsA(isA<StateError>()), reason: 'only cached approved messages');
    await expectLater(repo.recordDetailing(visit.clientReference, productId: 'P1', messageIds: ['M1'], samplesQuantity: '2'), throwsA(isA<StateError>()),
        reason: 'samples need their batch number');
    final detailing = await repo.recordDetailing(visit.clientReference, productId: 'P1', productName: 'Testmox', messageIds: ['M1', 'M2'], reception: 'positive',
        samplesQuantity: '2', sampleBatchNumber: 'FF-1');
    final feedback = await repo.recordFeedback(customer.id,
        category: 'product_quality', description: 'Rash reported after the second dose', visitReference: visit.clientReference, possibleAdverseEvent: true);
    expect([detailing.syncState, feedback.syncState, feedback.possibleAdverseEvent], ['pending', 'pending', true]);

    api.online = true;
    api.onEnvelope = (type, p) => switch (type) {
          'field.visit.check_in' => {'id': 'V1', 'number': 'VIS-1', 'status': 'in_progress', 'check_in_verification': 'verified'},
          'crm.detailing.record' => {'id': 'D1', 'reception': 'positive'},
          'crm.feedback.record' => {'id': 'F1', 'status': 'open', 'possible_adverse_event': true, 'pharmacovigilance_notice': 'Report it.'},
          _ => throw StateError(type),
        };
    final result = await SyncService(repo).run();
    expect(result.sent, 3);
    expect(api.processedTypes, ['field.visit.check_in', 'crm.detailing.record', 'crm.feedback.record']);

    final sentDetailing = api.processed[1]['payload'] as Map;
    expect(sentDetailing['visit_client_reference'], visit.clientReference);
    expect(sentDetailing['message_ids'], ['M1', 'M2']);
    expect(sentDetailing['sample_batch_number'], 'FF-1');
    final sentFeedback = api.processed[2]['payload'] as Map;
    expect([sentFeedback['possible_adverse_event'], sentFeedback['visit_client_reference']], [true, visit.clientReference]);

    final stored = (await repo.detailingFor(visit.clientReference)).single;
    expect([stored.serverId, stored.syncState], ['D1', 'synced']);
    expect((await repo.feedbackFor(customer.id)).single.serverId, 'F1');
  });

  test('a refused detailing record is kept with the server reason and does not block later work', () async {
    final (repo, api, customer) = await fieldSetup();
    api.handler = (method, path, body) => _myDay();
    await repo.refreshMyDay();
    final visit = await repo.checkIn(customer, visitType: 'detailing');
    await repo.recordDetailing(visit.clientReference, productId: 'P1', messageIds: ['M1']);
    await repo.recordFeedback(customer.id, category: 'praise', description: 'Quick delivery');

    api.onEnvelope = (type, p) => switch (type) {
          'crm.detailing.record' => throw Refusal('rejected', 'detailing_message_not_approved', 'Only approved, current key messages can be recorded.'),
          'crm.feedback.record' => {'id': 'F2', 'status': 'open', 'possible_adverse_event': false},
          _ => {'id': 'V1', 'number': 'VIS-1', 'status': 'in_progress', 'check_in_verification': 'verified'},
        };
    final result = await SyncService(repo).run();
    expect([result.sent, result.refused], [2, 1]);
    final refused = (await repo.detailingFor(visit.clientReference)).single;
    expect([refused.syncState, refused.lastError], ['failed', 'Only approved, current key messages can be recorded.']);
    expect((await repo.feedbackFor(customer.id)).single.serverId, 'F2');
  });
}
