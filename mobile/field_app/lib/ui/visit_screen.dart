import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../data/models.dart';
import '../data/status_text.dart';
import '../state/app_state.dart';
import 'crm_widgets.dart';
import 'customer_screen.dart';
import 'order_screen.dart';
import 'widgets.dart';

const outcomes = {
  'successful': 'Successful',
  'partially_successful': 'Partially successful',
  'unsuccessful': 'Unsuccessful',
  'customer_unavailable': 'Customer unavailable',
  'rescheduled': 'Rescheduled',
};

const activityTypes = {
  'detailing': 'Product detailing',
  'sample_request': 'Sample request',
  'order_request': 'Order request',
  'complaint': 'Complaint',
  'stock_check': 'Stock check',
  'note': 'Note',
};

class VisitScreen extends StatefulWidget {
  const VisitScreen({super.key, required this.reference});

  final String reference;

  @override
  State<VisitScreen> createState() => _VisitScreenState();
}

class _VisitScreenState extends State<VisitScreen> {
  final _notes = TextEditingController();
  final _feedback = TextEditingController();
  final _competitor = TextEditingController();
  final _nextAction = TextEditingController();
  bool _opportunity = false;
  String _outcome = 'successful';
  DateTime? _followUp;
  List<Map<String, dynamic>> _activities = [];
  LocalVisit? _visit;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final repo = context.read<AppState>().repository!;
    final v = await repo.visit(widget.reference);
    final acts = await repo.visitActivities(widget.reference);
    if (!mounted || v == null) return;
    setState(() {
      _visit = v;
      _activities = acts;
      _notes.text = v.details['discussion_notes'] as String? ?? '';
      _feedback.text = v.details['customer_feedback'] as String? ?? '';
      _competitor.text = v.details['competitor_information'] as String? ?? '';
      _nextAction.text = v.details['next_action'] as String? ?? '';
      _opportunity = v.details['order_opportunity'] as bool? ?? false;
      _followUp = v.details['follow_up_date'] == null ? null : DateTime.tryParse(v.details['follow_up_date'] as String);
    });
  }

  Map<String, dynamic> _details() => {
        'discussion_notes': _notes.text.trim(),
        'customer_feedback': _feedback.text.trim(),
        if (_competitor.text.trim().isNotEmpty) 'competitor_information': _competitor.text.trim(),
        'order_opportunity': _opportunity,
        'next_action': _nextAction.text.trim(),
        if (_followUp != null) 'follow_up_date': DateFormat('yyyy-MM-dd').format(_followUp!),
      };

  Future<void> _save() => context.read<AppState>().repository!.saveVisitDetails(widget.reference, _details(), _activities);

  @override
  void dispose() {
    for (final c in [_notes, _feedback, _competitor, _nextAction]) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final v = _visit;
    if (v == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    final open = v.status == 'in_progress' && v.syncState != 'failed';
    final canCompetitor = app.session!.can('field_force.competitor.record');

    return PopScope(
      onPopInvokedWithResult: (didPop, _) {
        if (open) _save();
      },
      child: Scaffold(
        appBar: AppBar(title: Text(v.customerName ?? 'Visit')),
        body: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${visitTypes[v.visitType] ?? v.visitType} · ${v.number ?? 'not yet numbered'}', style: const TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                Wrap(spacing: 6, runSpacing: 6, children: [StatusChip(describeVisit(v))]),
                const Divider(height: 24),
                _evidence('Check-in', v.checkIn.occurredAt, v.checkIn.describe(), describeVerification(v.checkInVerification, synced: v.serverId != null)),
                if (v.checkOut != null)
                  _evidence('Check-out', v.checkOut!.occurredAt, v.checkOut!.describe(), describeVerification(v.checkOutVerification, synced: v.syncState == 'synced')),
                if (v.lastError != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(v.lastError!, style: const TextStyle(color: Colors.red))),
              ]),
            ),
          ),
          if (open) ...[
            _field(_notes, 'Discussion notes', lines: 4),
            _field(_feedback, 'Customer feedback', lines: 2),
            if (canCompetitor) _field(_competitor, 'Competitor information', lines: 2),
            SwitchListTile(title: const Text('Order opportunity'), value: _opportunity, onChanged: (x) => setState(() => _opportunity = x)),
            _field(_nextAction, 'Next action'),
            ListTile(
              leading: const Icon(Icons.event_outlined),
              title: Text(_followUp == null ? 'Set a follow-up date' : 'Follow up on ${DateFormat.yMMMd().format(_followUp!)}'),
              subtitle: const Text('Creates a follow-up task when you check out'),
              onTap: () async {
                final picked = await showDatePicker(context: context, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)), initialDate: _followUp ?? DateTime.now());
                if (picked != null) setState(() => _followUp = picked);
              },
            ),
            ListTile(
              leading: const Icon(Icons.playlist_add),
              title: Text('Activities (${_activities.length})'),
              subtitle: Text(_activities.map((a) => activityTypes[a['activity_type']] ?? a['activity_type']).join(', ')),
              trailing: const Icon(Icons.add),
              onTap: _addActivity,
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                OutlinedButton.icon(
                  onPressed: app.session!.can('field_force.orders.capture')
                      ? () async {
                          await _save();
                          if (!context.mounted) return;
                          await Navigator.push(context, MaterialPageRoute(builder: (_) => OrderScreen(customerId: v.customerId, visitReference: v.clientReference)));
                        }
                      : null,
                  icon: const Icon(Icons.add_shopping_cart),
                  label: const Text('Capture an order'),
                ),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  onPressed: () => showDetailingSheet(context, visitReference: v.clientReference, customerId: v.customerId),
                  icon: const Icon(Icons.campaign_outlined),
                  label: const Text('Detail a product'),
                ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: () => showFeedbackDialog(context, customerId: v.customerId, visitReference: v.clientReference),
                  icon: const Icon(Icons.feedback_outlined),
                  label: const Text('Record feedback'),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: _outcome,
                  decoration: const InputDecoration(labelText: 'Outcome'),
                  items: [for (final e in outcomes.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
                  onChanged: (x) => setState(() => _outcome = x ?? _outcome),
                ),
                const SizedBox(height: 12),
                FilledButton.icon(onPressed: _checkOut, icon: const Icon(Icons.logout), label: const Text('Check out')),
                TextButton(onPressed: _cancel, child: const Text('Cancel visit')),
              ]),
            ),
          ] else if (v.details.isNotEmpty)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  for (final e in v.details.entries.where((e) => e.value != null && '${e.value}'.isNotEmpty))
                    Padding(padding: const EdgeInsets.only(bottom: 6), child: Text('${e.key.replaceAll('_', ' ')}: ${e.value}')),
                ]),
              ),
            ),
        ]),
      ),
    );
  }

  Widget _evidence(String label, DateTime at, String device, StatusView verification) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(children: [
          Expanded(child: Text('$label ${DateFormat.Hm().format(at.toLocal())}\n$device', style: const TextStyle(fontSize: 13))),
          StatusChip(verification),
        ]),
      );

  Widget _field(TextEditingController c, String label, {int lines = 1}) => Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 4),
        child: TextField(controller: c, maxLines: lines, decoration: InputDecoration(labelText: label), onChanged: (_) => _save()),
      );

  Future<void> _addActivity() async {
    var type = 'detailing';
    final notes = TextEditingController();
    final add = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: const Text('Add activity'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String>(
              initialValue: type,
              items: [for (final e in activityTypes.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
              onChanged: (v) => setState(() => type = v ?? type),
            ),
            const SizedBox(height: 12),
            TextField(controller: notes, decoration: const InputDecoration(labelText: 'Notes'), maxLines: 2),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Add')),
          ],
        ),
      ),
    );
    if (add != true) return;
    setState(() => _activities = [
          ..._activities,
          {'activity_type': type, if (notes.text.trim().isNotEmpty) 'notes': notes.text.trim(), 'recorded_at': DateTime.now().toUtc().toIso8601String()},
        ]);
    await _save();
  }

  Future<void> _checkOut() async {
    final app = context.read<AppState>();
    await _save();
    try {
      final v = await app.repository!.checkOut(widget.reference, outcome: _outcome);
      await app.changed();
      if (!mounted) return;
      showMessage(context, 'Checked out. ${v.checkOut?.describe() ?? ''}');
      app.syncNow();
      Navigator.pop(context);
    } on StateError catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }

  Future<void> _cancel() async {
    final app = context.read<AppState>();
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Cancel this visit?'),
        content: TextField(controller: reason, decoration: const InputDecoration(labelText: 'Reason')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep visit')),
          FilledButton(onPressed: () => Navigator.pop(context, reason.text.trim().isNotEmpty), child: const Text('Cancel visit')),
        ],
      ),
    );
    if (ok != true) return;
    await app.repository!.cancelVisit(widget.reference, reason.text.trim());
    await app.changed();
    app.syncNow();
    if (mounted) Navigator.pop(context);
  }
}
