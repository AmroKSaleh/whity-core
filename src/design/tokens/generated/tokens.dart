// Generated token definitions for Flutter / Material Design 3.
// Source of truth: src/design/tokens/base.json (version 2.0.0).
// Do not edit manually - run: npm run tokens:generate

import 'package:flutter/material.dart';

class AppTokens {
  // Light mode colors
  static const lightColors = <String, Color>{
    'background': Color(0xFFF8F8F8),
    'foreground': Color(0xFF0A0A0A),
    'card': Color(0xFFFFFFFF),
    'cardForeground': Color(0xFF0A0A0A),
    'popover': Color(0xFFFFFFFF),
    'popoverForeground': Color(0xFF0A0A0A),
    'primary': Color(0xFF0961BB),
    'primaryForeground': Color(0xFFFAFAFA),
    'secondary': Color(0xFFF5F5F5),
    'secondaryForeground': Color(0xFF171717),
    'muted': Color(0xFFF5F5F5),
    'mutedForeground': Color(0xFF737373),
    'accent': Color(0xFFF5F5F5),
    'accentForeground': Color(0xFF171717),
    'brand': Color(0xFF0961BB),
    'brandForeground': Color(0xFFFAFAFA),
    'destructive': Color(0xFFE7000B),
    'success': Color(0xFF007F35),
    'successForeground': Color(0xFFFAFAFA),
    'warning': Color(0xFFDA8B00),
    'warningForeground': Color(0xFF171717),
    'error': Color(0xFFCC2827),
    'errorForeground': Color(0xFFFAFAFA),
    'info': Color(0xFF0070B5),
    'infoForeground': Color(0xFFFAFAFA),
    'border': Color(0xFFE5E5E5),
    'input': Color(0xFFE5E5E5),
    'ring': Color(0xFF0961BB),
    'chart1': Color(0xFF0961BB),
    'chart2': Color(0xFF737373),
    'chart3': Color(0xFF525252),
    'chart4': Color(0xFF404040),
    'chart5': Color(0xFF262626),
    'sidebar': Color(0xFFFAFAFA),
    'sidebarForeground': Color(0xFF0A0A0A),
    'sidebarPrimary': Color(0xFF0961BB),
    'sidebarPrimaryForeground': Color(0xFFFAFAFA),
    'sidebarAccent': Color(0xFFF5F5F5),
    'sidebarAccentForeground': Color(0xFF171717),
    'sidebarBorder': Color(0xFFE5E5E5),
    'sidebarRing': Color(0xFF0961BB),
  };

  // Dark mode colors
  static const darkColors = <String, Color>{
    'background': Color(0xFF0A0A0A),
    'foreground': Color(0xFFFAFAFA),
    'card': Color(0xFF171717),
    'cardForeground': Color(0xFFFAFAFA),
    'popover': Color(0xFF171717),
    'popoverForeground': Color(0xFFFAFAFA),
    'primary': Color(0xFF59A0F9),
    'primaryForeground': Color(0xFF171717),
    'secondary': Color(0xFF262626),
    'secondaryForeground': Color(0xFFFAFAFA),
    'muted': Color(0xFF262626),
    'mutedForeground': Color(0xFFA1A1A1),
    'accent': Color(0xFF262626),
    'accentForeground': Color(0xFFFAFAFA),
    'brand': Color(0xFF59A0F9),
    'brandForeground': Color(0xFF171717),
    'destructive': Color(0xFFFF6467),
    'success': Color(0xFF4AC06C),
    'successForeground': Color(0xFF171717),
    'warning': Color(0xFFFAB72A),
    'warningForeground': Color(0xFF171717),
    'error': Color(0xFFFA6863),
    'errorForeground': Color(0xFF171717),
    'info': Color(0xFF30AFF8),
    'infoForeground': Color(0xFF171717),
    'border': Color(0x1AFFFFFF),
    'input': Color(0x26FFFFFF),
    'ring': Color(0xFF59A0F9),
    'chart1': Color(0xFF59A0F9),
    'chart2': Color(0xFF737373),
    'chart3': Color(0xFFA1A1A1),
    'chart4': Color(0xFF525252),
    'chart5': Color(0xFF404040),
    'sidebar': Color(0xFF0F0F0F),
    'sidebarForeground': Color(0xFFFAFAFA),
    'sidebarPrimary': Color(0xFF59A0F9),
    'sidebarPrimaryForeground': Color(0xFF171717),
    'sidebarAccent': Color(0xFF262626),
    'sidebarAccentForeground': Color(0xFFFAFAFA),
    'sidebarBorder': Color(0x1AFFFFFF),
    'sidebarRing': Color(0xFF59A0F9),
  };

  // Font families
  static const String fontSans = 'Noto Sans';
  static const String fontMono = 'Geist Mono';
  static const String fontHeading = 'Noto Sans';

  // Font sizes (logical pixels)
  static const fontSize = <String, double>{
    'xs': 12.0,
    'sm': 14.0,
    'base': 16.0,
    'lg': 18.0,
    'xl': 20.0,
    '2xl': 24.0,
    '3xl': 30.0,
    '4xl': 36.0,
  };

  // Font weights
  static const fontWeight = <String, FontWeight>{
    'normal': FontWeight.w400,
    'medium': FontWeight.w500,
    'semibold': FontWeight.w600,
    'bold': FontWeight.w700,
  };

  // Line heights (unitless multipliers)
  static const lineHeight = <String, double>{
    'none': 1.000,
    'tight': 1.250,
    'snug': 1.375,
    'normal': 1.500,
    'relaxed': 1.625,
  };

  // Border radius (logical pixels)
  static const radius = <String, double>{
    'base': 10.0,
    'sm': 4.0,
    'md': 6.0,
    'lg': 8.0,
    'xl': 12.0,
    '2xl': 16.0,
    '3xl': 20.0,
    '4xl': 24.0,
  };

  // Spacing scale (logical pixels)
  static const spacing = <String, double>{
    '0': 0.0,
    '1': 4.0,
    '2': 8.0,
    '3': 12.0,
    '4': 16.0,
    '6': 24.0,
    '8': 32.0,
  };

  // Helper to resolve theme colors
  static Map<String, Color> colors(bool isDark) {
    return isDark ? darkColors : lightColors;
  }
}
