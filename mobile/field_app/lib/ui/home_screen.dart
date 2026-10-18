import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../data/models.dart';
import '../data/status_text.dart';
import '../state/app_state.dart';
import 'customer_screen.dart';
import 'order_screen.dart';
import 'report_screen.dart';
import 'theme.dart';
import 'visit_screen.dart';
import 'widgets.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final session = app.session!;

    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(session.organizationName ?? '', style: const TextStyle(fontSize: 16)),
          Text(session.userName, style: const TextStyle(fontSize: 12, color: AppColors.gold)),
        ]),
        actions: [
          const SyncButton(),
          PopupMenuButton<String>(
            onSelected: (v) async {
              if (v == 'location') {
                await showLocationNotice(context, session.settings['location_notice']?.toString() ?? '');
              } else if (v == 'report') {
                await Navigator.push(context, MaterialPageRoute(builder: (_) => const ReportScreen()));
              } else if (v == 'attention') {
                await Navigator.push(context, MaterialPageRoute(builder: (_) => const NeedsAttentionScreen()));
              } else if (v == 'signout') {
                await _confirmSignOut(context, app);
              }
            },
            itemBuilder: (_) => [
              if (session.can('field_force.reports.view')) const PopupMenuItem(value: 'report', child: Text('Activity report')),
              const PopupMenuItem(value: 'attention', child: Text('Needs attention')),
              const PopupMenuItem(value: 'location', child: Text('How location is used')),
              const PopupMenuItem(value: 'signout', child: Text('Sign out')),
            ],
          ),
        ],
      ),
      body: Column(children: [
        if (app.syncMessage != null)
          MaterialBanner(content: Text(app.syncMessage!), actions: [TextButton(onPressed: app.syncNow, child: const Text('Retry'))]),
        const _OpenVisitBanner(),
        Expanded(
          child: IndexedStack(index: _tab, children: const [_CustomersTab(), _VisitsTab(), _OrdersTab(), _FollowUpsTab()]),
        ),
      ]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.storefront_outlined), label: 'Customers'),
          NavigationDestination(icon: Icon(Icons.pin_drop_outlined), label: 'Visits'),
          NavigationDestination(icon: Icon(Icons.receipt_long_outlined), label: 'Orders'),
          NavigationDestination(icon: Icon(Icons.task_alt), label: 'Follow-ups'),
        ],
      ),
    );
  }

  Future<void> _confirmSignOut(BuildContext context, AppState app) async {
    final waiting = app.pending;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Sign out?'),
        content: Text(waiting > 0
            ? '$waiting change(s) have not been sent yet. They stay on this device and are sent the next time you sign in to this organization.'
            : 'You can sign in again at any time.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sign out')),
        ],
      ),
    );
    if (ok == true) await app.signOut();
  }
}

/// Reloads its content whenever AppState notifies (after sync or local changes).
class _Reloading<T> extends StatelessWidget {
  const _Reloading({required this.load, required this.builder});

  final Future<T> Function(AppState app) load;
  final Widget Function(BuildContext context, T data) builder;

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return FutureBuilder<T>(
      future: load(app),
      builder: (context, snap) {
        if (snap.hasError) return EmptyState(icon: Icons.error_outline, message: snap.error.toString());
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        return RefreshIndicator(onRefresh: app.syncNow, child: builder(context, snap.data as T));
      },
    );
  }
}

class _OpenVisitBanner extends StatelessWidget {
  const _OpenVisitBanner();

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return FutureBuilder<LocalVisit?>(
      future: app.repository!.openVisit(),
      builder: (context, snap) {
        final v = snap.data;
        if (v == null) return const SizedBox.shrink();
        return Material(
          color: AppColors.gold.withValues(alpha: 0.2),
          child: ListTile(
            leading: const Icon(Icons.timer_outlined, color: AppColors.navy),
            title: Text('Visit in progress: ${v.customerName ?? ''}'),
            subtitle: Text('Checked in ${DateFormat.Hm().format(v.checkIn.occurredAt.toLocal())}'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => VisitScreen(reference: v.clientReference))),
          ),
        );
      },
    );
  }
}

class _CustomersTab extends StatefulWidget {
  const _CustomersTab();

  @override
  State<_CustomersTab> createState() => _CustomersTabState();
}

class _CustomersTabState extends State<_CustomersTab> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      Padding(
        padding: const EdgeInsets.all(12),
        child: TextField(
          decoration: const InputDecoration(hintText: 'Search name or code', prefixIcon: Icon(Icons.search)),
          onChanged: (v) => setState(() => _query = v),
        ),
      ),
      Expanded(
        child: _Reloading<List<Customer>>(
          load: (app) => app.repository!.customers(query: _query),
          builder: (context, customers) => customers.isEmpty
              ? ListView(children: const [EmptyState(icon: Icons.storefront_outlined, message: 'No customers assigned to you yet. Pull down to sync.')])
              : ListView.builder(
                  itemCount: customers.length,
                  itemBuilder: (_, i) {
                    final c = customers[i];
                    return Card(
                      child: ListTile(
                        leading: CircleAvatar(backgroundColor: AppColors.royal.withValues(alpha: 0.1), child: const Icon(Icons.storefront, color: AppColors.royal)),
                        title: Text(c.name),
                        subtitle: Text([c.code, c.city, if (c.lastVisitAt != null) 'last visit ${DateFormat.yMMMd().format(c.lastVisitAt!.toLocal())}'].whereType<String>().join(' · ')),
                        trailing: c.hasLocation ? null : const Tooltip(message: 'No location on file', child: Icon(Icons.location_off_outlined, size: 18)),
                        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => CustomerScreen(customerId: c.id))),
                      ),
                    );
                  },
                ),
        ),
      ),
    ]);
  }
}

class _VisitsTab extends StatelessWidget {
  const _VisitsTab();

  @override
  Widget build(BuildContext context) => _Reloading<List<LocalVisit>>(
        load: (app) => app.repository!.visits(),
        builder: (context, visits) => visits.isEmpty
            ? ListView(children: const [EmptyState(icon: Icons.pin_drop_outlined, message: 'No visits yet. Open a customer to check in.')])
            : ListView(children: [
                for (final v in visits)
                  Card(
                    child: ListTile(
                      title: Text(v.customerName ?? v.customerId),
                      subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('${v.number ?? 'Not yet numbered'} · ${DateFormat.yMMMd().add_Hm().format(v.checkIn.occurredAt.toLocal())}'),
                        const SizedBox(height: 4),
                        Wrap(spacing: 6, runSpacing: 4, children: [
                          StatusChip(describeVisit(v)),
                          StatusChip(describeVerification(v.checkInVerification, synced: v.serverId != null)),
                        ]),
                      ]),
                      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => VisitScreen(reference: v.clientReference))),
                    ),
                  ),
              ]),
      );
}

class _OrdersTab extends StatelessWidget {
  const _OrdersTab();

  @override
  Widget build(BuildContext context) => _Reloading<List<LocalOrder>>(
        load: (app) => app.repository!.orders(),
        builder: (context, orders) => orders.isEmpty
            ? ListView(children: const [EmptyState(icon: Icons.receipt_long_outlined, message: 'No orders yet. Open a customer to capture one.')])
            : ListView(children: [
                for (final o in orders)
                  Card(
                    child: ListTile(
                      title: Text(o.customerName ?? o.customerId),
                      subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('${o.number ?? 'Not yet numbered'} · ${o.lines.length} line(s) · captured ${DateFormat.yMMMd().add_Hm().format(o.capturedAt.toLocal())}'),
                        const SizedBox(height: 4),
                        StatusChip(describeOrder(o)),
                      ]),
                      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => OrderScreen(customerId: o.customerId, reference: o.clientReference))),
                    ),
                  ),
              ]),
      );
}

class _FollowUpsTab extends StatelessWidget {
  const _FollowUpsTab();

  @override
  Widget build(BuildContext context) => _Reloading<List<FollowUp>>(
        load: (app) => app.repository!.followUps(),
        builder: (context, items) => items.isEmpty
            ? ListView(children: const [EmptyState(icon: Icons.task_alt, message: 'No open follow-ups.')])
            : ListView(children: [
                for (final f in items)
                  Card(
                    child: ListTile(
                      leading: Icon(Icons.flag_outlined, color: f.dueOn.isBefore(DateTime.now()) ? AppColors.danger : AppColors.royal),
                      title: Text(f.title),
                      subtitle: Text('Due ${DateFormat.yMMMd().format(f.dueOn)}${f.localStatus == 'done' ? ' · completed, waiting to sync' : ''}${f.lastError != null ? ' · ${f.lastError}' : ''}'),
                      trailing: f.localStatus == 'done' ? const Icon(Icons.cloud_upload_outlined) : TextButton(onPressed: () => _complete(context, f), child: const Text('Done')),
                    ),
                  ),
              ]),
      );

  Future<void> _complete(BuildContext context, FollowUp f) async {
    final app = context.read<AppState>();
    final controller = TextEditingController();
    final outcome = await showDialog<String>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(f.title),
        content: TextField(controller: controller, decoration: const InputDecoration(labelText: 'Outcome'), maxLines: 3),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: const Text('Complete')),
        ],
      ),
    );
    if (outcome == null || outcome.isEmpty) return;
    await app.repository!.completeFollowUp(f.id, outcome);
    await app.changed();
    await app.syncNow();
  }
}

/// Items the server refused: shown with the reason so nothing disappears silently.
class NeedsAttentionScreen extends StatelessWidget {
  const NeedsAttentionScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return Scaffold(
      appBar: AppBar(title: const Text('Needs attention')),
      body: FutureBuilder(
        future: app.repository!.outbox.failed(),
        builder: (context, snap) {
          final items = snap.data ?? const [];
          if (items.isEmpty) return const EmptyState(icon: Icons.check_circle_outline, message: 'Nothing needs attention.');
          return ListView(children: [
            for (final item in items)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.error_outline, color: AppColors.danger),
                  title: Text(item.operation.replaceAll('_', ' ')),
                  subtitle: Text(item.lastError ?? ''),
                  trailing: TextButton(
                    onPressed: () async {
                      await app.repository!.outbox.dismiss(item.id);
                      await app.changed();
                    },
                    child: const Text('Dismiss'),
                  ),
                ),
              ),
          ]);
        },
      ),
    );
  }
}
