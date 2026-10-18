import 'dart:async';
import 'dart:io';

import 'package:geolocator/geolocator.dart';

import '../core/config.dart';

/// What the device actually reported at a check-in or check-out. When there
/// is no fix, there are no coordinates: nothing is guessed or carried over
/// from an earlier reading. The server decides whether the visit is verified.
class LocationEvidence {
  const LocationEvidence({
    required this.occurredAt,
    required this.status,
    this.latitude,
    this.longitude,
    this.accuracyM,
    this.mock,
  });

  /// granted | denied | restricted | service_disabled | unavailable | timeout
  final String status;
  final DateTime occurredAt;
  final double? latitude;
  final double? longitude;
  final double? accuracyM;
  final bool? mock;

  bool get hasFix => status == 'granted' && latitude != null && longitude != null;

  Map<String, dynamic> toJson(Map<String, String> device) => {
        'occurred_at': occurredAt.toUtc().toIso8601String(),
        'location_status': status,
        if (hasFix) ...{
          'latitude': latitude,
          'longitude': longitude,
          if (accuracyM != null) 'accuracy_m': accuracyM,
          'mock_location': mock ?? false,
        },
        'device': device,
      };

  static LocationEvidence fromJson(Map<String, dynamic> j) => LocationEvidence(
        occurredAt: DateTime.parse(j['occurred_at'] as String),
        status: j['location_status'] as String,
        latitude: (j['latitude'] as num?)?.toDouble(),
        longitude: (j['longitude'] as num?)?.toDouble(),
        accuracyM: (j['accuracy_m'] as num?)?.toDouble(),
        mock: j['mock_location'] as bool?,
      );

  /// Plain-language description for the representative.
  String describe() => switch (status) {
        'granted' => hasFix ? 'Location captured (±${accuracyM?.round() ?? '?'} m)' : 'No location fix',
        'denied' => 'Location permission denied',
        'restricted' => 'Location restricted on this device',
        'service_disabled' => 'Location services switched off',
        'timeout' => 'Location timed out',
        _ => 'Location unavailable',
      };
}

/// Device context sent with each event (no hardware identifiers).
Map<String, String> deviceContext() {
  String trim(String v) => v.length > 128 ? v.substring(0, 128) : v;
  return {
    'platform': Platform.operatingSystem,
    'os_version': trim(Platform.operatingSystemVersion),
    'app_version': AppConfig.appVersion,
  };
}

abstract class LocationCapture {
  /// Reads the location once, now. Never runs in the background.
  Future<LocationEvidence> capture();
}

/// One-shot reading through the platform location services.
class GeolocatorCapture implements LocationCapture {
  const GeolocatorCapture({this.timeLimit = const Duration(seconds: 20)});

  final Duration timeLimit;

  @override
  Future<LocationEvidence> capture() async {
    LocationEvidence without(String status) => LocationEvidence(occurredAt: DateTime.now(), status: status);

    try {
      if (!await Geolocator.isLocationServiceEnabled()) return without('service_disabled');

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      switch (permission) {
        case LocationPermission.denied:
          return without('denied');
        case LocationPermission.deniedForever:
          return without('restricted');
        case LocationPermission.unableToDetermine:
          return without('unavailable');
        case LocationPermission.whileInUse:
        case LocationPermission.always:
          break;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: LocationSettings(accuracy: LocationAccuracy.high, timeLimit: timeLimit),
      );
      return LocationEvidence(
        occurredAt: DateTime.now(),
        status: 'granted',
        latitude: position.latitude,
        longitude: position.longitude,
        accuracyM: position.accuracy,
        mock: position.isMocked,
      );
    } on TimeoutException {
      return without('timeout');
    } catch (_) {
      return without('unavailable');
    }
  }
}
