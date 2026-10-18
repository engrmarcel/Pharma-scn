import 'package:flutter/material.dart';

/// Navy and gold, matching the web application.
class AppColors {
  static const navy = Color(0xFF0B1F42);
  static const royal = Color(0xFF0B4EA2);
  static const gold = Color(0xFFE6AE33);
  static const success = Color(0xFF059669);
  static const warning = Color(0xFFD97706);
  static const danger = Color(0xFFE11D48);
}

ThemeData buildTheme() {
  final scheme = ColorScheme.fromSeed(seedColor: AppColors.royal, primary: AppColors.royal, secondary: AppColors.gold, surface: Colors.white);
  return ThemeData(
    colorScheme: scheme,
    useMaterial3: true,
    scaffoldBackgroundColor: const Color(0xFFF5F7FB),
    appBarTheme: const AppBarTheme(backgroundColor: AppColors.navy, foregroundColor: Colors.white, elevation: 0),
    navigationBarTheme: NavigationBarThemeData(indicatorColor: AppColors.gold.withValues(alpha: 0.25)),
    inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder(), isDense: true),
    cardTheme: const CardThemeData(margin: EdgeInsets.symmetric(horizontal: 12, vertical: 6), elevation: 0.5),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(backgroundColor: AppColors.royal, minimumSize: const Size(0, 48))),
  );
}
