import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite_sqlcipher/sqflite.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/db_keys.dart';
import '../core/money.dart';
import '../core/session.dart';
import '../data/field_repository.dart';
import '../data/local_db.dart';
import '../data/pos_repository.dart';
import '../data/sync_service.dart';
import '../location/location_capture.dart';

class Choice {
  Choice(this.id, this.name);

  final String id;
  final String name;
}

/// Session, encrypted local database, device registration and sync status.
///
/// Sync runs by itself while the app is open: every [tick] it sends the
/// outbox if anything is waiting and the backoff window (2 s doubling to
/// 15 min after failures) has passed, and again whenever the app returns to
/// the foreground. Background sync with the app closed is not implemented
/// (see docs/OFFLINE_OUTAGE_PLAYBOOK.md).
class AppState extends ChangeNotifier {
  AppState({
    required this.sessionStore,
    DbKeyStore? keyStore,
    this.location = const GeolocatorCapture(),
    this.databaseFactoryOverride,
    this.databaseDirectory,
    this.tick = const Duration(seconds: 15),
  }) : keyStore = keyStore ?? SecureDbKeyStore();

  final SessionStore sessionStore;
  final DbKeyStore keyStore;
  final LocationCapture location;
  final DatabaseFactory? databaseFactoryOverride;
  final String? databaseDirectory;
  final Duration tick;

  Session? session;
  ApiClient? _api;
  LocalDb? _db;
  FieldRepository? repository;
  PosRepository? pos;
  SyncService? _sync;
  Timer? _timer;

  bool ready = false;
  bool busy = false;
  bool syncing = false;
  String? error;
  String? syncMessage;
  int pending = 0;
  int needsAttention = 0;
  DateTime? lastSyncAt;
  DateTime? nextSyncAt;
  int _failures = 0;

  List<Choice> organizationChoices = const [];
  List<String> modeChoices = const [];
  List<Choice> terminalChoices = const [];
  Map<String, dynamic>? posShift;

  bool get signedIn => session != null;

  bool get workspaceReady => repository != null;

  bool get isPos => session?.mode == 'pos';

  Future<void> restore() async {
    final stored = Session.fromStorage(await sessionStore.read());
    if (stored != null && stored.registered) {
      session = stored;
      await _openWorkspace();
    }
    ready = true;
    notifyListeners();
  }

  // ---- Sign-in, organization, mode, device registration -----------------------------

  Future<void> signIn({required String baseUrl, required String email, required String password, String? mfaCode}) => _guard(() async {
        final api = ApiClient(baseUrl: baseUrl.trim());
        final issued = await api.post('auth/tokens', {
          'email': email.trim(),
          'password': password,
          'device_name': 'Pharma Field sign-in (${Platform.operatingSystem})',
          if (mfaCode != null && mfaCode.isNotEmpty) 'mfa_code': mfaCode,
        });
        api.token = (issued['data'] as Map)['token'] as String;
        final me = Map<String, dynamic>.from((await api.get('auth/me'))['data'] as Map);
        session = Session(baseUrl: baseUrl.trim(), token: api.token!, userId: (me['id'] as num).toInt(), userName: me['name'] as String);
        _api = api;

        organizationChoices = (me['memberships'] as List)
            .map((m) => Map<String, dynamic>.from((m as Map)['organization'] as Map))
            .where((o) => o['status'] == 'active')
            .map((o) => Choice(o['id'] as String, o['name'] as String))
            .toList();
        if (organizationChoices.isEmpty) throw StateError('This account is not an active member of any organization.');
        if (organizationChoices.length == 1) await _selectOrganization(organizationChoices.single);
      });

  Future<void> selectOrganization(Choice choice) => _guard(() => _selectOrganization(choice));

  /// Finds out what this user may do here: field work, the till, or both.
  Future<void> _selectOrganization(Choice choice) async {
    final api = _api!..organizationId = choice.id;
    session = session!.copyWith(organizationId: choice.id, organizationName: choice.name);
    organizationChoices = const [];

    final modes = <String>[];
    Map<String, dynamic>? field;
    Map<String, dynamic>? till;
    try {
      field = Map<String, dynamic>.from((await api.get('field-force/me'))['data'] as Map);
      modes.add('field');
    } on ApiException catch (e) {
      if (e.statusCode != 403) rethrow;
    }
    try {
      till = Map<String, dynamic>.from((await api.get('pos/me'))['data'] as Map);
      modes.add('pos');
    } on ApiException catch (e) {
      if (e.statusCode != 403) rethrow;
    }
    if (modes.isEmpty) throw StateError('You have no field-force or POS access in ${choice.name}.');

    _probe = {'field': field, 'pos': till};
    if (modes.length == 1) {
      await _selectMode(modes.single);
    } else {
      modeChoices = modes;
    }
  }

  Map<String, Map<String, dynamic>?> _probe = const {};

  Future<void> selectMode(String mode) => _guard(() => _selectMode(mode));

  Future<void> _selectMode(String mode) async {
    modeChoices = const [];
    if (mode == 'field') {
      final me = _probe['field']!;
      session = session!.copyWith(mode: 'field', permissions: List<String>.from(me['permissions'] as List), settings: Map<String, dynamic>.from(me['settings'] as Map));
      await _register();
    } else {
      final me = _probe['pos']!;
      session = session!.copyWith(mode: 'pos', permissions: List<String>.from(me['permissions'] as List));
      terminalChoices = (me['terminals'] as List).map((t) => Map<String, dynamic>.from(t as Map)).map((t) => Choice(t['id'] as String, '${t['code']} · ${t['name']}')).toList();
      final shift = me['shift'] as Map?;
      // A cashier with an open shift works on that shift's terminal.
      final fixed = shift == null ? null : terminalChoices.where((t) => t.id == shift['pos_terminal_id']).firstOrNull;
      if (fixed != null || terminalChoices.length == 1) {
        await _selectTerminal(fixed ?? terminalChoices.single);
      } else if (terminalChoices.isEmpty) {
        throw StateError('There is no active POS terminal you can use.');
      }
    }
  }

  Future<void> selectTerminal(Choice terminal) => _guard(() => _selectTerminal(terminal));

  Future<void> _selectTerminal(Choice terminal) async {
    terminalChoices = const [];
    session = session!.copyWith(terminalId: terminal.id, terminalName: terminal.name);
    await _register();
  }

  /// Registers this device, swaps the sign-in token for the device-bound one, and opens the workspace.
  Future<void> _register() async {
    final s = session!;
    final api = _api!;
    final reg = Map<String, dynamic>.from((await api.post('devices', {
      'name': '${s.mode == 'pos' ? 'Till' : 'Field app'} (${Platform.operatingSystem})',
      'platform': Platform.isAndroid ? 'android' : (Platform.isIOS ? 'ios' : 'other'),
      'app_version': AppConfig.appVersion,
      'role': s.mode,
      if (s.mode == 'pos') 'pos_terminal_id': s.terminalId,
    }))['data'] as Map);
    try {
      await api.delete('auth/tokens/current'); // the sign-in token is no longer needed
    } catch (_) {}
    session = s.copyWith(token: reg['token'] as String, deviceId: (reg['device'] as Map)['id'] as String);
    await sessionStore.write(session!.toStorage());
    await _openWorkspace();
    if (isPos) await refreshPos();
    await syncNow();
  }

  // ---- Workspace ---------------------------------------------------------------------

  Future<void> _openWorkspace() async {
    final s = session!;
    _api = ApiClient(baseUrl: s.baseUrl, token: s.token, organizationId: s.organizationId);
    final dir = databaseDirectory ?? await (databaseFactoryOverride ?? databaseFactory).getDatabasesPath();
    _db = await LocalDb.open(p.join(dir, s.databaseName), factory: databaseFactoryOverride, password: await keyStore.keyFor(s.databaseName));
    repository = FieldRepository(local: _db!, api: _api!, location: location, userId: s.userId);
    pos = isPos ? PosRepository(local: _db!, api: _api!) : null;
    _sync = SyncService(repository!, pos: pos);
    final last = await _db!.meta('last_sync_at');
    lastSyncAt = last == null ? null : DateTime.parse(last);
    final shift = isPos ? await _db!.meta('pos_shift') : null;
    posShift = shift == null || shift == 'null' ? null : Map<String, dynamic>.from(jsonDecode(shift) as Map);
    await _refreshCounts();
    _timer?.cancel();
    _timer = Timer.periodic(tick, (_) => _autoSync());
  }

  /// Online: reload the till's catalogue snapshot and its open shift.
  Future<void> refreshPos() async {
    if (pos == null) return;
    try {
      final me = Map<String, dynamic>.from((await _api!.get('pos/me'))['data'] as Map);
      posShift = me['shift'] == null ? null : Map<String, dynamic>.from(me['shift'] as Map);
      await _db!.setMeta('pos_shift', jsonEncode(posShift));
      await pos!.refreshCatalogue(session!.terminalId!);
    } on OfflineException catch (e) {
      syncMessage = 'Offline: using the catalogue saved on this device. ${e.message}';
    }
    notifyListeners();
  }

  void _autoSync() {
    if (syncing || pending == 0) return;
    if (nextSyncAt != null && DateTime.now().isBefore(nextSyncAt!)) return;
    syncNow();
  }

  /// Called when the app returns to the foreground.
  void resumed() {
    nextSyncAt = null;
    _autoSync();
  }

  /// Sends queued work, then refreshes what the device caches.
  Future<void> syncNow() async {
    if (_sync == null || syncing) return;
    syncing = true;
    syncMessage = null;
    notifyListeners();
    try {
      final result = await _sync!.run();
      if (result.revoked) {
        await _deauthorized(wipe: result.wipe);
        return;
      }
      if (result.failedToReachServer) {
        _failures++;
        nextSyncAt = DateTime.now().add(SyncService.backoff(_failures));
        syncMessage = result.unauthenticated ? result.message : 'Offline: changes are kept on this device and sent automatically. ${result.message ?? ''}'.trim();
      } else {
        _failures = 0;
        nextSyncAt = null;
        try {
          if (isPos) {
            await refreshPos();
          } else {
            await repository!.refreshCustomers();
            await repository!.refreshOrders();
            await repository!.refreshFollowUps();
            await _refreshMyDay();
          }
          lastSyncAt = DateTime.now();
          await _db!.setMeta('last_sync_at', lastSyncAt!.toUtc().toIso8601String());
          syncMessage = result.refused > 0 ? '${result.refused} item(s) were refused by the server. See Sync status.' : null;
        } on OfflineException catch (e) {
          syncMessage = 'Offline: ${e.message}';
        } on ApiException catch (e) {
          if (e.code == 'device_revoked') {
            await _deauthorized(wipe: e.details['wipe'] == true);
            return;
          }
          syncMessage = e.message;
        }
      }
    } finally {
      syncing = false;
      await _refreshCounts();
      notifyListeners();
    }
  }

  /// The administrator deauthorized this device (spec §13.2). With a wipe request the
  /// local data is erased by destroying its key and file; otherwise it stays encrypted
  /// on the device and is sent after the next successful sign-in to the same organization.
  Future<void> _deauthorized({required bool wipe}) async {
    final s = session!;
    final unsent = pending;
    _timer?.cancel();
    await _db?.close();
    if (wipe) {
      final dir = databaseDirectory ?? await (databaseFactoryOverride ?? databaseFactory).getDatabasesPath();
      await (databaseFactoryOverride ?? databaseFactory).deleteDatabase(p.join(dir, s.databaseName));
      await keyStore.destroy(s.databaseName);
    }
    _db = null;
    repository = null;
    pos = null;
    _sync = null;
    session = null;
    _api = null;
    await sessionStore.clear();
    error = wipe
        ? 'This device was deauthorized by your administrator and its local data has been erased${unsent > 0 ? ' ($unsent unsent item(s) were lost)' : ''}.'
        : 'This device was deauthorized by your administrator. Unsent work is kept on this device; sign in again to continue.';
    syncing = false;
    notifyListeners();
  }

  /// My day needs the visit-capture permission; supervisors without it simply have no planned calls.
  Future<void> _refreshMyDay() async {
    if (!(session?.can('field_force.visits.capture') ?? false)) return;
    try {
      await repository!.refreshMyDay();
    } on ApiException catch (e) {
      if (e.statusCode != 403 && e.statusCode != 404) rethrow;
    }
  }

  Future<void> _refreshCounts() async {
    if (repository == null) return;
    pending = await repository!.outbox.count('pending');
    needsAttention = await repository!.outbox.count('failed');
  }

  Future<void> changed() async {
    await _refreshCounts();
    notifyListeners();
  }

  Future<void> openShift(String openingFloat) => _guard(() async {
        posShift = await pos!.openShift(session!.terminalId!, D4.parse(openingFloat));
        await refreshPos();
      });

  Future<Map<String, dynamic>?> closeShift(String countedCash) async {
    Map<String, dynamic>? closed;
    await _guard(() async {
      await syncNow();
      closed = await pos!.closeShift(posShift!['id'] as String, D4.parse(countedCash));
      posShift = null;
    });
    return closed;
  }

  /// Signing out deauthorizes this device registration; unsent work stays encrypted on the device.
  Future<void> signOut() async {
    try {
      if (session?.deviceId != null) await _api?.post('devices/${session!.deviceId}/revoke', {'reason': 'Signed out on the device'});
    } catch (_) {
      // Works offline; the device token expires on the server anyway.
    }
    _timer?.cancel();
    await _db?.close();
    _db = null;
    repository = null;
    pos = null;
    _sync = null;
    session = null;
    _api = null;
    posShift = null;
    await sessionStore.clear();
    notifyListeners();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
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
    } on FormatException {
      error = 'Enter a valid amount.';
    } finally {
      busy = false;
      notifyListeners();
    }
  }
}
