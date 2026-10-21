import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/money.dart';
import '../data/pos_repository.dart';
import '../data/status_text.dart';
import '../state/app_state.dart';
import 'sync_status_screen.dart';
import 'theme.dart';
import 'widgets.dart';

/// Spec §13.5: what a sale's payments mean, in words.
StatusView describePosSale(LocalPosSale s) {
  if (s.syncState == 'failed') return StatusView('Not accepted: needs attention', StatusTone.danger, s.lastError);
  if (s.serverStatus == null) {
    return const StatusView('Saved on this device · waiting to sync', StatusTone.pending, 'Cash is recorded offline; card payments are pending until a supervisor confirms them.');
  }
  if (s.serverStatus == 'awaiting_payment') {
    return StatusView('Synced · ${s.number} · payment pending confirmation', StatusTone.warning, 'A card or transfer payment waits for the supervisor\'s settlement check.');
  }
  if (s.warnings.isNotEmpty) return StatusView('Synced · ${s.number} · review notes', StatusTone.warning, 'A supervisor will review: ${s.warnings.map((w) => (w as Map)['type']).join(', ')}.');
  return StatusView('Synced · ${s.number}', StatusTone.success);
}

String paymentStateLabel(String? state, String method) => switch (state) {
      'offline_cash_recorded' => 'Cash recorded offline (reconciled at shift count)',
      'confirmed' => 'Confirmed',
      'payment_pending' => 'Pending confirmation',
      'payment_failed' => 'Failed',
      _ => method == 'cash' ? 'Cash recorded offline (not yet synced)' : 'Pending confirmation (not yet synced)',
    };

class PosHomeScreen extends StatefulWidget {
  const PosHomeScreen({super.key});

  @override
  State<PosHomeScreen> createState() => _PosHomeScreenState();
}

class _PosHomeScreenState extends State<PosHomeScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final shift = app.posShift;
    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(app.session!.terminalName ?? 'Till', style: const TextStyle(fontSize: 16)),
          Text('${app.session!.userName}${shift == null ? '' : ' · shift ${shift['number']}'}', style: const TextStyle(fontSize: 12, color: AppColors.gold)),
        ]),
        actions: [
          const SyncButton(),
          PopupMenuButton<String>(
            onSelected: (v) async {
              if (v == 'catalogue') await app.refreshPos();
              if (v == 'sync' && context.mounted) await Navigator.push(context, MaterialPageRoute(builder: (_) => const SyncStatusScreen()));
              if (v == 'signout') await app.signOut();
            },
            itemBuilder: (_) => const [
              PopupMenuItem(value: 'catalogue', child: Text('Refresh catalogue')),
              PopupMenuItem(value: 'sync', child: Text('Sync status')),
              PopupMenuItem(value: 'signout', child: Text('Sign out')),
            ],
          ),
        ],
      ),
      body: Column(children: [
        if (app.syncMessage != null) MaterialBanner(content: Text(app.syncMessage!), actions: [TextButton(onPressed: app.syncNow, child: const Text('Retry'))]),
        Expanded(
          child: shift == null
              ? const _OpenShift()
              : IndexedStack(index: _tab, children: [_Register(shiftId: shift['id'] as String), _Sales(shiftId: shift['id'] as String), const _Shift()]),
        ),
      ]),
      bottomNavigationBar: shift == null
          ? null
          : NavigationBar(
              selectedIndex: _tab,
              onDestinationSelected: (i) => setState(() => _tab = i),
              destinations: const [
                NavigationDestination(icon: Icon(Icons.point_of_sale), label: 'Register'),
                NavigationDestination(icon: Icon(Icons.receipt_long_outlined), label: 'Sales'),
                NavigationDestination(icon: Icon(Icons.lock_clock_outlined), label: 'Shift'),
              ],
            ),
    );
  }
}

class _OpenShift extends StatefulWidget {
  const _OpenShift();

  @override
  State<_OpenShift> createState() => _OpenShiftState();
}

class _OpenShiftState extends State<_OpenShift> {
  final _float = TextEditingController(text: '0');

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return ListView(padding: const EdgeInsets.all(16), children: [
      const Text('Open a shift', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
      const SizedBox(height: 8),
      const Text('Opening a shift needs a connection. Once it is open, you can keep selling if the connection drops; sales are sent automatically when it returns.'),
      const SizedBox(height: 16),
      TextField(controller: _float, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Opening float')),
      if (app.error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(app.error!, style: const TextStyle(color: AppColors.danger))),
      const SizedBox(height: 16),
      FilledButton(onPressed: app.busy ? null : () => app.openShift(_float.text.trim()), child: const Text('Open shift')),
    ]);
  }
}

class _Register extends StatefulWidget {
  const _Register({required this.shiftId});

  final String shiftId;

  @override
  State<_Register> createState() => _RegisterState();
}

class _RegisterState extends State<_Register> {
  final _search = TextEditingController();
  final List<CartLine> _cart = [];
  List<PosItem> _results = [];
  String _currency = '';
  DateTime? _asOf;
  Map<String, dynamic> _settings = const {};

  @override
  void initState() {
    super.initState();
    _loadMeta();
  }

  Future<void> _loadMeta() async {
    final pos = context.read<AppState>().pos!;
    final currency = await pos.currency();
    final asOf = await pos.catalogueAsOf();
    final settings = await pos.settings();
    if (!mounted) return;
    setState(() {
      _currency = currency;
      _asOf = asOf;
      _settings = settings;
    });
  }

  Future<void> _find(String q) async {
    final results = await context.read<AppState>().pos!.search(q);
    if (mounted) setState(() => _results = results);
  }

  /// Scanner input: a code followed by Enter adds the exact match straight away.
  Future<void> _scan(String code) async {
    final hit = await context.read<AppState>().pos!.scan(code);
    if (hit == null) return _find(code);
    _search.clear();
    await _add(hit.$1, batch: hit.$2);
  }

  Future<void> _add(PosItem item, {PosBatch? batch}) async {
    final app = context.read<AppState>();
    final level = item.levels.where((l) => l.unitPrice != null).firstOrNull;
    if (level == null || item.taxRate == null) {
      showMessage(context, '${item.displayName} has no price or tax setting and cannot be sold offline.', error: true);
      return;
    }
    Map<String, String>? prescription;
    if (item.prescriptionControlled && _settings['enforce_prescription_controls'] != false) {
      if (!app.session!.can('pos.dispense.prescription')) {
        showMessage(context, '${item.displayName} must be dispensed by an authorized pharmacist.', error: true);
        return;
      }
      prescription = await _askPrescription(item);
      if (prescription == null) return;
    }
    setState(() {
      final same = _cart.where((l) => l.item.productId == item.productId && l.level.code == level.code && l.batch?.batchId == batch?.batchId).firstOrNull;
      if (same != null && prescription == null) {
        same.quantity = same.quantity + D4.parse('1');
      } else {
        _cart.add(CartLine(item: item, level: level, quantity: D4.parse('1'), unitPrice: level.unitPrice!, batch: batch, prescription: prescription));
      }
      _results = [];
    });
  }

  Future<Map<String, String>?> _askPrescription(PosItem item) async {
    final ref = TextEditingController(), prescriber = TextEditingController(), date = TextEditingController(text: DateFormat('yyyy-MM-dd').format(DateTime.now()));
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('Prescription for ${item.displayName}'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: ref, decoration: const InputDecoration(labelText: 'Prescription reference')),
          const SizedBox(height: 8),
          TextField(controller: prescriber, decoration: const InputDecoration(labelText: 'Prescriber')),
          const SizedBox(height: 8),
          TextField(controller: date, decoration: const InputDecoration(labelText: 'Prescribed on (YYYY-MM-DD)')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Add')),
        ],
      ),
    );
    if (ok != true || ref.text.trim().isEmpty || prescriber.text.trim().isEmpty || date.text.trim().isEmpty) return null;
    return {'reference': ref.text.trim(), 'prescriber_name': prescriber.text.trim(), 'prescribed_on': date.text.trim()};
  }

  @override
  Widget build(BuildContext context) {
    final totals = CartTotals.of(_cart);
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
        child: TextField(
          controller: _search,
          autofocus: true,
          decoration: const InputDecoration(hintText: 'Scan or search (name, SKU, barcode, batch)', prefixIcon: Icon(Icons.qr_code_scanner)),
          onChanged: (v) => v.trim().length >= 2 ? _find(v) : setState(() => _results = []),
          onSubmitted: _scan,
        ),
      ),
      if (_asOf != null)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
          child: Text('Prices and stock as of ${DateFormat.MMMd().add_Hm().format(_asOf!.toLocal())}', style: const TextStyle(fontSize: 12, color: Colors.blueGrey)),
        ),
      Expanded(
        child: ListView(children: [
          for (final item in _results)
            ListTile(
              dense: true,
              title: Text(item.displayName),
              subtitle: Text('${item.sku ?? ''} · ${item.sellableBaseUnits.money().replaceAll('.00', '')} units on this till\'s snapshot${item.prescriptionControlled ? ' · Rx' : ''}'),
              trailing: const Icon(Icons.add_circle_outline, color: AppColors.royal),
              onTap: () => _add(item),
            ),
          if (_results.isNotEmpty) const Divider(),
          if (_cart.isEmpty && _results.isEmpty) const EmptyState(icon: Icons.shopping_basket_outlined, message: 'Scan a product to start a sale.'),
          for (var i = 0; i < _cart.length; i++) _cartRow(i),
        ]),
      ),
      if (_cart.isNotEmpty)
        Material(
          elevation: 8,
          child: SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(children: [
                _total('Subtotal', totals.subtotal),
                if (!totals.discount.isZero) _total('Discount', D4.zero - totals.discount),
                _total('Tax', totals.tax),
                _total('Total', totals.total, bold: true),
                const SizedBox(height: 8),
                SizedBox(width: double.infinity, child: FilledButton.icon(onPressed: () => _pay(totals.total), icon: const Icon(Icons.payments_outlined), label: Text('Pay $_currency ${totals.total.money()}'))),
              ]),
            ),
          ),
        ),
    ]);
  }

  Widget _cartRow(int i) {
    final l = _cart[i];
    final over = l.baseQuantity > l.item.sellableBaseUnits;
    return ListTile(
      title: Text(l.item.displayName),
      subtitle: Text('${l.level.name} × ${l.quantity.money().replaceAll('.00', '')} @ ${l.unitPrice.money()}'
          '${l.batch != null ? ' · batch ${l.batch!.batchNumber}' : ''}${over ? ' · more than this till\'s stock figure; it will be reconciled' : ''}'),
      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
        IconButton(icon: const Icon(Icons.remove), onPressed: () => setState(() => l.quantity > D4.parse('1') ? l.quantity = l.quantity - D4.parse('1') : _cart.removeAt(i))),
        Text(l.amounts.total.money()),
        IconButton(icon: const Icon(Icons.add), onPressed: () => setState(() => l.quantity = l.quantity + D4.parse('1'))),
      ]),
    );
  }

  Widget _total(String label, D4 amount, {bool bold = false}) => Row(children: [
        Expanded(child: Text(label, style: TextStyle(fontWeight: bold ? FontWeight.bold : null))),
        Text('$_currency ${amount.money()}', style: TextStyle(fontWeight: bold ? FontWeight.bold : null, fontSize: bold ? 18 : null)),
      ]);

  Future<void> _pay(D4 total) async {
    final payment = await showModalBottomSheet<PosPaymentInput>(context: context, isScrollControlled: true, builder: (_) => _PaymentSheet(total: total, currency: _currency));
    if (payment == null || !mounted) return;
    final app = context.read<AppState>();
    try {
      final sale = await app.pos!.recordSale(shiftId: widget.shiftId, lines: List.of(_cart), payments: [payment]);
      await app.changed();
      if (!mounted) return;
      setState(() => _cart.clear());
      app.syncNow();
      await Navigator.push(context, MaterialPageRoute(builder: (_) => ReceiptScreen(reference: sale.clientReference)));
    } on StateError catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }
}

class _PaymentSheet extends StatefulWidget {
  const _PaymentSheet({required this.total, required this.currency});

  final D4 total;
  final String currency;

  @override
  State<_PaymentSheet> createState() => _PaymentSheetState();
}

class _PaymentSheetState extends State<_PaymentSheet> {
  String _method = 'cash';
  late final _tendered = TextEditingController(text: widget.total.toString().replaceAll(RegExp(r'\.?0+$'), ''));
  final _approval = TextEditingController();

  @override
  Widget build(BuildContext context) {
    final tendered = D4.parse(_tendered.text.isEmpty ? '0' : _tendered.text);
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(context).viewInsets.bottom + 16),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('Total ${widget.currency} ${widget.total.money()}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
        const SizedBox(height: 12),
        SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'cash', label: Text('Cash'), icon: Icon(Icons.payments)),
            ButtonSegment(value: 'card', label: Text('Card terminal'), icon: Icon(Icons.credit_card)),
            ButtonSegment(value: 'bank_transfer', label: Text('Transfer'), icon: Icon(Icons.account_balance)),
          ],
          selected: {_method},
          onSelectionChanged: (s) => setState(() => _method = s.first),
        ),
        const SizedBox(height: 12),
        if (_method == 'cash') ...[
          TextField(controller: _tendered, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Cash tendered'), onChanged: (_) => setState(() {})),
          if (tendered >= widget.total) Padding(padding: const EdgeInsets.only(top: 8), child: Text('Change ${widget.currency} ${(tendered - widget.total).money()}', style: const TextStyle(fontSize: 16))),
        ] else ...[
          TextField(controller: _approval, decoration: const InputDecoration(labelText: 'Approval code from the terminal slip')),
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text('Recorded as pending. It is confirmed only when a supervisor checks the terminal settlement.', style: TextStyle(fontSize: 12, color: Colors.blueGrey)),
          ),
        ],
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _method == 'cash' ? (tendered >= widget.total ? () => Navigator.pop(context, PosPaymentInput.cash(amount: widget.total, tendered: tendered)) : null)
              : (_approval.text.trim().isEmpty ? null : () => Navigator.pop(context, PosPaymentInput.terminal(method: _method, amount: widget.total, approvalCode: _approval.text.trim()))),
          child: const Text('Complete sale'),
        ),
      ]),
    );
  }
}

class _Sales extends StatelessWidget {
  const _Sales({required this.shiftId});

  final String shiftId;

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return FutureBuilder<List<LocalPosSale>>(
      future: app.pos!.sales(shiftId: shiftId),
      builder: (context, snap) {
        final sales = snap.data ?? const <LocalPosSale>[];
        if (sales.isEmpty) return const EmptyState(icon: Icons.receipt_long_outlined, message: 'No sales on this shift yet.');
        return ListView(children: [
          for (final s in sales)
            Card(
              child: ListTile(
                title: Text('${s.number ?? 'Offline sale'} · ${s.total.money()}'),
                subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(DateFormat.Hm().format(s.completedAt.toLocal())),
                  const SizedBox(height: 4),
                  StatusChip(describePosSale(s)),
                ]),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => ReceiptScreen(reference: s.clientReference))),
              ),
            ),
        ]);
      },
    );
  }
}

class _Shift extends StatefulWidget {
  const _Shift();

  @override
  State<_Shift> createState() => _ShiftState();
}

class _ShiftState extends State<_Shift> {
  final _counted = TextEditingController();

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final shift = app.posShift!;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text('Shift ${shift['number']}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
      Text('Opening float ${D4.parse(shift['opening_float'].toString()).money()}'),
      const SizedBox(height: 16),
      FutureBuilder<int>(
        future: app.pos!.unsentSales(),
        builder: (context, snap) => Text(
            (snap.data ?? 0) == 0 ? 'All sales on this device have reached the server.' : '${snap.data} sale(s) have not reached the server yet. The shift can be closed once they have.',
            style: TextStyle(color: (snap.data ?? 0) == 0 ? AppColors.success : AppColors.warning)),
      ),
      const SizedBox(height: 16),
      TextField(controller: _counted, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Cash counted in the drawer')),
      if (app.error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(app.error!, style: const TextStyle(color: AppColors.danger))),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: app.busy
            ? null
            : () async {
                final closed = await app.closeShift(_counted.text.trim());
                if (closed != null && context.mounted) {
                  showMessage(context, 'Shift closed. Expected ${closed['expected_cash']}, counted ${closed['counted_cash']}, variance ${closed['variance']}.');
                }
              },
        child: const Text('Close shift (needs a connection)'),
      ),
    ]);
  }
}

class ReceiptScreen extends StatelessWidget {
  const ReceiptScreen({super.key, required this.reference});

  final String reference;

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return Scaffold(
      appBar: AppBar(title: const Text('Receipt')),
      body: FutureBuilder<LocalPosSale?>(
        future: app.pos!.sale(reference),
        builder: (context, snap) {
          final s = snap.data;
          if (s == null) return const Center(child: CircularProgressIndicator());
          final states = s.paymentStates;
          return ListView(padding: const EdgeInsets.all(16), children: [
            Center(child: Text(app.session!.organizationName ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold))),
            Center(child: Text(app.session!.terminalName ?? '')),
            const SizedBox(height: 8),
            Center(
              child: Text(s.number ?? 'OFFLINE RECEIPT · not yet synced', style: TextStyle(fontWeight: FontWeight.bold, color: s.number == null ? AppColors.warning : null)),
            ),
            if (s.number == null) Center(child: Text('Ref ${s.clientReference.substring(0, 13)}', style: const TextStyle(fontSize: 11))),
            Center(child: Text(DateFormat.yMMMd().add_Hm().format(s.completedAt.toLocal()))),
            const Divider(),
            for (final l in s.lines)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Expanded(
                    child: Text('${l['product_name']}\n${l['level_name']} × ${D4.parse(l['quantity'] as String).money().replaceAll('.00', '')} @ ${D4.parse(l['unit_price'] as String).money()}'
                        '${l['batch_number'] != null ? '\nBatch ${l['batch_number']}' : ''}'),
                  ),
                  Text(D4.parse(l['line_total'] as String).money()),
                ]),
              ),
            const Divider(),
            _line('Subtotal', s.subtotal),
            if (!s.discount.isZero) _line('Discount', D4.zero - s.discount),
            _line('Tax', s.tax),
            _line('Total', s.total, bold: true),
            const SizedBox(height: 8),
            for (var i = 0; i < s.payments.length; i++)
              Text('${s.payments[i]['method'] == 'cash' ? 'Cash' : 'Card/transfer'} ${D4.parse(s.payments[i]['amount'] as String).money()} · '
                  '${paymentStateLabel(i < states.length ? states[i] : null, s.payments[i]['method'] as String)}'),
            if (!s.changeDue.isZero) _line('Change', s.changeDue),
            const SizedBox(height: 12),
            StatusBanner(describePosSale(s)),
          ]);
        },
      ),
    );
  }

  Widget _line(String label, D4 amount, {bool bold = false}) => Row(children: [
        Expanded(child: Text(label, style: TextStyle(fontWeight: bold ? FontWeight.bold : null))),
        Text(amount.money(), style: TextStyle(fontWeight: bold ? FontWeight.bold : null)),
      ]);
}
