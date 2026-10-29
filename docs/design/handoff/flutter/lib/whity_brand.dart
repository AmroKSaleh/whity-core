// Whity brand extension for Flutter — use beside flutter/whity_tokens (AppTokens).
// Generated from the Whity design system v2.0. Adds what AppTokens does not carry:
// the plane, the blueprint, the mark and the logo sizes.
import 'package:flutter/material.dart';

@immutable
class WhityBrand extends ThemeExtension<WhityBrand> {
  const WhityBrand({required this.plane, required this.planeDeep, required this.markSheet, required this.blueprint, required this.blueprintLine, required this.blueprintLineStrong});
  final Color plane;            // the substrate under approved sheets and hovered cards (= brand)
  final Color planeDeep;        // the substrate under a primary-filled button
  final Color markSheet;        // always white
  final Color blueprint;        // does not invert with the theme
  final Color blueprintLine;
  final Color blueprintLineStrong;

  static const light = WhityBrand(plane: Color(0xFF0961BB), planeDeep: Color(0xFF002C5E), markSheet: Color(0xFFFFFFFF), blueprint: Color(0xFF0961BB), blueprintLine: Color(0x1FFFFFFF), blueprintLineStrong: Color(0x3DFFFFFF));
  static const dark = WhityBrand(plane: Color(0xFF59A0F9), planeDeep: Color(0xFF0B4C92), markSheet: Color(0xFFFFFFFF), blueprint: Color(0xFF0961BB), blueprintLine: Color(0x1FFFFFFF), blueprintLineStrong: Color(0x3DFFFFFF));

  /// The plane: a hard offset down-left, never blurred, never mirrored in RTL.
  static List<BoxShadow> planeShadow(Color c, {bool hover = false}) => [BoxShadow(color: c, offset: hover ? const Offset(-5, 5) : const Offset(-3, 3), blurRadius: 0, spreadRadius: 0)];

  static const double planeOffset = 3;
  static const double planeLift = 5;
  static const double logoMinMark = 16;
  static const double logoMinHorizontalHeight = 20; // the horizontal lockup is at least 20 px tall (6 mm in print)
  static const double logoMinStacked = 48;
  static const double markCutSwitch = 24; // below this, use the 20 px or 16 px pixel cut
  static const Duration fast = Duration(milliseconds: 120);
  static const Duration base = Duration(milliseconds: 180);
  static const Duration slow = Duration(milliseconds: 250);

  @override
  WhityBrand copyWith({Color? plane, Color? planeDeep, Color? markSheet, Color? blueprint, Color? blueprintLine, Color? blueprintLineStrong}) => WhityBrand(plane: plane ?? this.plane, planeDeep: planeDeep ?? this.planeDeep, markSheet: markSheet ?? this.markSheet, blueprint: blueprint ?? this.blueprint, blueprintLine: blueprintLine ?? this.blueprintLine, blueprintLineStrong: blueprintLineStrong ?? this.blueprintLineStrong);

  @override
  WhityBrand lerp(ThemeExtension<WhityBrand>? other, double t) {
    if (other is! WhityBrand) return this;
    return WhityBrand(plane: Color.lerp(plane, other.plane, t)!, planeDeep: Color.lerp(planeDeep, other.planeDeep, t)!, markSheet: Color.lerp(markSheet, other.markSheet, t)!, blueprint: Color.lerp(blueprint, other.blueprint, t)!, blueprintLine: Color.lerp(blueprintLine, other.blueprintLine, t)!, blueprintLineStrong: Color.lerp(blueprintLineStrong, other.blueprintLineStrong, t)!);
  }
}
