import 'dart:convert';

import '../location/location_capture.dart';

class Customer {
  Customer({
    required this.id,
    required this.name,
    this.code,
    this.type,
    this.status,
    this.phone,
    this.email,
    this.address,
    this.city,
    this.latitude,
    this.longitude,
    this.currencyCode,
    this.lastVisitAt,
  });

  final String id;
  final String name;
  final String? code;
  final String? type;
  final String? status;
  final String? phone;
  final String? email;
  final String? address;
  final String? city;
  final double? latitude;
  final double? longitude;
  final String? currencyCode;
  final DateTime? lastVisitAt;

  bool get hasLocation => latitude != null && longitude != null;

  static double? _num(dynamic v) => v == null ? null : double.tryParse(v.toString());

  static Customer fromApi(Map<String, dynamic> j) => Customer(
        id: j['id'] as String,
        name: j['name'] as String,
        code: j['code'] as String?,
        type: j['type'] as String?,
        status: j['status'] as String?,
        phone: j['phone'] as String?,
        email: j['email'] as String?,
        address: j['address_line1'] as String?,
        city: j['city'] as String?,
        latitude: _num(j['latitude']),
        longitude: _num(j['longitude']),
        currencyCode: j['currency_code'] as String?,
        lastVisitAt: j['last_visit_at'] == null ? null : DateTime.tryParse(j['last_visit_at'].toString()),
      );

  Map<String, Object?> toRow(Map<String, dynamic> api) => {
        'id': id,
        'code': code,
        'name': name,
        'type': type,
        'status': status,
        'phone': phone,
        'email': email,
        'address': address,
        'city': city,
        'latitude': latitude,
        'longitude': longitude,
        'territory_id': api['territory_id'],
        'assigned_representative_id': api['assigned_representative_id'],
        'currency_code': currencyCode,
        'last_visit_at': lastVisitAt?.toIso8601String(),
        'server_updated_at': api['updated_at']?.toString(),
        'cached_at': DateTime.now().toUtc().toIso8601String(),
      };

  static Customer fromRow(Map<String, Object?> r) => Customer(
        id: r['id'] as String,
        name: r['name'] as String,
        code: r['code'] as String?,
        type: r['type'] as String?,
        status: r['status'] as String?,
        phone: r['phone'] as String?,
        email: r['email'] as String?,
        address: r['address'] as String?,
        city: r['city'] as String?,
        latitude: r['latitude'] as double?,
        longitude: r['longitude'] as double?,
        currencyCode: r['currency_code'] as String?,
        lastVisitAt: r['last_visit_at'] == null ? null : DateTime.tryParse(r['last_visit_at'] as String),
      );
}

class PackageLevel {
  PackageLevel({required this.code, required this.name, required this.baseUnitQuantity, this.indicativePrice, this.priceSource});

  final String code;
  final String name;
  final String baseUnitQuantity;
  final String? indicativePrice;
  final String? priceSource;

  static PackageLevel fromJson(Map<String, dynamic> j) => PackageLevel(
        code: j['code'] as String,
        name: j['name'] as String,
        baseUnitQuantity: j['base_unit_quantity'].toString(),
        indicativePrice: j['indicative_unit_price']?.toString(),
        priceSource: j['price_source'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'code': code,
        'name': name,
        'base_unit_quantity': baseUnitQuantity,
        'indicative_unit_price': indicativePrice,
        'price_source': priceSource,
      };
}

class CatalogueItem {
  CatalogueItem({
    required this.productId,
    required this.displayName,
    required this.levels,
    required this.stockAsOf,
    this.genericName,
    this.sku,
    this.currencyCode,
    this.sellableBaseUnits,
  });

  final String productId;
  final String displayName;
  final String? genericName;
  final String? sku;
  final String? currencyCode;
  final List<PackageLevel> levels;
  final String? sellableBaseUnits;

  /// When the server read the stock figure; the UI shows its age.
  final DateTime stockAsOf;

  static CatalogueItem fromRow(Map<String, Object?> r) => CatalogueItem(
        productId: r['product_id'] as String,
        displayName: r['display_name'] as String,
        genericName: r['generic_name'] as String?,
        sku: r['sku'] as String?,
        currencyCode: r['currency_code'] as String?,
        levels: (jsonDecode(r['package_levels'] as String) as List).map((e) => PackageLevel.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        sellableBaseUnits: r['sellable_base_units'] as String?,
        stockAsOf: DateTime.parse(r['stock_as_of'] as String),
      );
}

class LocalVisit {
  LocalVisit({
    required this.clientReference,
    required this.customerId,
    required this.visitType,
    required this.status,
    required this.checkIn,
    required this.syncState,
    required this.createdAt,
    this.customerName,
    this.serverId,
    this.number,
    this.objectives,
    this.checkOut,
    this.checkInVerification,
    this.checkOutVerification,
    this.details = const {},
    this.lastError,
  });

  final String clientReference;
  final String? serverId;
  final String? number;
  final String customerId;
  final String? customerName;
  final String visitType;
  final String? objectives;
  final String status;
  final LocationEvidence checkIn;
  final LocationEvidence? checkOut;
  final String? checkInVerification;
  final String? checkOutVerification;
  final Map<String, dynamic> details;
  final String syncState;
  final String? lastError;
  final DateTime createdAt;

  static LocalVisit fromRow(Map<String, Object?> r) => LocalVisit(
        clientReference: r['client_reference'] as String,
        serverId: r['server_id'] as String?,
        number: r['number'] as String?,
        customerId: r['customer_id'] as String,
        customerName: r['customer_name'] as String?,
        visitType: r['visit_type'] as String,
        objectives: r['objectives'] as String?,
        status: r['status'] as String,
        checkIn: LocationEvidence.fromJson(Map<String, dynamic>.from(jsonDecode(r['check_in'] as String) as Map)),
        checkOut: r['check_out'] == null ? null : LocationEvidence.fromJson(Map<String, dynamic>.from(jsonDecode(r['check_out'] as String) as Map)),
        checkInVerification: r['check_in_verification'] as String?,
        checkOutVerification: r['check_out_verification'] as String?,
        details: r['details'] == null ? const {} : Map<String, dynamic>.from(jsonDecode(r['details'] as String) as Map),
        syncState: r['sync_state'] as String,
        lastError: r['last_error'] as String?,
        createdAt: DateTime.parse(r['created_at'] as String),
      );
}

class OrderLine {
  OrderLine({required this.productId, required this.productName, required this.packageLevelCode, required this.quantity, this.indicativeUnitPrice});

  final String productId;
  final String productName;
  final String packageLevelCode;
  final String quantity;
  final String? indicativeUnitPrice;

  Map<String, dynamic> toJson() => {
        'product_id': productId,
        'product_name': productName,
        'package_level_code': packageLevelCode,
        'quantity': quantity,
        'indicative_unit_price': indicativeUnitPrice,
      };

  /// What the server accepts (no display-only fields).
  Map<String, dynamic> toApi() => {
        'product_id': productId,
        'package_level_code': packageLevelCode,
        'quantity': quantity,
        if (indicativeUnitPrice != null) 'indicative_unit_price': indicativeUnitPrice,
      };

  static OrderLine fromJson(Map<String, dynamic> j) => OrderLine(
        productId: j['product_id'] as String,
        productName: (j['product_name'] ?? '') as String,
        packageLevelCode: (j['package_level_code'] ?? '') as String,
        quantity: j['quantity'].toString(),
        indicativeUnitPrice: j['indicative_unit_price']?.toString(),
      );
}

class LocalOrder {
  LocalOrder({
    required this.clientReference,
    required this.customerId,
    required this.capturedAt,
    required this.lines,
    required this.localState,
    required this.syncState,
    this.customerName,
    this.visitReference,
    this.serverId,
    this.number,
    this.notes,
    this.serverStatus,
    this.salesOrder,
    this.stockReserved = false,
    this.rejection,
    this.lastError,
  });

  final String clientReference;
  final String? serverId;
  final String? number;
  final String customerId;
  final String? customerName;
  final String? visitReference;
  final DateTime capturedAt;
  final String? notes;
  final List<OrderLine> lines;

  /// draft (only on this device) | queued (waiting to send) | sent
  final String localState;

  /// Set only from server responses.
  final String? serverStatus;
  final Map<String, dynamic>? salesOrder;
  final bool stockReserved;
  final List<dynamic>? rejection;
  final String syncState;
  final String? lastError;

  bool get editable => localState == 'draft' || serverStatus == 'rejected';

  static LocalOrder fromRow(Map<String, Object?> r) => LocalOrder(
        clientReference: r['client_reference'] as String,
        serverId: r['server_id'] as String?,
        number: r['number'] as String?,
        customerId: r['customer_id'] as String,
        customerName: r['customer_name'] as String?,
        visitReference: r['visit_reference'] as String?,
        capturedAt: DateTime.parse(r['captured_at'] as String),
        notes: r['notes'] as String?,
        lines: (jsonDecode(r['lines'] as String) as List).map((e) => OrderLine.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        localState: r['local_state'] as String,
        serverStatus: r['server_status'] as String?,
        salesOrder: r['sales_order'] == null ? null : Map<String, dynamic>.from(jsonDecode(r['sales_order'] as String) as Map),
        stockReserved: (r['stock_reserved'] as int? ?? 0) == 1,
        rejection: r['rejection'] == null ? null : jsonDecode(r['rejection'] as String) as List,
        syncState: r['sync_state'] as String,
        lastError: r['last_error'] as String?,
      );
}

class FollowUp {
  FollowUp({required this.id, required this.customerId, required this.title, required this.dueOn, required this.status, this.notes, this.localStatus, this.syncState = 'synced', this.lastError});

  final String id;
  final String customerId;
  final String title;
  final String? notes;
  final DateTime dueOn;
  final String status;

  /// A completion recorded on the device and not yet confirmed by the server.
  final String? localStatus;
  final String syncState;
  final String? lastError;

  static FollowUp fromRow(Map<String, Object?> r) => FollowUp(
        id: r['id'] as String,
        customerId: r['customer_id'] as String,
        title: r['title'] as String,
        notes: r['notes'] as String?,
        dueOn: DateTime.parse(r['due_on'] as String),
        status: r['status'] as String,
        localStatus: r['local_status'] as String?,
        syncState: r['sync_state'] as String,
        lastError: r['last_error'] as String?,
      );
}
