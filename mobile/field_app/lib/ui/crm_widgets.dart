import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../data/crm_models.dart';
import '../state/app_state.dart';
import 'customer_screen.dart';
import 'theme.dart';
import 'widgets.dart';

/// Today's planned calls (from the representative's submitted or approved plan), in route order.
class MyDayTab extends StatelessWidget {
  const MyDayTab({super.key});

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return FutureBuilder<List<PlanCall>>(
      future: app.repository!.myDay(),
      builder: (context, snap) {
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final calls = snap.data!;
        return RefreshIndicator(
          onRefresh: app.syncNow,
          child: calls.isEmpty
              ? ListView(children: const [EmptyState(icon: Icons.route_outlined, message: 'No planned calls today. Visit plans are prepared and approved in the CRM; pull down to refresh.')])
              : ListView(children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                    child: Text('${calls.where((c) => c.status == 'visited').length} of ${calls.length} planned calls done',
                        style: const TextStyle(fontWeight: FontWeight.w600, color: AppColors.navy)),
                  ),
                  for (final c in calls)
                    Card(
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: (c.status == 'visited' ? AppColors.success : AppColors.royal).withValues(alpha: 0.12),
                          child: c.status == 'visited' ? const Icon(Icons.check, color: AppColors.success) : Text('${c.sequence}', style: const TextStyle(color: AppColors.royal)),
                        ),
                        title: Text(c.customerName),
                        subtitle: Text([c.city, c.objective, if (c.planStatus == 'submitted') 'plan awaiting approval'].whereType<String>().where((s) => s.isNotEmpty).join(' · ')),
                        trailing: c.status == 'missed' ? const Icon(Icons.event_busy, color: AppColors.danger) : const Icon(Icons.chevron_right),
                        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => CustomerScreen(customerId: c.customerId))),
                      ),
                    ),
                ]),
        );
      },
    );
  }
}

/// Records detailing during a visit: product, approved key messages, reception and samples.
Future<void> showDetailingSheet(BuildContext context, {required String visitReference, required String customerId}) async {
  final app = context.read<AppState>();
  final repo = app.repository!;
  final messages = await repo.detailingMessages();
  if (!context.mounted) return;
  if (messages.isEmpty) {
    showMessage(context, 'No approved key messages on this device yet. Sync to download them.', error: true);
    return;
  }
  final names = {for (final i in await repo.catalogue(customerId)) i.productId: i.displayName};
  if (!context.mounted) return;
  final products = messages.map((m) => m.productId).toSet().toList();

  var productId = products.first;
  final chosen = <String>{};
  var reception = 'not_assessed';
  final samples = TextEditingController();
  final batch = TextEditingController();
  final notes = TextEditingController();

  final saved = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (context) => StatefulBuilder(
      builder: (context, setState) => Padding(
        padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(context).viewInsets.bottom + 16),
        child: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('Detail a product', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            const Text('Only approved key messages can be recorded.', style: TextStyle(fontSize: 12, color: Colors.blueGrey)),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: productId,
              decoration: const InputDecoration(labelText: 'Product'),
              items: [for (final p in products) DropdownMenuItem(value: p, child: Text(names[p] ?? messages.firstWhere((m) => m.productId == p).title))],
              onChanged: (v) => setState(() {
                productId = v ?? productId;
                chosen.clear();
              }),
            ),
            for (final m in messages.where((m) => m.productId == productId))
              CheckboxListTile(
                value: chosen.contains(m.id),
                title: Text(m.title),
                subtitle: Text(m.keyMessage),
                onChanged: (on) => setState(() => on == true ? chosen.add(m.id) : chosen.remove(m.id)),
              ),
            DropdownButtonFormField<String>(
              initialValue: reception,
              decoration: const InputDecoration(labelText: 'Reception'),
              items: [for (final e in receptions.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
              onChanged: (v) => setState(() => reception = v ?? reception),
            ),
            Row(children: [
              Expanded(child: TextField(controller: samples, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Samples given'))),
              const SizedBox(width: 12),
              Expanded(child: TextField(controller: batch, decoration: const InputDecoration(labelText: 'Sample batch'))),
            ]),
            TextField(controller: notes, decoration: const InputDecoration(labelText: 'Notes (no patient details)'), maxLines: 2),
            const SizedBox(height: 16),
            FilledButton(onPressed: chosen.isEmpty ? null : () => Navigator.pop(context, true), child: const Text('Record detailing')),
          ]),
        ),
      ),
    ),
  );
  if (saved != true) return;

  try {
    await repo.recordDetailing(visitReference,
        productId: productId,
        productName: names[productId],
        messageIds: chosen.toList(),
        reception: reception,
        samplesQuantity: samples.text.trim(),
        sampleBatchNumber: batch.text.trim(),
        notes: notes.text.trim());
    await app.changed();
    if (context.mounted) showMessage(context, 'Detailing recorded. It is sent with your visit.');
    app.syncNow();
  } on StateError catch (e) {
    if (context.mounted) showMessage(context, e.message, error: true);
  }
}

/// Records structured customer feedback. A possible adverse event shows the pharmacovigilance notice at once.
Future<void> showFeedbackDialog(BuildContext context, {required String customerId, String? visitReference}) async {
  final app = context.read<AppState>();
  var category = 'service';
  int? rating;
  var adverse = false;
  final description = TextEditingController();

  final saved = await showDialog<bool>(
    context: context,
    builder: (context) => StatefulBuilder(
      builder: (context, setState) => AlertDialog(
        title: const Text('Record feedback'),
        content: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String>(
              initialValue: category,
              decoration: const InputDecoration(labelText: 'Category'),
              items: [for (final e in feedbackCategories.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
              onChanged: (v) => setState(() => category = v ?? category),
            ),
            DropdownButtonFormField<int?>(
              initialValue: rating,
              decoration: const InputDecoration(labelText: 'Rating (optional)'),
              items: [const DropdownMenuItem(value: null, child: Text('None')), for (var i = 1; i <= 5; i++) DropdownMenuItem(value: i, child: Text('$i / 5'))],
              onChanged: (v) => setState(() => rating = v),
            ),
            TextField(controller: description, decoration: const InputDecoration(labelText: 'What did the customer say?'), maxLines: 3),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              value: adverse,
              title: const Text('A patient may have been harmed (possible adverse event)'),
              onChanged: (v) => setState(() => adverse = v ?? false),
            ),
            if (adverse) Text(pharmacovigilanceNotice, style: const TextStyle(color: AppColors.danger, fontSize: 12)),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Record')),
        ],
      ),
    ),
  );
  if (saved != true) return;

  try {
    await app.repository!.recordFeedback(customerId,
        category: category, rating: rating, description: description.text, visitReference: visitReference, possibleAdverseEvent: adverse);
    await app.changed();
    if (context.mounted) showMessage(context, adverse ? pharmacovigilanceNotice : 'Feedback recorded.', error: adverse);
    app.syncNow();
  } on StateError catch (e) {
    if (context.mounted) showMessage(context, e.message, error: true);
  }
}
