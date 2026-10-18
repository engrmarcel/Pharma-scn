import 'dart:io';

import 'package:path/path.dart' as p;
import 'package:pharma_field/core/api.dart';
import 'package:pharma_field/data/field_repository.dart';
import 'package:pharma_field/data/local_db.dart';
import 'package:pharma_field/data/models.dart';
import 'package:pharma_field/location/location_capture.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

typedef Handler = Map<String, dynamic> Function(String method, String path, Map<String, dynamic>? body);

/// Records requests and answers them from [handler]; throws [OfflineException] when [online] is false.
class FakeApi implements Api {
  bool online = true;
  Handler? handler;
  final calls = <String>[];
  final bodies = <Map<String, dynamic>?>[];

  Future<Map<String, dynamic>> _do(String method, String path, Map<String, dynamic>? body) async {
    if (!online) throw OfflineException();
    calls.add('$method $path');
    bodies.add(body);
    return handler!(method, path, body);
  }

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) => _do('GET', path, query);

  @override
  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) => _do('POST', path, body);

  @override
  Future<Map<String, dynamic>> patch(String path, Map<String, dynamic> body) => _do('PATCH', path, body);
}

class FakeLocation implements LocationCapture {
  FakeLocation(this.next);

  LocationEvidence Function() next;

  @override
  Future<LocationEvidence> capture() async => next();
}

LocationEvidence fix() => LocationEvidence(occurredAt: DateTime.now(), status: 'granted', latitude: 6.4281, longitude: 3.4219, accuracyM: 12, mock: false);

LocationEvidence denied() => LocationEvidence(occurredAt: DateTime.now(), status: 'denied');

Future<LocalDb> openTestDb() async {
  sqfliteFfiInit();
  final dir = Directory.systemTemp.createTempSync('field_app_test');
  return LocalDb.open(p.join(dir.path, 'test.db'), factory: databaseFactoryFfi);
}

Future<(FieldRepository, FakeApi, Customer)> fieldSetup({LocationCapture? location}) async {
  final db = await openTestDb();
  final api = FakeApi();
  final repo = FieldRepository(local: db, api: api, location: location ?? FakeLocation(fix), userId: 7, device: () => {'platform': 'test', 'app_version': '1.0.0'});
  final customer = Customer(id: 'C1', name: 'Lekki Clinic', code: 'A', latitude: 6.4281, longitude: 3.4219, currencyCode: 'NGN');
  await db.db.insert('customers', customer.toRow(const {}));
  return (repo, api, customer);
}

OrderLine line([String qty = '2']) => OrderLine(productId: 'P1', productName: 'Amoxicillin 500 mg', packageLevelCode: 'pack', quantity: qty, indicativeUnitPrice: '2500');
