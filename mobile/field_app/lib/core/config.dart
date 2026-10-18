/// Build-time configuration. Override the server with
/// `flutter run --dart-define=API_BASE_URL=https://example.org/api/v1`.
class AppConfig {
  /// 10.0.2.2 is the host machine as seen from the Android emulator.
  static const defaultBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8000/api/v1');

  static const appVersion = '1.0.0';

  /// Captures sent more than this long after they were made are flagged as offline captures.
  static const offlineCaptureThreshold = Duration(minutes: 2);
}
