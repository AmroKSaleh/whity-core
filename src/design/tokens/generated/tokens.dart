// Generated token definitions for Flutter
// Generated: 2026-05-16T13:30:03.150Z
// Do not edit manually - run: npm run tokens:generate

import 'package:flutter/material.dart';

class AppTokens {
  // Light mode colors
  static const lightTokens = <String, Color>{
    '$camelKey': Color(0xff00f6),
    '$camelKey': Color(0x070001),
    '$camelKey': Color(0xff00f6),
    '$camelKey': Color(0x070001),
    '$camelKey': Color(0xff00f6),
    '$camelKey': Color(0x070001),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0xff00e0),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0xff00e0),
    '$camelKey': Color(0xff002a),
    '$camelKey': Color(0xff00e0),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0xff004c),
    '$camelKey': Color(0xff00c1),
    '$camelKey': Color(0xff00c1),
    '$camelKey': Color(0xff0057),
    '$camelKey': Color(0xff00a2),
    '$camelKey': Color(0xff002a),
    '$camelKey': Color(0xc00015),
    '$camelKey': Color(0x74000d),
    '$camelKey': Color(0x2c0005),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0x070001),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0xff00e0),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0xff00c1),
    '$camelKey': Color(0xff0057),
  };

  // Dark mode colors
  static const darkTokens = <String, Color>{
    '$camelKey': Color(0x070001),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0xff00c1),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0x2c0005),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0x2c0005),
    '$camelKey': Color(0xff0057),
    '$camelKey': Color(0x2c0005),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0xff0070),
    '$camelKey': Color(0xklch(1 0 0 / 10%)),
    '$camelKey': Color(0xklch(1 0 0 / 15%)),
    '$camelKey': Color(0xff002a),
    '$camelKey': Color(0xff00a2),
    '$camelKey': Color(0xff002a),
    '$camelKey': Color(0xc00015),
    '$camelKey': Color(0x74000d),
    '$camelKey': Color(0x2c0005),
    '$camelKey': Color(0x140002),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0xdc0000),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0x2c0005),
    '$camelKey': Color(0xff00eb),
    '$camelKey': Color(0xklch(1 0 0 / 10%)),
    '$camelKey': Color(0xff002a),
  };

  // Border radius
  static const double radiusBase = 10.0; // 0.625rem
  static const double radiusSm = 6.0;
  static const double radiusMd = 8.0;
  static const double radiusLg = 10.0;
  static const double radiusXl = 14.0;
  static const double radius2xl = 18.0;
  static const double radius3xl = 22.0;
  static const double radius4xl = 26.0;

  // Font families
  static const String fontSans = 'Inter';
  static const String fontMono = 'Roboto Mono';
  static const String fontHeading = 'Inter';

  // Helper to get theme colors
  static Map<String, Color> getTokens(bool isDark) {
    return isDark ? darkTokens : lightTokens;
  }
}
