import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'core/session.dart';
import 'state/app_state.dart';
import 'ui/home_screen.dart';
import 'ui/login_screen.dart';
import 'ui/theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final state = AppState(sessionStore: SecureSessionStore())..restore();
  runApp(ChangeNotifierProvider.value(value: state, child: const FieldApp()));
}

class FieldApp extends StatelessWidget {
  const FieldApp({super.key});

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
    if (app.workspaceReady) return const HomeScreen();
    if (app.signedIn && app.organizationChoices.isNotEmpty) return const OrganizationPicker();
    return const LoginScreen();
  }
}
