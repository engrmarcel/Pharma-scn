import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

/// The server could not be reached; the operation can be retried later.
class OfflineException implements Exception {
  OfflineException([this.message = 'No connection to the server.']);

  final String message;

  @override
  String toString() => message;
}

/// The server answered with an error. [code] is the platform's stable error code.
class ApiException implements Exception {
  ApiException(this.statusCode, this.code, this.message, {this.details = const {}});

  final int statusCode;
  final String code;
  final String message;
  final Map<String, dynamic> details;

  /// Server-side or throttling problems: retry later instead of giving up.
  bool get isRetryable => statusCode >= 500 || statusCode == 429 || statusCode == 408;

  bool get isUnauthenticated => statusCode == 401 && code != 'mfa_required' && code != 'mfa_code_invalid';

  @override
  String toString() => message;
}

/// Transport used by repositories and the sync service (faked in tests).
abstract class Api {
  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query});

  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]);

  Future<Map<String, dynamic>> patch(String path, Map<String, dynamic> body);
}

/// JSON client for /api/v1 with the bearer token and the tenant header.
class ApiClient implements Api {
  ApiClient({
    required this.baseUrl,
    this.token,
    this.organizationId,
    http.Client? client,
    this.timeout = const Duration(seconds: 20),
  }) : _client = client ?? http.Client();

  final String baseUrl;
  String? token;
  String? organizationId;
  final http.Client _client;
  final Duration timeout;

  Uri _uri(String path, [Map<String, String>? query]) {
    final base = baseUrl.endsWith('/') ? baseUrl.substring(0, baseUrl.length - 1) : baseUrl;
    final rel = path.startsWith('/') ? path.substring(1) : path;
    final uri = Uri.parse('$base/$rel');
    return (query == null || query.isEmpty) ? uri : uri.replace(queryParameters: query);
  }

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
        'X-Organization-Id': ?organizationId,
      };

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) =>
      _send(() => _client.get(_uri(path, query), headers: _headers));

  @override
  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) =>
      _send(() => _client.post(_uri(path), headers: _headers, body: jsonEncode(body ?? const {})));

  @override
  Future<Map<String, dynamic>> patch(String path, Map<String, dynamic> body) =>
      _send(() => _client.patch(_uri(path), headers: _headers, body: jsonEncode(body)));

  Future<void> delete(String path) => _send(() => _client.delete(_uri(path), headers: _headers));

  Future<Map<String, dynamic>> _send(Future<http.Response> Function() call) async {
    final http.Response res;
    try {
      res = await call().timeout(timeout);
    } on SocketException {
      throw OfflineException();
    } on http.ClientException {
      throw OfflineException();
    } on TimeoutException {
      throw OfflineException('The server did not respond in time.');
    }

    Map<String, dynamic> body = const {};
    if (res.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(res.body);
        if (decoded is Map<String, dynamic>) body = decoded;
      } on FormatException {
        // Not JSON (for example a proxy error page); handled by the status code below.
      }
    }

    if (res.statusCode >= 400) {
      final error = body['error'];
      final code = error is Map && error['code'] != null
          ? error['code'].toString()
          : (res.statusCode == 422 ? 'validation_failed' : 'http_${res.statusCode}');
      final details = error is Map && error['details'] is Map
          ? Map<String, dynamic>.from(error['details'] as Map)
          : (body['errors'] is Map ? <String, dynamic>{'errors': body['errors']} : <String, dynamic>{});
      throw ApiException(res.statusCode, code, body['message']?.toString() ?? 'Request failed (${res.statusCode}).', details: details);
    }

    return body;
  }
}
