import 'package:sqflite_sqlcipher/sqflite.dart' as cipher;

/// On-device relational store (spec §13.2), encrypted with SQLCipher. The
/// key lives in Keystore/Keychain (see DbKeyStore). Every record created on
/// the device carries a client_reference, and every change destined for the
/// server goes through the durable `outbox`, sent in order as sync envelopes
/// (ADR-0011) with a stable client_txn_id.
///
/// One database file per user, organization and device role, so records never mix.
class LocalDb {
  LocalDb._(this.db);

  final cipher.Database db;

  static const schemaVersion = 2;

  static const _v1 = <String>[
    '''CREATE TABLE customers (
      id TEXT PRIMARY KEY, code TEXT, name TEXT NOT NULL, type TEXT, status TEXT, phone TEXT, email TEXT,
      address TEXT, city TEXT, latitude REAL, longitude REAL, territory_id TEXT, assigned_representative_id INTEGER,
      currency_code TEXT, last_visit_at TEXT, server_updated_at TEXT, cached_at TEXT NOT NULL)''',
    '''CREATE TABLE catalogue_items (
      customer_id TEXT NOT NULL, product_id TEXT NOT NULL, display_name TEXT NOT NULL, generic_name TEXT, sku TEXT,
      currency_code TEXT, package_levels TEXT NOT NULL, sellable_base_units TEXT, stock_as_of TEXT NOT NULL,
      PRIMARY KEY (customer_id, product_id))''',
    '''CREATE TABLE visits (
      client_reference TEXT PRIMARY KEY, server_id TEXT, number TEXT, customer_id TEXT NOT NULL, customer_name TEXT,
      visit_type TEXT NOT NULL, objectives TEXT,
      status TEXT NOT NULL,                 -- in_progress | completed | cancelled (as known on the device)
      check_in TEXT NOT NULL, check_out TEXT, -- evidence JSON exactly as captured
      check_in_verification TEXT, check_out_verification TEXT, -- set only from the server response
      details TEXT, activities TEXT,
      sync_state TEXT NOT NULL,             -- pending | synced | failed
      last_error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''',
    '''CREATE TABLE orders (
      client_reference TEXT PRIMARY KEY, server_id TEXT, number TEXT, customer_id TEXT NOT NULL, customer_name TEXT,
      visit_reference TEXT, captured_at TEXT NOT NULL, notes TEXT, lines TEXT NOT NULL,
      local_state TEXT NOT NULL,            -- draft (device only) | queued (waiting to send) | sent
      server_status TEXT,                   -- draft | submitted | accepted | rejected | cancelled (from the server only)
      sales_order TEXT, stock_reserved INTEGER NOT NULL DEFAULT 0, rejection TEXT,
      sync_state TEXT NOT NULL, last_error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''',
    '''CREATE TABLE follow_ups (
      id TEXT PRIMARY KEY, customer_id TEXT NOT NULL, title TEXT NOT NULL, notes TEXT, due_on TEXT NOT NULL,
      status TEXT NOT NULL, local_status TEXT, sync_state TEXT NOT NULL DEFAULT 'synced', last_error TEXT, cached_at TEXT NOT NULL)''',
    '''CREATE TABLE outbox (
      id INTEGER PRIMARY KEY AUTOINCREMENT, operation TEXT NOT NULL, entity_ref TEXT NOT NULL, payload TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending', -- pending | failed (done items are removed)
      attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''',
    'CREATE INDEX outbox_status ON outbox (status, id)',
    'CREATE INDEX outbox_entity ON outbox (entity_ref)',
    'CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)',
  ];

  /// v2 (Phase 3): sync envelopes, retry scheduling and the offline POS.
  static const _v2 = <String>[
    'ALTER TABLE outbox ADD COLUMN client_txn_id TEXT',
    'ALTER TABLE outbox ADD COLUMN failure_kind TEXT',     // conflict | rejected (set when the server refuses)
    'ALTER TABLE outbox ADD COLUMN last_attempt_at TEXT',
    "UPDATE outbox SET client_txn_id = 'legacy-' || id WHERE client_txn_id IS NULL",
    'CREATE UNIQUE INDEX outbox_txn ON outbox (client_txn_id)',
    '''CREATE TABLE pos_catalogue (
      product_id TEXT PRIMARY KEY, display_name TEXT NOT NULL, generic_name TEXT, sku TEXT, barcodes TEXT NOT NULL,
      classification TEXT, tax_category_code TEXT, tax_rate TEXT, package_levels TEXT NOT NULL, batches TEXT NOT NULL,
      as_of TEXT NOT NULL)''',
    '''CREATE TABLE pos_sales (
      client_reference TEXT PRIMARY KEY, shift_id TEXT NOT NULL, server_id TEXT, number TEXT,
      lines TEXT NOT NULL, payments TEXT NOT NULL, subtotal TEXT NOT NULL, discount TEXT NOT NULL, tax TEXT NOT NULL, total TEXT NOT NULL,
      change_due TEXT NOT NULL,
      server_status TEXT,                   -- completed | awaiting_payment (from the server only)
      payment_states TEXT,                  -- per payment, from the server: offline_cash_recorded | confirmed | payment_pending
      warnings TEXT, sync_state TEXT NOT NULL, last_error TEXT, completed_at TEXT NOT NULL, updated_at TEXT NOT NULL)''',
  ];

  static Future<LocalDb> open(String path, {cipher.DatabaseFactory? factory, String? password}) async {
    Future<void> create(cipher.Database db, int version) async {
      for (final s in [..._v1, ..._v2]) {
        await db.execute(s);
      }
    }

    Future<void> upgrade(cipher.Database db, int from, int to) async {
      if (from < 2) {
        for (final s in _v2) {
          await db.execute(s);
        }
      }
    }

    // Tests pass an FFI factory (plain SQLite); the app always opens the encrypted database.
    final db = factory != null
        ? await factory.openDatabase(path, options: cipher.OpenDatabaseOptions(version: schemaVersion, onCreate: create, onUpgrade: upgrade))
        : await cipher.openDatabase(path, password: password, version: schemaVersion, onCreate: create, onUpgrade: upgrade);
    return LocalDb._(db);
  }

  Future<String?> meta(String key) async =>
      (await db.query('meta', where: 'key = ?', whereArgs: [key])).firstOrNull?['value'] as String?;

  Future<void> setMeta(String key, String value) =>
      db.insert('meta', {'key': key, 'value': value}, conflictAlgorithm: cipher.ConflictAlgorithm.replace);

  Future<void> deleteMeta(String key) => db.delete('meta', where: 'key = ?', whereArgs: [key]);

  Future<void> close() => db.close();
}
