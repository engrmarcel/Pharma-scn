import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../data/models.dart';
import '../data/status_text.dart';
import '../state/app_state.dart';
import 'order_screen.dart';
import 'theme.dart';
import 'visit_screen.dart';
import 'widgets.dart';

const visitTypes = {
  'routine': 'Routine call',
  'detailing': 'Product detailing',
  'order_taking': 'Order taking',
  'follow_up': 'Follow-up',
  'complaint': 'Complaint',
  'collection': 'Collection',
  'other': 'Other',
};

class CustomerScreen extends StatelessWidget {
  const CustomerScreen({super.key, required this.customerId});

  final String customerId;

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final repo = app.repository!;
    return FutureBuilder(
      future: Future.wait([repo.customer(customerId), repo.visits(customerId: customerId), repo.orders(customerId: customerId)]),
      builder: (context, snap) {
        if (!snap.hasData) return const Scaffold(body: Center(child: CircularProgressIndicator()));
        final customer = snap.data![0] as Customer?;
        if (customer == null) {
          return Scaffold(appBar: AppBar(), body: const EmptyState(icon: Icons.lock_outline, message: 'This customer is no longer assigned to you.'));
        }
        final visits = snap.data![1] as List<LocalVisit>;
        final orders = snap.data![2] as List<LocalOrder>;

        return Scaffold(
          appBar: AppBar(title: Text(customer.name)),
          body: ListView(children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text([customer.code, customer.type?.replaceAll('_', ' ')].whereType<String>().join(' · '), style: const TextStyle(color: Colors.blueGrey)),
                  if (customer.address != null || customer.city != null) _row(Icons.place_outlined, [customer.address, customer.city].whereType<String>().join(', ')),
                  if (customer.phone != null) _row(Icons.phone_outlined, customer.phone!),
                  if (customer.email != null) _row(Icons.mail_outline, customer.email!),
                  _row(customer.hasLocation ? Icons.my_location : Icons.location_off_outlined,
                      customer.hasLocation ? 'Location on file: check-ins can be verified' : 'No location on file: check-ins cannot be verified'),
                ]),
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Row(children: [
                Expanded(child: FilledButton.icon(onPressed: () => _checkIn(context, customer), icon: const Icon(Icons.login), label: const Text('Check in'))),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(minimumSize: const Size(0, 48)),
                    onPressed: app.session!.can('field_force.orders.capture')
                        ? () => Navigator.push(context, MaterialPageRoute(builder: (_) => OrderScreen(customerId: customer.id)))
                        : null,
                    icon: const Icon(Icons.add_shopping_cart),
                    label: const Text('New order'),
                  ),
                ),
              ]),
            ),
            const _Header('Visits on this device'),
            if (visits.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('None yet.')),
            for (final v in visits.take(10))
              ListTile(
                title: Text(DateFormat.yMMMd().add_Hm().format(v.checkIn.occurredAt.toLocal())),
                subtitle: Wrap(spacing: 6, children: [StatusChip(describeVisit(v)), StatusChip(describeVerification(v.checkInVerification, synced: v.serverId != null))]),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => VisitScreen(reference: v.clientReference))),
              ),
            const _Header('Orders'),
            if (orders.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('None yet.')),
            for (final o in orders.take(10))
              ListTile(
                title: Text('${o.number ?? 'Not yet numbered'} · ${o.lines.length} line(s)'),
                subtitle: Align(alignment: Alignment.centerLeft, child: StatusChip(describeOrder(o))),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => OrderScreen(customerId: customer.id, reference: o.clientReference))),
              ),
            const SizedBox(height: 24),
          ]),
        );
      },
    );
  }

  Widget _row(IconData icon, String text) => Padding(
        padding: const EdgeInsets.only(top: 8),
        child: Row(children: [Icon(icon, size: 18, color: AppColors.royal), const SizedBox(width: 8), Expanded(child: Text(text))]),
      );

  Future<void> _checkIn(BuildContext context, Customer customer) async {
    final app = context.read<AppState>();
    final repo = app.repository!;

    if (await repo.local.meta('location_notice_seen') == null) {
      if (!context.mounted) return;
      await showLocationNotice(context, app.session!.settings['location_notice']?.toString() ?? '');
      await repo.local.setMeta('location_notice_seen', DateTime.now().toIso8601String());
    }
    if (!context.mounted) return;

    var type = 'routine';
    final objectives = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: Text('Check in at ${customer.name}'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String>(
              initialValue: type,
              decoration: const InputDecoration(labelText: 'Visit type'),
              items: [for (final e in visitTypes.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
              onChanged: (v) => setState(() => type = v ?? type),
            ),
            const SizedBox(height: 12),
            TextField(controller: objectives, decoration: const InputDecoration(labelText: 'Objectives (optional)'), maxLines: 2),
            const SizedBox(height: 8),
            const Text('Your location is read once, now.', style: TextStyle(fontSize: 12, color: Colors.blueGrey)),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Check in')),
          ],
        ),
      ),
    );
    if (confirmed != true) return;

    try {
      final visit = await repo.checkIn(customer, visitType: type, objectives: objectives.text.trim());
      await app.changed();
      if (!context.mounted) return;
      showMessage(context, visit.checkIn.describe());
      app.syncNow();
      await Navigator.push(context, MaterialPageRoute(builder: (_) => VisitScreen(reference: visit.clientReference)));
    } on StateError catch (e) {
      if (context.mounted) showMessage(context, e.message, error: true);
    }
  }
}

class _Header extends StatelessWidget {
  const _Header(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
        child: Text(text.toUpperCase(), style: const TextStyle(fontSize: 12, letterSpacing: 1.2, fontWeight: FontWeight.w600, color: AppColors.navy)),
      );
}
