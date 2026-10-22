// Renders real screens of the field force app (the production widgets, with synthetic data)
// to PNG files for the public landing page. Run on demand:
//
//   FIELD_SCREENSHOTS=1 flutter test test/screenshots/landing_screenshots_test.dart
//
// Output: ../../public/images/landing/app-*.png (phone size 390 x 844 logical pixels at 3x).
// The landing page serves 2x WebP copies; after rendering, convert and remove the PNGs (PHP GD), from the repository root:
//   php -r 'foreach (glob("public/images/landing/app-*.png") as $f) { $s = imagecreatefrompng($f); $d = imagescale($s, 780); imagewebp($d, substr($f, 0, -3)."webp", 86); unlink($f); }'
// Skipped in normal test runs. All names and figures are synthetic demo data.

import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:pharma_field/core/session.dart';
import 'package:pharma_field/data/models.dart';
import 'package:pharma_field/state/app_state.dart';
import 'package:pharma_field/ui/customer_screen.dart';
import 'package:pharma_field/ui/home_screen.dart';
import 'package:pharma_field/ui/theme.dart';
import 'package:pharma_field/ui/visit_screen.dart';
import 'package:provider/provider.dart';
import 'package:sqflite_sqlcipher/sqflite.dart' show ConflictAlgorithm;

import '../support/fakes.dart';

final _enabled = Platform.environment['FIELD_SCREENSHOTS'] == '1';
final _out = p.normalize(p.join(Directory.current.path, '..', '..', 'public', 'images', 'landing'));

/// Real fonts instead of the test font, so text renders as it does on a phone.
Future<void> _loadFonts() async {
  final fonts = p.join(Platform.environment['FLUTTER_ROOT'] ?? r'C:\flutter', 'bin', 'cache', 'artifacts', 'material_fonts');
  Future<ByteData> read(String f) async => ByteData.view(Uint8List.fromList(await File(p.join(fonts, f)).readAsBytes()).buffer);
  final roboto = FontLoader('Roboto')
    ..addFont(read('roboto-regular.ttf'))
    ..addFont(read('roboto-medium.ttf'))
    ..addFont(read('roboto-bold.ttf'));
  await roboto.load();
  await (FontLoader('MaterialIcons')..addFont(read('materialicons-regular.otf'))).load();
}

Map<String, dynamic> _myDay() => {
      'data': {
        'date': DateTime.now().toIso8601String().substring(0, 10),
        'calls': [
          {'id': 'I1', 'customer_id': 'C1', 'customer_name': 'Lekki Medical Centre', 'city': 'Lagos', 'sequence': 1, 'objective': 'Detail Amoxicillin 500 mg', 'status': 'visited', 'plan_status': 'approved'},
          {'id': 'I2', 'customer_id': 'C2', 'customer_name': 'Ikoyi Community Pharmacy', 'city': 'Lagos', 'sequence': 2, 'objective': 'Restock antimalarials', 'status': 'visited', 'plan_status': 'approved'},
          {'id': 'I3', 'customer_id': 'C3', 'customer_name': 'Yaba Family Clinic', 'city': 'Lagos', 'sequence': 3, 'objective': 'Collect overdue payment', 'status': 'planned', 'plan_status': 'approved'},
          {'id': 'I4', 'customer_id': 'C4', 'customer_name': 'Surulere Health Store', 'city': 'Lagos', 'sequence': 4, 'objective': 'Introduce cold-chain vaccines', 'status': 'planned', 'plan_status': 'approved'},
          {'id': 'I5', 'customer_id': 'C5', 'customer_name': 'Ikeja General Hospital', 'city': 'Lagos', 'sequence': 5, 'objective': 'Hospital tender follow-up', 'status': 'planned', 'plan_status': 'approved'},
        ],
        'follow_ups': [],
        'detailing_messages': [
          {'id': 'M1', 'product_id': 'P1', 'title': 'Dosing', 'key_message': 'Twice daily with food.', 'valid_until': null},
        ],
      },
    };

Future<(AppState, String)> _state() async {
  final (repo, api, _) = await fieldSetup();
  api.handler = (method, path, body) => _myDay();
  await repo.refreshMyDay();
  final customers = [
    Customer(id: 'C1', name: 'Lekki Medical Centre', code: 'LMC-014', type: 'clinic', city: 'Lagos', address: '12 Admiralty Way, Lekki', phone: '+234 803 555 0142',
        latitude: 6.4281, longitude: 3.4219, currencyCode: 'NGN'),
    Customer(id: 'C2', name: 'Ikoyi Community Pharmacy', code: 'ICP-007', type: 'pharmacy', city: 'Lagos', latitude: 6.45, longitude: 3.43, currencyCode: 'NGN'),
    Customer(id: 'C3', name: 'Yaba Family Clinic', code: 'YFC-021', type: 'clinic', city: 'Lagos', latitude: 6.51, longitude: 3.38, currencyCode: 'NGN'),
  ];
  for (final c in customers) {
    await repo.local.db.insert('customers', c.toRow(const {}), conflictAlgorithm: ConflictAlgorithm.replace);
  }
  final order = await repo.saveOrderDraft(customer: customers[0], lines: [
    OrderLine(productId: 'P1', productName: 'Amoxicillin 500 mg Capsule', packageLevelCode: 'pack', quantity: '20', indicativeUnitPrice: '3000'),
    OrderLine(productId: 'P2', productName: 'Artemether/Lumefantrine 80/480 mg', packageLevelCode: 'pack', quantity: '12', indicativeUnitPrice: '4500'),
  ]);
  await repo.queueOrder(order.clientReference);
  final visit = await repo.checkIn(customers[0], visitType: 'detailing', objectives: 'Detail Amoxicillin 500 mg');

  final state = AppState(sessionStore: MemorySessionStore())
    ..session = Session(
      baseUrl: 'https://demo.example',
      token: 't',
      userId: 7,
      userName: 'Chiamaka Obi · Sales representative',
      organizationId: 'O1',
      organizationName: 'Demo Pharma Distribution Ltd',
      mode: 'field',
      deviceId: 'D1',
      permissions: const ['field_force.visits.capture', 'field_force.orders.capture', 'field_force.competitor.record', 'field_force.reports.view'],
    )
    ..repository = repo;
  return (state, visit.clientReference);
}

Future<void> _shoot(WidgetTester tester, AppState state, Widget screen, String file) async {
  final key = GlobalKey();
  await tester.pumpWidget(ChangeNotifierProvider.value(
    value: state,
    child: RepaintBoundary(key: key, child: MaterialApp(debugShowCheckedModeBanner: false, theme: buildTheme(), home: screen)),
  ));
  // Database reads are real I/O: let them finish, then rebuild.
  for (var i = 0; i < 6; i++) {
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 150)));
    await tester.pump(const Duration(milliseconds: 50));
  }
  await tester.runAsync(() async {
    final boundary = key.currentContext!.findRenderObject()! as RenderRepaintBoundary;
    final image = await boundary.toImage(pixelRatio: 3);
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    Directory(_out).createSync(recursive: true);
    File(p.join(_out, file)).writeAsBytesSync(bytes!.buffer.asUint8List());
  });
}

void main() {
  setUpAll(() async {
    if (_enabled) await _loadFonts();
  });

  testWidgets('field force app screens for the landing page', (tester) async {
    tester.view.physicalSize = const Size(390 * 3, 844 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    final (state, visitRef) = (await tester.runAsync(_state))!;
    await _shoot(tester, state, const HomeScreen(), 'app-my-day.png');
    await _shoot(tester, state, const CustomerScreen(customerId: 'C1'), 'app-customer.png');
    await _shoot(tester, state, VisitScreen(reference: visitRef), 'app-visit.png');

    for (final f in ['app-my-day.png', 'app-customer.png', 'app-visit.png']) {
      expect(File(p.join(_out, f)).lengthSync(), greaterThan(20000), reason: f);
    }
  }, skip: !_enabled);
}
