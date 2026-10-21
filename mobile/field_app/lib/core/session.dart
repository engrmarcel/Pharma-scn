import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// The signed-in user, the organization, and how this device is registered.
class Session {
  Session({
    required this.baseUrl,
    required this.token,
    required this.userId,
    required this.userName,
    this.organizationId,
    this.organizationName,
    this.mode,
    this.deviceId,
    this.terminalId,
    this.terminalName,
    this.permissions = const [],
    this.settings = const {},
  });

  final String baseUrl;

  /// The device-bound token once the device is registered (ADR-0011).
  final String token;
  final int userId;
  final String userName;
  final String? organizationId;
  final String? organizationName;

  /// field | pos
  final String? mode;
  final String? deviceId;
  final String? terminalId;
  final String? terminalName;
  final List<String> permissions;
  final Map<String, dynamic> settings;

  bool can(String permission) => permissions.contains(permission);

  bool get registered => deviceId != null && organizationId != null && mode != null;

  Session copyWith({
    String? token,
    String? organizationId,
    String? organizationName,
    String? mode,
    String? deviceId,
    String? terminalId,
    String? terminalName,
    List<String>? permissions,
    Map<String, dynamic>? settings,
  }) =>
      Session(
        baseUrl: baseUrl,
        token: token ?? this.token,
        userId: userId,
        userName: userName,
        organizationId: organizationId ?? this.organizationId,
        organizationName: organizationName ?? this.organizationName,
        mode: mode ?? this.mode,
        deviceId: deviceId ?? this.deviceId,
        terminalId: terminalId ?? this.terminalId,
        terminalName: terminalName ?? this.terminalName,
        permissions: permissions ?? this.permissions,
        settings: settings ?? this.settings,
      );

  /// Database file name: one per user, organization and role, so records never mix.
  String get databaseName => '${mode}_${userId}_$organizationId.db';

  Map<String, String> toStorage() => {
        'base_url': baseUrl,
        'token': token,
        'user_id': '$userId',
        'user_name': userName,
        'organization_id': ?organizationId,
        'organization_name': ?organizationName,
        'mode': ?mode,
        'device_id': ?deviceId,
        'terminal_id': ?terminalId,
        'terminal_name': ?terminalName,
        'permissions': jsonEncode(permissions),
        'settings': jsonEncode(settings),
      };

  static Session? fromStorage(Map<String, String> v) {
    if (v['token'] == null || v['base_url'] == null || v['user_id'] == null) return null;
    return Session(
      baseUrl: v['base_url']!,
      token: v['token']!,
      userId: int.tryParse(v['user_id']!) ?? 0,
      userName: v['user_name'] ?? '',
      organizationId: v['organization_id'],
      organizationName: v['organization_name'],
      mode: v['mode'],
      deviceId: v['device_id'],
      terminalId: v['terminal_id'],
      terminalName: v['terminal_name'],
      permissions: v['permissions'] == null ? const [] : List<String>.from(jsonDecode(v['permissions']!) as List),
      settings: v['settings'] == null ? const {} : Map<String, dynamic>.from(jsonDecode(v['settings']!) as Map),
    );
  }
}

/// Where the session (including the API token) is kept between launches.
abstract class SessionStore {
  Future<Map<String, String>> read();

  Future<void> write(Map<String, String> values);

  Future<void> clear();
}

/// Keystore/Keychain-backed storage: the token never sits in plain preferences.
class SecureSessionStore implements SessionStore {
  SecureSessionStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;
  static const _prefix = 'session.';

  @override
  Future<Map<String, String>> read() async {
    final all = await _storage.readAll();
    return {
      for (final e in all.entries)
        if (e.key.startsWith(_prefix)) e.key.substring(_prefix.length): e.value,
    };
  }

  @override
  Future<void> write(Map<String, String> values) async {
    await clear();
    for (final e in values.entries) {
      await _storage.write(key: '$_prefix${e.key}', value: e.value);
    }
  }

  @override
  Future<void> clear() async {
    final all = await _storage.readAll();
    for (final key in all.keys.where((k) => k.startsWith(_prefix))) {
      await _storage.delete(key: key);
    }
  }
}

/// In-memory store for tests.
class MemorySessionStore implements SessionStore {
  Map<String, String> values = {};

  @override
  Future<Map<String, String>> read() async => Map.of(values);

  @override
  Future<void> write(Map<String, String> v) async => values = Map.of(v);

  @override
  Future<void> clear() async => values = {};
}
