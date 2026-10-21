import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Keys for the encrypted local databases (spec §13.2). Each database file
/// has its own random 256-bit key, kept only in Android Keystore / iOS
/// Keychain. Deleting the key makes the file unreadable (crypto-shredding),
/// which is how a device wipe is carried out.
abstract class DbKeyStore {
  Future<String> keyFor(String databaseName);

  Future<void> destroy(String databaseName);
}

class SecureDbKeyStore implements DbKeyStore {
  SecureDbKeyStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String> keyFor(String databaseName) async {
    final name = 'dbkey.$databaseName';
    final existing = await _storage.read(key: name);
    if (existing != null) return existing;
    final random = Random.secure();
    final key = base64UrlEncode(List<int>.generate(32, (_) => random.nextInt(256)));
    await _storage.write(key: name, value: key);
    return key;
  }

  @override
  Future<void> destroy(String databaseName) => _storage.delete(key: 'dbkey.$databaseName');
}

/// Tests: keys in memory.
class MemoryDbKeyStore implements DbKeyStore {
  final keys = <String, String>{};

  @override
  Future<String> keyFor(String databaseName) async => keys.putIfAbsent(databaseName, () => 'test-key-$databaseName');

  @override
  Future<void> destroy(String databaseName) async => keys.remove(databaseName);
}
