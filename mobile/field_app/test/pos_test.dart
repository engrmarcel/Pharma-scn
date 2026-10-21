import 'package:flutter_test/flutter_test.dart';
import 'package:pharma_field/core/money.dart';
import 'package:pharma_field/data/field_repository.dart';
import 'package:pharma_field/data/local_db.dart';
import 'package:pharma_field/data/pos_repository.dart';
import 'package:pharma_field/data/sync_service.dart';
import 'package:pharma_field/ui/pos_screens.dart';

import 'support/fakes.dart';

void main() {
  test('money: amounts are computed exactly like the ERP, rounding half-up at four places', () {
    final a = LineAmounts.of(quantity: D4.parse('2'), unitPrice: D4.parse('3000'), discountPercent: D4.parse('0'), taxRate: D4.parse('7.5'));
    expect([a.subtotal.toString(), a.tax.toString(), a.total.toString()], ['6000.0000', '450.0000', '6450.0000']);

    final b = LineAmounts.of(quantity: D4.parse('3'), unitPrice: D4.parse('33.3333'), discountPercent: D4.parse('12.5'), taxRate: D4.parse('7.5'));
    expect(b.subtotal.toString(), '99.9999');
    expect(b.discount.toString(), '12.5000'); // 12.4999875 -> half-up
    expect(b.tax.toString(), '6.5625'); // 87.4999 × 7.5% = 6.5624925 -> 6.5625
    expect(b.total.toString(), '94.0624');
    expect(D4.parse('1234567.005').money(), '1,234,567.01');
    expect(D4.parse('-0.5').toString(), '-0.5000');
  });

  test('a till sale is written with its outbox item in one step, lowers the cached stock and syncs with payment states', () async {
    final db = await openTestDb();
    final api = FakeApi();
    final pos = await posSetup(db, api);

    final (item, batch) = (await pos.scan('P-LATE'))!;
    expect(batch!.batchId, 'B-LATE', reason: 'a scanned batch number selects that batch');
    final pack = item.levels.firstWhere((l) => l.code == 'pack');
    final lines = [CartLine(item: item, level: pack, quantity: D4.parse('2'), unitPrice: pack.unitPrice!, batch: batch)];
    expect(CartTotals.of(lines).total.toString(), '6450.0000');

    expect(() => pos.recordSale(shiftId: 'S1', lines: lines, payments: [PosPaymentInput.cash(amount: D4.parse('6000'), tendered: D4.parse('6000'))]), throwsStateError,
        reason: 'payments must cover the total');

    api.online = false;
    final sale = await pos.recordSale(shiftId: 'S1', lines: lines, payments: [PosPaymentInput.cash(amount: D4.parse('6450'), tendered: D4.parse('7000'))]);
    expect(sale.changeDue.toString(), '550.0000');
    expect(sale.number, isNull);
    expect(describePosSale(sale).label, 'Saved on this device · waiting to sync');
    expect(paymentStateLabel(null, 'cash'), contains('not yet synced'));
    expect(await pos.unsentSales(), 1);

    final cached = (await pos.search('Amox')).single;
    expect(cached.batches.firstWhere((b) => b.batchId == 'B-LATE').free.toString(), '60.0000', reason: 'the scanned batch is consumed first on the device');

    // The shift cannot be closed while sales are unsent.
    expect(() => pos.closeShift('S1', D4.parse('6450')), throwsStateError);

    api.online = true;
    api.onEnvelope = (type, p) {
      expect(type, 'pos.sale');
      expect(p['device_total'], '6450.0000');
      expect((p['lines'] as List).single['batch_id'], 'B-LATE');
      return {
        'id': 'SALE1',
        'number': 'POS-000042',
        'status': 'completed',
        'payments': [
          {'method': 'cash', 'state': 'offline_cash_recorded'},
        ],
        '_warnings': [
          {'type': 'price_variance', 'severity': 'medium'},
        ],
      };
    };
    final result = await SyncService(await _repoFor(db, api), pos: pos).run();
    expect(result.sent, 1);

    final synced = (await pos.sale(sale.clientReference))!;
    expect(synced.number, 'POS-000042');
    expect(paymentStateLabel(synced.paymentStates.single, 'cash'), 'Cash recorded offline (reconciled at shift count)');
    expect(describePosSale(synced).label, 'Synced · POS-000042 · review notes');
  });

  test('a card on a standalone terminal is pending, never confirmed by the device', () async {
    final db = await openTestDb();
    final api = FakeApi();
    final pos = await posSetup(db, api);
    final item = (await pos.search('AMX')).single;
    final capsule = item.levels.firstWhere((l) => l.code == 'capsule');
    final lines = [CartLine(item: item, level: capsule, quantity: D4.parse('3'), unitPrice: capsule.unitPrice!)];

    final sale = await pos.recordSale(shiftId: 'S1', lines: lines, payments: [PosPaymentInput.terminal(method: 'card', amount: CartTotals.of(lines).total, approvalCode: 'AUTH-1')]);
    expect(paymentStateLabel(null, 'card'), 'Pending confirmation (not yet synced)');

    api.onEnvelope = (type, p) => {'id': 'S2', 'number': 'POS-43', 'status': 'awaiting_payment', 'payments': [
          {'method': 'card', 'state': 'payment_pending'},
        ]};
    await SyncService(await _repoFor(db, api), pos: pos).run();
    final synced = (await pos.sale(sale.clientReference))!;
    expect(describePosSale(synced).label, 'Synced · POS-43 · payment pending confirmation');
  });
}

Future<FieldRepository> _repoFor(LocalDb db, FakeApi api) async {
  final (repo, _, _) = await fieldSetup(db: db, api: api);
  return repo;
}
