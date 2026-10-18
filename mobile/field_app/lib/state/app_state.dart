import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

import '../core/api.dart';
import '../core/session.dart';
import '../data/field_repository.dart';
import '../data/local_db.dart';
import '../data/sync_service.dart';
import '../location/location_capture.dart';

class OrganizationChoice {
  OrganizationChoice(this.id, this.name);

  final String id;
  final String name;
}

/// Session, local database and sync status for the whole app.
class AppState extends ChangeNotifier {
  AppState({required this.sessionStore, this.location = const GeolocatorCapture(), this.databaseFactoryOverride, this.databaseDirectory});

  final SessionStore sessionStore;
  final LocationCapture location;
  final DatabaseFactory? databaseFactoryOverride;
  final String? databaseDirectory;

  Session? session;
  ApiClient? _api;
  LocalDb? _db;
  FieldRepository? repository;
  SyncService? _sync;

  bool ready = false;
  bool busy = false;
  bool syncing = false;
  String? error;
  String? syncMessage;
  int pending = 0;
  int needsAttention = 0;
  DateTime? lastSyncAt;
  List<OrganizationChoice> organizationChoices = const [];

  bool get signedIn => session != null;

  bool get workspaceReady => repository != null;

  Future<void> restore() async {
    final stored = Session.fromStorage(await sessionStore.read());
    if (stored != null && stored.organizationId != null) {
      session = stored;
      await _openWorkspace();
    }
    ready = true;
    notifyListeners();
  }

  Future<void> signIn({required String baseUrl, required String email, required String password, String? mfaCode}) async {
    await _guard(() async {
      final api = ApiClient(baseUrl: baseUrl.trim());
      final issued = await api.post('auth/tokens', {
        'email': email.trim(),
        'password': password,
        'device_name': 'Field app (${Platform.operatingSystem})',
        if (mfaCode != null && mfaCode.isNotEmpty) 'mfa_code': mfaCode,
      });
      api.token = (issued['data'] as Map)['token'] as String;
      final me = Map<String, dynamic>.from((await api.get('auth/me'))['data'] as Map);
      session = Session(baseUrl: baseUrl.trim(), token: api.token!, userId: (me['id'] as num).toInt(), userName: me['name'] as String);
      _api = api;

      organizationChoices = (me['memberships'] as List)
          .map((m) => Map<String, dynamic>.from((m as Map)['organization'] as Map))
          .where((o) => o['status'] == 'active')
          .map((o) => OrganizationChoice(o['id'] as String, o['name'] as String))
          .toList();
      if (organizationChoices.isEmpty) {
        throw StateError('This account is not an active member of any organization.');
      }
      if (organizationChoices.length == 1) {
        await _selectOrganization(organizationChoices.single);
      }
    });
  }

  Future<void> selectOrganization(OrganizationChoice choice) => _guard(() => _selectOrganization(choice));

  Future<void> _selectOrganization(OrganizationChoice choice) async {
    final api = _api ?? ApiClient(baseUrl: session!.baseUrl, token: session!.token);
    api.organizationId = choice.id;
    // Fails with a clear message when the organization has no field-force module or the user no field-force role.
    final me = Map<String, dynamic>.from((await api.get('field-force/me'))['data'] as Map);
    session = session!.withOrganization(choice.id, choice.name, List<String>.from(me['permissions'] as List), Map<String, dynamic>.from(me['settings'] as Map));
    await sessionStore.write(session!.toStorage());
    organizationChoices = const [];
    await _openWorkspace();
    await syncNow();
  }

  Future<void> _openWorkspace() async {
    final s = session!;
    _api = ApiClient(baseUrl: s.baseUrl, token: s.token, organizationId: s.organizationId);
    final dir = databaseDirectory ?? await (databaseFactoryOverride ?? databaseFactory).getDatabasesPath();
    _db = await LocalDb.open(p.join(dir, 'field_${s.userId}_${s.organizationId}.db'), factory: databaseFactoryOverride);
    repository = FieldRepository(local: _db!, api: _api!, location: location, userId: s.userId);
    _sync = SyncService(repository!);
    await _refreshCounts();
  }

  /// Sends queued work, then refreshes the cached directory, orders and follow-ups.
  Future<void> syncNow() async {
    if (_sync == null || syncing) return;
    syncing = true;
    syncMessage = null;
    notifyListeners();
    try {
      final result = await _sync!.run();
      if (result.unauthenticated) {
        syncMessage = result.message;
      } else if (result.offline) {
        syncMessage = 'Offline: ${result.message ?? 'changes are kept on this device'}.';
      } else {
        try {
          await repository!.refreshCustomers();
          await repository!.refreshOrders();
          await repository!.refreshFollowUps();
          lastSyncAt = DateTime.now();
          syncMessage = result.refused > 0 ? '${result.refused} item(s) were refused by the server.' : result.message;
        } on OfflineException catch (e) {
          syncMessage = 'Offline: ${e.message}';
        } on ApiException catch (e) {
          syncMessage = e.message;
        }
      }
    } finally {
      syncing = false;
      await _refreshCounts();
      notifyListeners();
    }
  }

  Future<void> _refreshCounts() async {
    if (repository == null) return;
    pending = await repository!.outbox.count('pending');
    needsAttention = await repository!.outbox.count('failed');
  }

  /// Call after any local change so badges stay current.
  Future<void> changed() async {
    await _refreshCounts();
    notifyListeners();
  }

  Future<void> signOut() async {
    try {
      await _api?.delete('auth/tokens/current');
    } catch (_) {
      // Signing out works offline; the token expires on the server anyway.
    }
    await _db?.close();
    _db = null;
    repository = null;
    _sync = null;
    session = null;
    _api = null;
    await sessionStore.clear();
    notifyListeners();
  }

  Future<void> _guard(Future<void> Function() action) async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      await action();
    } on ApiException catch (e) {
      error = e.message;
    } on OfflineException catch (e) {
      error = e.message;
    } on StateError catch (e) {
      error = e.message;
    } finally {
      busy = false;
      notifyListeners();
    }
  }
}
