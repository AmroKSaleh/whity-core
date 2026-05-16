#!/usr/bin/env node

/**
 * Design Token Generator
 *
 * Generates token files for different platforms from the master token definitions.
 * Supports: CSS, Dart (Flutter), JSON
 */

const fs = require('fs');
const path = require('path');

// Master token definitions (from web/app/globals.css)
const tokens = {
  light: {
    background: 'oklch(1 0 0)',
    foreground: 'oklch(0.145 0 0)',
    card: 'oklch(1 0 0)',
    'card-foreground': 'oklch(0.145 0 0)',
    popover: 'oklch(1 0 0)',
    'popover-foreground': 'oklch(0.145 0 0)',
    primary: 'oklch(0.205 0 0)',
    'primary-foreground': 'oklch(0.985 0 0)',
    secondary: 'oklch(0.97 0 0)',
    'secondary-foreground': 'oklch(0.205 0 0)',
    muted: 'oklch(0.97 0 0)',
    'muted-foreground': 'oklch(0.556 0 0)',
    accent: 'oklch(0.97 0 0)',
    'accent-foreground': 'oklch(0.205 0 0)',
    destructive: 'oklch(0.577 0.245 27.325)',
    border: 'oklch(0.922 0 0)',
    input: 'oklch(0.922 0 0)',
    ring: 'oklch(0.708 0 0)',
    'chart-1': 'oklch(0.87 0 0)',
    'chart-2': 'oklch(0.556 0 0)',
    'chart-3': 'oklch(0.439 0 0)',
    'chart-4': 'oklch(0.371 0 0)',
    'chart-5': 'oklch(0.269 0 0)',
    sidebar: 'oklch(0.985 0 0)',
    'sidebar-foreground': 'oklch(0.145 0 0)',
    'sidebar-primary': 'oklch(0.205 0 0)',
    'sidebar-primary-foreground': 'oklch(0.985 0 0)',
    'sidebar-accent': 'oklch(0.97 0 0)',
    'sidebar-accent-foreground': 'oklch(0.205 0 0)',
    'sidebar-border': 'oklch(0.922 0 0)',
    'sidebar-ring': 'oklch(0.708 0 0)',
    radius: '0.625rem',
  },
  dark: {
    background: 'oklch(0.145 0 0)',
    foreground: 'oklch(0.985 0 0)',
    card: 'oklch(0.205 0 0)',
    'card-foreground': 'oklch(0.985 0 0)',
    popover: 'oklch(0.205 0 0)',
    'popover-foreground': 'oklch(0.985 0 0)',
    primary: 'oklch(0.922 0 0)',
    'primary-foreground': 'oklch(0.205 0 0)',
    secondary: 'oklch(0.269 0 0)',
    'secondary-foreground': 'oklch(0.985 0 0)',
    muted: 'oklch(0.269 0 0)',
    'muted-foreground': 'oklch(0.708 0 0)',
    accent: 'oklch(0.269 0 0)',
    'accent-foreground': 'oklch(0.985 0 0)',
    destructive: 'oklch(0.704 0.191 22.216)',
    border: 'oklch(1 0 0 / 10%)',
    input: 'oklch(1 0 0 / 15%)',
    ring: 'oklch(0.556 0 0)',
    'chart-1': 'oklch(0.87 0 0)',
    'chart-2': 'oklch(0.556 0 0)',
    'chart-3': 'oklch(0.439 0 0)',
    'chart-4': 'oklch(0.371 0 0)',
    'chart-5': 'oklch(0.269 0 0)',
    sidebar: 'oklch(0.205 0 0)',
    'sidebar-foreground': 'oklch(0.985 0 0)',
    'sidebar-primary': 'oklch(0.488 0.243 264.376)',
    'sidebar-primary-foreground': 'oklch(0.985 0 0)',
    'sidebar-accent': 'oklch(0.269 0 0)',
    'sidebar-accent-foreground': 'oklch(0.985 0 0)',
    'sidebar-border': 'oklch(1 0 0 / 10%)',
    'sidebar-ring': 'oklch(0.556 0 0)',
    radius: '0.625rem',
  },
};

const fontsTokens = {
  'font-sans': "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
  'font-mono': "'Geist Mono', monospace",
  'font-heading': "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
};

// Utilities
function toCamelCase(str) {
  return str.replace(/-([a-z])/g, (g) => g[1].toUpperCase());
}

function toScreamingSnakeCase(str) {
  return str.toUpperCase().replace(/-/g, '_');
}

function oklchToRgb(oklch) {
  // Parse OKLCH: oklch(L C H) or oklch(L C H / alpha)
  const match = oklch.match(/oklch\(([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+))?\)/);
  if (!match) return oklch; // Return original if not OKLCH

  const L = parseFloat(match[1]);
  const C = parseFloat(match[2]);
  const H = parseFloat(match[3]);
  const alpha = match[4] ? parseFloat(match[4]) : 1;

  // Convert OKLCH to RGB (simplified)
  // This is a placeholder - for production, use a proper color library
  const h = (H * Math.PI) / 180;
  const a = L + C * 0.3 * Math.cos(h);
  const b = C * 0.3 * Math.sin(h);

  const l = L + 0.3955 * a + 0.2169 * b;
  const m = L - 0.1055 * a - 0.3693 * b;
  const s = L - 0.0469 * a + 1.8695 * b;

  const l_ = l * l * l;
  const m_ = m * m * m;
  const s_ = s * s * s;

  let r = +4.0767416621 * l_ - 3.3077363322 * m_ + 0.2309101289 * s_;
  let g = -1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193761 * s_;
  let b_ = -0.0041960771 * l_ - 0.7034186147 * m_ + 1.707614701 * s_;

  r = Math.max(0, Math.min(1, r));
  g = Math.max(0, Math.min(1, g));
  b_ = Math.max(0, Math.min(1, b_));

  const toHex = (val) => {
    const hex = Math.round(val * 255).toString(16);
    return hex.length === 1 ? '0' + hex : hex;
  };

  return `#${toHex(r)}${toHex(g)}${toHex(b_)}${alpha < 1 ? toHex(alpha) : ''}`;
}

// Generators
function generateJSON() {
  const output = {
    version: '1.0.0',
    generated: new Date().toISOString(),
    platforms: {
      web: {
        light: tokens.light,
        dark: tokens.dark,
      },
      fonts: fontsTokens,
    },
  };

  return JSON.stringify(output, null, 2);
}

function generateDart() {
  let dartCode = `// Generated token definitions for Flutter
// Generated: ${new Date().toISOString()}
// Do not edit manually - run: npm run tokens:generate

import 'package:flutter/material.dart';

class AppTokens {
  // Light mode colors
  static const lightTokens = <String, Color>{`;

  for (const [key, value] of Object.entries(tokens.light)) {
    if (key === 'radius') continue;
    const camelKey = toCamelCase(key);
    const hex = oklchToRgb(value);
    dartCode += `\n    '$camelKey': Color(0x${hex.slice(1)}),`;
  }

  dartCode += `\n  };

  // Dark mode colors
  static const darkTokens = <String, Color>{`;

  for (const [key, value] of Object.entries(tokens.dark)) {
    if (key === 'radius') continue;
    const camelKey = toCamelCase(key);
    const hex = oklchToRgb(value);
    dartCode += `\n    '$camelKey': Color(0x${hex.slice(1)}),`;
  }

  dartCode += `\n  };

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
`;

  return dartCode;
}

function generateTokensJson() {
  const output = {
    light: tokens.light,
    dark: tokens.dark,
    fonts: fontsTokens,
  };

  return JSON.stringify(output, null, 2);
}

// Main
const command = process.argv[2] || 'all';
const outputDir = path.join(__dirname, 'generated');

// Create output directory if it doesn't exist
if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir, { recursive: true });
}

try {
  if (command === 'all' || command === 'json') {
    fs.writeFileSync(
      path.join(outputDir, 'tokens.json'),
      generateTokensJson(),
      'utf8'
    );
    console.log('✓ Generated: tokens.json');
  }

  if (command === 'all' || command === 'dart') {
    fs.writeFileSync(
      path.join(outputDir, 'tokens.dart'),
      generateDart(),
      'utf8'
    );
    console.log('✓ Generated: tokens.dart');
  }

  if (command === 'all' || command === 'web') {
    // Already in web/app/globals.css, but can document the generation
    console.log('✓ Web tokens: web/app/globals.css');
  }

  console.log('\nToken generation complete!');
} catch (error) {
  console.error('Error generating tokens:', error);
  process.exit(1);
}
