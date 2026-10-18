import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pharma_field/core/session.dart';
import 'package:pharma_field/data/models.dart';
import 'package:pharma_field/data/status_text.dart';
import 'package:pharma_field/main.dart';
import 'package:pharma_field/state/app_state.dart';
import 'package:pharma_field/ui/widgets.dart';
import 'package:provider/provider.dart';

LocalOrder _order({String localState = 'sent', String? serverStatus, bool reserved = false}) => LocalOrder(
      clientReference: 'r',
      customerId: 'c',
      capturedAt: DateTime(2026, 10, 18),
      lines: const [],
      localState: localState,
      syncState: 'synced',
      serverStatus: serverStatus,
      salesOrder: serverStatus == 'accepted' ? {'number': 'SO-1', 'status': reserved ? 'confirmed' : 'pending_approval'} : null,
      stockReserved: reserved,
    );

void main() {
  test('order wording never presents a captured order as accepted', () {
    expect(describeOrder(_order(localState: 'draft')).label, 'Draft on this device');
    expect(describeOrder(_order(localState: 'queued')).label, 'Waiting to sync');
    expect(describeOrder(_order(serverStatus: 'submitted')).detail, contains('Nothing is reserved'));
    expect(describeOrder(_order(serverStatus: 'accepted', reserved: true)).detail, contains('stock is reserved'));
    expect(describeOrder(_order(serverStatus: 'accepted')).detail, contains('not reserved'));
  });

  testWidgets('status banner shows the label and the explanation', (tester) async {
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: StatusBanner(describeOrder(_order(localState: 'queued'))))));
    expect(find.text('Waiting to sync'), findsOneWidget);
    expect(find.textContaining('Nothing is reserved yet'), findsOneWidget);
  });

  testWidgets('without a stored session the app opens on the sign-in screen', (tester) async {
    final state = AppState(sessionStore: MemorySessionStore());
    await state.restore();
    await tester.pumpWidget(ChangeNotifierProvider.value(value: state, child: const FieldApp()));
    await tester.pumpAndSettle();
    expect(find.text('Sign in'), findsWidgets);
    expect(find.text('FIELD FORCE'), findsOneWidget);
  });
}
