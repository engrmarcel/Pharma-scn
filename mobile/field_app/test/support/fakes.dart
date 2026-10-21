import 'dart:io';

import 'package:path/path.dart' as p;
import 'package:pharma_field/core/api.dart';
import 'package:pharma_field/data/field_repository.dart';
import 'package:pharma_field/data/local_db.dart';
import 'package:pharma_field/data/models.dart';
import 'package:pharma_field/data/pos_repository.dart';
import 'package:pharma_field/location/location_capture.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

typedef Handler = Map<String, dynamic> Function(String method, String path, Map<String, dynamic>? body);

/// What the fake server does with one envelope: return the result (synced) or throw a [Refusal].
typedef EnvelopeHandler = Map<String, dynamic> Function(String type, Map<String, dynamic> payload);

class Refusal implements Exception {
  Refusal(this.status, this.code, this.message);

  final String status; // conflict | rejected
  final String code;
  final String message;
}

/// Records requests; `POST sync/batches` is answered like the real inbox
/// (ADR-0011): idempotent per client_txn_id, acknowledged per envelope.
class FakeApi implements Api {
  bool online = true;
  Handler? handler;
  EnvelopeHandler? onEnvelope;

  /// Simulates a response lost on the way back: the server processes, the device never hears.
  bool loseNextResponse = false;
  final calls = <String>[];
  final bodies = <Map<String, dynamic>?>[];
  final inbox = <String, Map<String, dynamic>>{};
  final processed = <Map<String, dynamic>>[];

  Future<Map<String, dynamic>> _do(String method, String path, Map<String, dynamic>? body) async {
    if (!online) throw OfflineException();
    calls.add('$method $path');
    bodies.add(body);
    if (method == 'POST' && path == 'sync/batches') return _batch(body!);
    return handler!(method, path, body);
  }

  Map<String, dynamic> _batch(Map<String, dynamic> body) {
    final acks = <Map<String, dynamic>>[];
    for (final raw in body['envelopes'] as List) {
      final e = Map<String, dynamic>.from(raw as Map);
      final id = e['client_txn_id'] as String;
      if (inbox.containsKey(id)) {
        acks.add({...inbox[id]!, 'duplicate': true});
        continue;
      }
      Map<String, dynamic> ack;
      try {
        final result = onEnvelope!(e['type'] as String, Map<String, dynamic>.from(e['payload'] as Map));
        if (result['_retry'] == true) {
          acks.add({'client_txn_id': id, 'status': 'retry_required'});
          continue;
        }
        ack = {'client_txn_id': id, 'status': 'synced', 'result': result, if (result['_warnings'] != null) 'warnings': result['_warnings']};
        processed.add(e);
      } on Refusal catch (r) {
        ack = {'client_txn_id': id, 'status': r.status, 'error': {'code': r.code, 'message': r.message}};
      }
      inbox[id] = ack;
      acks.add(ack);
    }
    if (loseNextResponse) {
      loseNextResponse = false;
      throw OfflineException('Connection lost before the response arrived.');
    }
    return {'data': acks};
  }

  /// Envelope types the server received, in order (excluding replays).
  List<String> get processedTypes => processed.map((e) => e['type'] as String).toList();

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

String tempDbPath([String name = 'test.db']) => p.join(Directory.systemTemp.createTempSync('field_app_test').path, name);

Future<LocalDb> openTestDb([String? path]) async {
  sqfliteFfiInit();
  return LocalDb.open(path ?? tempDbPath(), factory: databaseFactoryFfi);
}

Future<(FieldRepository, FakeApi, Customer)> fieldSetup({LocationCapture? location, LocalDb? db, FakeApi? api}) async {
  final local = db ?? await openTestDb();
  final fake = api ?? FakeApi();
  final repo = FieldRepository(local: local, api: fake, location: location ?? FakeLocation(fix), userId: 7, device: () => {'platform': 'test', 'app_version': '1.0.0'});
  final customer = Customer(id: 'C1', name: 'Lekki Clinic', code: 'A', latitude: 6.4281, longitude: 3.4219, currencyCode: 'NGN');
  await local.db.insert('customers', customer.toRow(const {}), conflictAlgorithm: ConflictAlgorithm.replace);
  return (repo, fake, customer);
}

OrderLine line([String qty = '2']) => OrderLine(productId: 'P1', productName: 'Amoxicillin 500 mg', packageLevelCode: 'pack', quantity: qty, indicativeUnitPrice: '2500');

/// A till catalogue snapshot: Amoxicillin, 3,000 per pack of 20, 7.5% tax, 130 capsules in two batches.
Future<PosRepository> posSetup(LocalDb db, FakeApi api) async {
  final pos = PosRepository(local: db, api: api);
  api.handler = (method, path, body) => switch ('$method $path') {
        'GET pos/terminals/T1/offline-catalogue' => {
            'data': {
              'currency_code': 'NGN',
              'settings': {'max_discount_percent': '10', 'enforce_prescription_controls': true},
              'as_of': DateTime.now().toUtc().toIso8601String(),
              'items': [
                {
                  'product_id': 'P1',
                  'display_name': 'Amoxicillin 500 mg Capsule',
                  'sku': 'AMX',
                  'barcodes': ['06001234567890'],
                  'classification': null,
                  'tax_rate': '7.5000',
                  'package_levels': [
                    {'code': 'capsule', 'name': 'Capsule', 'base_unit_quantity': '1.0000', 'unit_price': '150.0000'},
                    {'code': 'pack', 'name': 'Pack of 20', 'base_unit_quantity': '20.0000', 'unit_price': '3000.0000'},
                  ],
                  'batches': [
                    {'batch_id': 'B-EARLY', 'batch_number': 'P-EARLY', 'expiry_date': '2027-01-31', 'free_base_units': '30.0000'},
                    {'batch_id': 'B-LATE', 'batch_number': 'P-LATE', 'expiry_date': '2028-06-30', 'free_base_units': '100.0000'},
                  ],
                },
              ],
            },
          },
        _ => throw StateError('unexpected $method $path'),
      };
  await pos.refreshCatalogue('T1');
  return pos;
}
