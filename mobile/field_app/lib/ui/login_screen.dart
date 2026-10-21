import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/config.dart';
import '../state/app_state.dart';
import 'theme.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _server = TextEditingController(text: AppConfig.defaultBaseUrl);
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _mfa = TextEditingController();
  bool _showServer = false;
  bool _obscure = true;

  @override
  void dispose() {
    for (final c in [_server, _email, _password, _mfa]) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final needsMfa = app.error?.contains('authenticator') ?? false;

    return Scaffold(
      backgroundColor: AppColors.navy,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(children: [
              const Icon(Icons.hub_outlined, size: 56, color: AppColors.gold),
              const SizedBox(height: 12),
              const Text('Pharma Network', style: TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.bold)),
              const Text('FIELD FORCE', style: TextStyle(color: AppColors.gold, letterSpacing: 4, fontSize: 12)),
              const SizedBox(height: 28),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: AutofillGroup(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      const Text('Sign in', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
                      const SizedBox(height: 16),
                      TextField(
                        controller: _email,
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [AutofillHints.username],
                        decoration: const InputDecoration(labelText: 'Email', prefixIcon: Icon(Icons.mail_outline)),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _password,
                        obscureText: _obscure,
                        autofillHints: const [AutofillHints.password],
                        decoration: InputDecoration(
                          labelText: 'Password',
                          prefixIcon: const Icon(Icons.key_outlined),
                          suffixIcon: IconButton(icon: Icon(_obscure ? Icons.visibility : Icons.visibility_off), onPressed: () => setState(() => _obscure = !_obscure)),
                        ),
                      ),
                      if (needsMfa) ...[
                        const SizedBox(height: 12),
                        TextField(controller: _mfa, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Authentication code', prefixIcon: Icon(Icons.phonelink_lock))),
                      ],
                      if (_showServer) ...[
                        const SizedBox(height: 12),
                        TextField(controller: _server, keyboardType: TextInputType.url, decoration: const InputDecoration(labelText: 'Server address', prefixIcon: Icon(Icons.dns_outlined))),
                      ],
                      if (app.error != null) ...[
                        const SizedBox(height: 12),
                        Text(app.error!, style: const TextStyle(color: AppColors.danger)),
                      ],
                      const SizedBox(height: 20),
                      FilledButton.icon(
                        onPressed: app.busy
                            ? null
                            : () => app.signIn(baseUrl: _server.text, email: _email.text, password: _password.text, mfaCode: _mfa.text),
                        icon: app.busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.login),
                        label: const Text('Sign in'),
                      ),
                      TextButton(onPressed: () => setState(() => _showServer = !_showServer), child: Text(_showServer ? 'Hide server address' : 'Server address')),
                    ]),
                  ),
                ),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}
