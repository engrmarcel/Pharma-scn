import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'core/session.dart';
import 'state/app_state.dart';
import 'ui/home_screen.dart';
import 'ui/login_screen.dart';
import 'ui/pos_screens.dart';
import 'ui/setup_screens.dart';
import 'ui/theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final state = AppState(sessionStore: SecureSessionStore())..restore();
  runApp(ChangeNotifierProvider.value(value: state, child: const FieldApp()));
}

class FieldApp extends StatefulWidget {
  const FieldApp({super.key});

  @override
  State<FieldApp> createState() => _FieldAppState();
}

class _FieldAppState extends State<FieldApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Coming back to the foreground is a good moment to send queued work.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) context.read<AppState>().resumed();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
        title: 'Pharma Network Field',
        theme: buildTheme(),
        debugShowCheckedModeBanner: false,
        home: const _Root(),
      );
}

class _Root extends StatelessWidget {
  const _Root();

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    if (!app.ready) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (app.workspaceReady) return app.isPos ? const PosHomeScreen() : const HomeScreen();
    if (app.signedIn && app.organizationChoices.isNotEmpty) return const OrganizationPicker();
    if (app.signedIn && app.modeChoices.isNotEmpty) return const ModePicker();
    if (app.signedIn && app.terminalChoices.isNotEmpty) return const TerminalPicker();
    return const LoginScreen();
  }
}
