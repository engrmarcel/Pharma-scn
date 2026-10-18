import 'package:sqflite/sqflite.dart';

/// On-device relational store (spec §13.2). Every record created on the
/// device carries a client_reference that the server uses for idempotency,
/// and every change destined for the server goes through the durable
/// `outbox`, processed in order. Phase 3 extends this schema (migrations
/// via [LocalDb.schemaVersion]) rather than replacing it.
///
/// One database file per user and organization, so records never mix.
class LocalDb {
  LocalDb._(this.db);

  final Database db;

  static const schemaVersion = 1;

  static const _schema = <String>[
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

  static Future<LocalDb> open(String path, {DatabaseFactory? factory}) async {
    final db = await (factory ?? databaseFactory).openDatabase(
      path,
      options: OpenDatabaseOptions(
        version: schemaVersion,
        onCreate: (db, version) async {
          for (final statement in _schema) {
            await db.execute(statement);
          }
        },
      ),
    );
    return LocalDb._(db);
  }

  Future<String?> meta(String key) async =>
      (await db.query('meta', where: 'key = ?', whereArgs: [key])).firstOrNull?['value'] as String?;

  Future<void> setMeta(String key, String value) =>
      db.insert('meta', {'key': key, 'value': value}, conflictAlgorithm: ConflictAlgorithm.replace);

  Future<void> close() => db.close();
}
