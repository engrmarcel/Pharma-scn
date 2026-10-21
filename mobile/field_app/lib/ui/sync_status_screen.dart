import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../data/outbox.dart';
import '../data/status_text.dart';
import '../state/app_state.dart';
import 'theme.dart';
import 'widgets.dart';

const _operationLabels = {
  'visit_check_in': 'Visit check-in',
  'visit_check_out': 'Visit check-out',
  'visit_cancel': 'Visit cancellation',
  'order_capture': 'Order',
  'order_resubmit': 'Order resubmission',
  'follow_up_complete': 'Follow-up completed',
  'pos_sale': 'Sale',
};

StatusView describeSyncState(OutboxItem item) => switch (item.state) {
      SyncState.pendingSync => const StatusView('Waiting to sync', StatusTone.pending),
      SyncState.retryRequired => StatusView('Retrying (${item.attempts} attempt${item.attempts == 1 ? '' : 's'})', StatusTone.warning, item.lastError),
      SyncState.conflict => StatusView('Conflict: needs review', StatusTone.danger, item.lastError),
      SyncState.rejected => StatusView('Rejected: needs review', StatusTone.danger, item.lastError),
    };

/// Spec §13.6: pending counts, last-sync time and failed items, in one place.
class SyncStatusScreen extends StatelessWidget {
  const SyncStatusScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final fmt = DateFormat.yMMMd().add_Hm();
    return Scaffold(
      appBar: AppBar(title: const Text('Sync status'), actions: const [SyncButton()]),
      body: FutureBuilder<List<OutboxItem>>(
        future: app.repository!.outbox.all(),
        builder: (context, snap) {
          final items = snap.data ?? const <OutboxItem>[];
          return ListView(children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  _row(Icons.cloud_done_outlined, 'Last successful sync', app.lastSyncAt == null ? 'Never' : fmt.format(app.lastSyncAt!.toLocal())),
                  _row(Icons.schedule, 'Waiting to sync', '${app.pending}'),
                  _row(Icons.error_outline, 'Needs attention', '${app.needsAttention}', color: app.needsAttention > 0 ? AppColors.danger : null),
                  if (app.nextSyncAt != null) _row(Icons.update, 'Next automatic try', DateFormat.Hms().format(app.nextSyncAt!)),
                  if (app.syncMessage != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(app.syncMessage!)),
                ]),
              ),
            ),
            if (items.isEmpty) const EmptyState(icon: Icons.check_circle_outline, message: 'Everything on this device has reached the server.'),
            for (final item in items)
              Card(
                child: ListTile(
                  title: Text(_operationLabels[item.operation] ?? item.operation),
                  subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('Captured ${fmt.format(item.createdAt.toLocal())}'),
                    const SizedBox(height: 4),
                    StatusChip(describeSyncState(item)),
                    if (item.lastError != null && item.status == 'failed') Padding(padding: const EdgeInsets.only(top: 4), child: Text(item.lastError!)),
                  ]),
                  trailing: item.status == 'failed'
                      ? TextButton(
                          onPressed: () async {
                            await app.repository!.outbox.dismiss(item.id);
                            await app.changed();
                          },
                          child: const Text('Dismiss'),
                        )
                      : null,
                ),
              ),
            const Padding(
              padding: EdgeInsets.all(16),
              child: Text(
                  'Refused items are also kept on the server for your supervisor. Dismissing one here only removes it from this device after you have seen the reason.',
                  style: TextStyle(fontSize: 12, color: Colors.blueGrey)),
            ),
          ]);
        },
      ),
    );
  }

  Widget _row(IconData icon, String label, String value, {Color? color}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Icon(icon, size: 18, color: color ?? AppColors.royal),
          const SizedBox(width: 8),
          Expanded(child: Text(label)),
          Text(value, style: TextStyle(fontWeight: FontWeight.w600, color: color)),
        ]),
      );
}
