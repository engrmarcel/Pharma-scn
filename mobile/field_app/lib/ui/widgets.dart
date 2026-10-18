import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../data/status_text.dart';
import '../state/app_state.dart';
import 'theme.dart';

Color toneColor(StatusTone tone) => switch (tone) {
      StatusTone.success => AppColors.success,
      StatusTone.warning => AppColors.warning,
      StatusTone.danger => AppColors.danger,
      StatusTone.pending => AppColors.royal,
      StatusTone.neutral => Colors.blueGrey,
    };

class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key});

  final StatusView status;

  @override
  Widget build(BuildContext context) {
    final color = toneColor(status.tone);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(20), border: Border.all(color: color.withValues(alpha: 0.4))),
      child: Text(status.label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }
}

/// Explains a status in full: used where the difference matters (orders).
class StatusBanner extends StatelessWidget {
  const StatusBanner(this.status, {super.key});

  final StatusView status;

  @override
  Widget build(BuildContext context) {
    final color = toneColor(status.tone);
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.all(12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(10), border: Border.all(color: color.withValues(alpha: 0.35))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(status.label, style: TextStyle(color: color, fontWeight: FontWeight.bold)),
        if (status.detail != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(status.detail!)),
      ]),
    );
  }
}

/// Pending-change count and a manual sync trigger.
class SyncButton extends StatelessWidget {
  const SyncButton({super.key});

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final count = app.pending + app.needsAttention;
    return IconButton(
      tooltip: app.syncing ? 'Syncing…' : '${app.pending} waiting to sync, ${app.needsAttention} need attention',
      onPressed: app.syncing ? null : () => app.syncNow(),
      icon: Badge(
        isLabelVisible: count > 0,
        backgroundColor: app.needsAttention > 0 ? AppColors.danger : AppColors.gold,
        textColor: app.needsAttention > 0 ? Colors.white : AppColors.navy,
        label: Text('$count'),
        child: app.syncing
            ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
            : const Icon(Icons.sync),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.message});

  final IconData icon;
  final String message;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, size: 48, color: Colors.blueGrey.shade300),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center, style: TextStyle(color: Colors.blueGrey.shade600)),
          ]),
        ),
      );
}

void showMessage(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message), backgroundColor: error ? AppColors.danger : null));
}

/// Shown before the first check-in and from the menu (spec §12.2: transparent notice).
Future<void> showLocationNotice(BuildContext context, String notice) => showDialog(
      context: context,
      builder: (_) => AlertDialog(
        icon: const Icon(Icons.location_on_outlined),
        title: const Text('How location is used'),
        content: Text('$notice\n\nIf location is off or unavailable, the visit is still recorded and marked as unverified. Coordinates are never estimated.'),
        actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('OK'))],
      ),
    );
