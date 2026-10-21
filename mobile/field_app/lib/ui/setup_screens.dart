import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../state/app_state.dart';
import 'theme.dart';

/// One list of choices after sign-in (organization, mode or terminal).
class _ChoiceScreen extends StatelessWidget {
  const _ChoiceScreen({required this.title, required this.intro, required this.children});

  final String title;
  final String intro;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return Scaffold(
      appBar: AppBar(title: Text(title), actions: [IconButton(onPressed: app.signOut, icon: const Icon(Icons.logout), tooltip: 'Sign out')]),
      body: ListView(children: [
        Padding(padding: const EdgeInsets.all(16), child: Text(intro)),
        if (app.error != null) Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: Text(app.error!, style: const TextStyle(color: AppColors.danger))),
        if (app.busy) const LinearProgressIndicator(),
        ...children,
      ]),
    );
  }
}

class OrganizationPicker extends StatelessWidget {
  const OrganizationPicker({super.key});

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return _ChoiceScreen(title: 'Choose organization', intro: 'Which organization are you working for?', children: [
      for (final org in app.organizationChoices)
        Card(child: ListTile(leading: const Icon(Icons.business_outlined), title: Text(org.name), trailing: const Icon(Icons.chevron_right), onTap: app.busy ? null : () => app.selectOrganization(org))),
    ]);
  }
}

class ModePicker extends StatelessWidget {
  const ModePicker({super.key});

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    const labels = {
      'field': ('Field force', 'Customer visits, orders and follow-ups', Icons.route_outlined),
      'pos': ('Point of sale', 'Sell at a till, also without a connection', Icons.point_of_sale),
    };
    return _ChoiceScreen(title: 'How will you use this device?', intro: 'The device is registered for one role. You can sign out and register it again for the other.', children: [
      for (final mode in app.modeChoices)
        Card(
          child: ListTile(
            leading: Icon(labels[mode]!.$3, color: AppColors.royal),
            title: Text(labels[mode]!.$1),
            subtitle: Text(labels[mode]!.$2),
            onTap: app.busy ? null : () => app.selectMode(mode),
          ),
        ),
    ]);
  }
}

class TerminalPicker extends StatelessWidget {
  const TerminalPicker({super.key});

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    return _ChoiceScreen(title: 'Choose the till', intro: 'This device will be registered to the terminal you choose. Its sales sync to that terminal\'s shifts.', children: [
      for (final t in app.terminalChoices)
        Card(child: ListTile(leading: const Icon(Icons.point_of_sale), title: Text(t.name), onTap: app.busy ? null : () => app.selectTerminal(t))),
    ]);
  }
}
