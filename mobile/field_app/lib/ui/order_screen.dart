import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/api.dart';
import '../data/models.dart';
import '../data/status_text.dart';
import '../state/app_state.dart';
import 'theme.dart';
import 'widgets.dart';

/// Order capture (spec §12.4). Prices and stock are shown as cached and
/// indicative; the order becomes real only when the ERP accepts it.
class OrderScreen extends StatefulWidget {
  const OrderScreen({super.key, required this.customerId, this.reference, this.visitReference});

  final String customerId;
  final String? reference;
  final String? visitReference;

  @override
  State<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends State<OrderScreen> {
  Customer? _customer;
  LocalOrder? _order;
  List<CatalogueItem> _catalogue = [];
  final List<OrderLine> _lines = [];
  final _notes = TextEditingController();
  String _query = '';
  bool _loadingCatalogue = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final repo = context.read<AppState>().repository!;
    final customer = await repo.customer(widget.customerId);
    final order = widget.reference == null ? null : await repo.order(widget.reference!);
    final catalogue = await repo.catalogue(widget.customerId);
    if (!mounted) return;
    setState(() {
      _customer = customer;
      _order = order;
      _catalogue = catalogue;
      if (order != null) {
        _lines
          ..clear()
          ..addAll(order.lines);
        _notes.text = order.notes ?? '';
      }
    });
    if (catalogue.isEmpty && (order == null || order.editable)) await _refreshCatalogue();
  }

  Future<void> _refreshCatalogue() async {
    final repo = context.read<AppState>().repository!;
    setState(() => _loadingCatalogue = true);
    try {
      await repo.refreshCatalogue(widget.customerId);
      final items = await repo.catalogue(widget.customerId);
      if (mounted) setState(() => _catalogue = items);
    } on OfflineException {
      if (mounted) showMessage(context, 'Offline: showing the catalogue saved on this device.');
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _loadingCatalogue = false);
    }
  }

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final customer = _customer;
    if (customer == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    final editable = _order == null || _order!.editable;
    final asOf = _catalogue.isEmpty ? null : _catalogue.first.stockAsOf;
    final q = _query.toLowerCase();
    final shown = _catalogue.where((i) => q.isEmpty || i.displayName.toLowerCase().contains(q) || (i.genericName ?? '').toLowerCase().contains(q) || i.sku == _query).toList();

    return Scaffold(
      appBar: AppBar(title: Text(_order?.number ?? 'Order: ${customer.name}')),
      body: ListView(padding: const EdgeInsets.only(bottom: 120), children: [
        if (_order != null) StatusBanner(describeOrder(_order!)),
        if (_order != null && _order!.serverStatus == 'rejected' && _order!.rejection != null)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Text('Fix the order and submit it again.', style: TextStyle(color: Colors.blueGrey.shade700)),
          ),
        if (editable)
          Container(
            margin: const EdgeInsets.all(12),
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(color: AppColors.gold.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(8)),
            child: const Text('Prices and stock here are indicative. The ERP sets the final price and reserves stock only when it accepts the order.',
                style: TextStyle(fontSize: 13)),
          ),
        const _Section('Lines'),
        if (_lines.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('No products added yet.')),
        for (var i = 0; i < _lines.length; i++)
          ListTile(
            title: Text(_lines[i].productName.isEmpty ? _lines[i].productId : _lines[i].productName),
            subtitle: Text('${_lines[i].quantity} × ${_lines[i].packageLevelCode}'
                '${_lines[i].indicativeUnitPrice == null ? '' : ' · indicative ${customer.currencyCode ?? ''} ${_lines[i].indicativeUnitPrice}'}'),
            trailing: editable ? IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => setState(() => _lines.removeAt(i))) : null,
          ),
        if (editable) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
            child: TextField(controller: _notes, decoration: const InputDecoration(labelText: 'Notes for the order (optional)')),
          ),
          Row(children: [
            const Expanded(child: _Section('Add products')),
            if (asOf != null)
              Text('Stock as of ${DateFormat.MMMd().add_Hm().format(asOf.toLocal())}', style: const TextStyle(fontSize: 12, color: Colors.blueGrey)),
            IconButton(onPressed: _loadingCatalogue ? null : _refreshCatalogue, icon: _loadingCatalogue ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.refresh)),
          ]),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: TextField(decoration: const InputDecoration(hintText: 'Search products', prefixIcon: Icon(Icons.search)), onChanged: (v) => setState(() => _query = v)),
          ),
          if (_catalogue.isEmpty && !_loadingCatalogue) const Padding(padding: EdgeInsets.all(16), child: Text('No catalogue saved on this device yet. Connect and tap refresh.')),
          for (final item in shown.take(50))
            ListTile(
              title: Text(item.displayName),
              subtitle: Text([item.genericName, if (item.sellableBaseUnits != null) '${_trim(item.sellableBaseUnits!)} units available (cached)'].whereType<String>().join(' · ')),
              trailing: const Icon(Icons.add_circle_outline, color: AppColors.royal),
              onTap: () => _addLine(item),
            ),
        ],
      ]),
      bottomSheet: editable
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(children: [
                  Expanded(child: OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: () => _save(submit: false), child: const Text('Save draft'))),
                  const SizedBox(width: 12),
                  Expanded(child: FilledButton(onPressed: _lines.isEmpty ? null : () => _save(submit: true), child: const Text('Submit to ERP'))),
                ]),
              ),
            )
          : null,
    );
  }

  static String _trim(String v) => v.contains('.') ? v.replaceAll(RegExp(r'0+$'), '').replaceAll(RegExp(r'\.$'), '') : v;

  Future<void> _addLine(CatalogueItem item) async {
    if (item.levels.isEmpty) return;
    var level = item.levels.firstWhere((l) => l.indicativePrice != null, orElse: () => item.levels.first);
    final qty = TextEditingController(text: '1');
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: Text(item.displayName),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String>(
              initialValue: level.code,
              decoration: const InputDecoration(labelText: 'Pack'),
              items: [
                for (final l in item.levels)
                  DropdownMenuItem(value: l.code, child: Text('${l.name} (${_trim(l.baseUnitQuantity)}) · ${l.indicativePrice == null ? 'price on request' : '${item.currencyCode ?? ''} ${_trim(l.indicativePrice!)}'}')),
              ],
              onChanged: (v) => setState(() => level = item.levels.firstWhere((l) => l.code == v)),
            ),
            const SizedBox(height: 12),
            TextField(controller: qty, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Quantity')),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Add')),
          ],
        ),
      ),
    );
    final quantity = double.tryParse(qty.text.trim());
    if (ok != true || quantity == null || quantity <= 0) return;
    setState(() => _lines.add(OrderLine(
          productId: item.productId,
          productName: item.displayName,
          packageLevelCode: level.code,
          quantity: qty.text.trim(),
          indicativeUnitPrice: level.indicativePrice,
        )));
  }

  Future<void> _save({required bool submit}) async {
    final app = context.read<AppState>();
    final repo = app.repository!;
    try {
      final saved = await repo.saveOrderDraft(
          ref: _order?.clientReference, customer: _customer!, lines: List.of(_lines), visitReference: widget.visitReference, notes: _notes.text.trim());
      if (submit) await repo.queueOrder(saved.clientReference);
      await app.changed();
      if (!mounted) return;
      showMessage(context, submit ? 'Queued for the ERP. It is not accepted until the ERP confirms it.' : 'Draft saved on this device.');
      if (submit) app.syncNow();
      Navigator.pop(context);
    } on StateError catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }
}

class _Section extends StatelessWidget {
  const _Section(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
        child: Text(text.toUpperCase(), style: const TextStyle(fontSize: 12, letterSpacing: 1.2, fontWeight: FontWeight.w600, color: AppColors.navy)),
      );
}
