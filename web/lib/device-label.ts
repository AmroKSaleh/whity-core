/**
 * Friendly platform + device-name formatting for the "Devices" settings list
 * (native-client long-lived credentials — DeviceApiHandler / #409), as a
 * frontend-only companion to the backend's DeviceLabel::fromUserAgent() (used
 * for the separate interactive-*sessions* list, see SessionsSettings).
 *
 * DeviceApiHandler validates `platform` against a fixed enum (windows/macos/
 * linux/ios/android/other) but stores/returns the client-supplied `name`
 * completely verbatim with no quality check — a device can enroll with a name
 * that is blank, generic, or a bare client/framework identifier (e.g.
 * "flutter", the name of the mobile framework rather than an actual device),
 * never a real human-facing device string. This combines the TRUSTED platform
 * with the free-text name, falling back to the platform label alone when the
 * name doesn't carry real information — so a device can never render as a
 * bare raw string.
 */

const PLATFORM_LABELS: Record<string, string> = {
  windows: 'Windows PC',
  macos: 'Mac',
  linux: 'Linux PC',
  ios: 'iPhone/iPad',
  android: 'Android device',
  other: 'Device',
};

/** Friendly platform name, e.g. "windows" -> "Windows PC". Unknown -> "Device". */
export function platformLabel(platform: string): string {
  return PLATFORM_LABELS[platform.trim().toLowerCase()] ?? 'Device';
}

/**
 * Generic/placeholder strings that carry no real device information — seen in
 * the wild as client-supplied `name` values (e.g. a client sending its own
 * framework/runtime name instead of a device name).
 */
const LOW_QUALITY_NAMES = new Set([
  'flutter',
  'dart',
  'react native',
  'reactnative',
  'device',
  'unknown',
  'unknown device',
  'app',
  'client',
  'test',
  'default',
  'n/a',
]);

/** True when `name` is missing, blank, or a known low-information placeholder. */
export function isLowQualityDeviceName(name: string | null | undefined, platform: string): boolean {
  const trimmed = (name ?? '').trim().toLowerCase();
  if (trimmed === '') {
    return true;
  }
  if (trimmed === platform.trim().toLowerCase()) {
    return true;
  }
  return LOW_QUALITY_NAMES.has(trimmed);
}

/**
 * The label to render for a device row: the platform-derived name alone when
 * `name` is missing/blank/low-quality, otherwise the platform combined with
 * the client-supplied name (e.g. "iPhone/iPad — Amro's Phone"). Never returns
 * the raw client-supplied string on its own.
 */
export function deviceDisplayName(name: string | null | undefined, platform: string): string {
  const label = platformLabel(platform);
  if (isLowQualityDeviceName(name, platform)) {
    return label;
  }
  return `${label} — ${(name as string).trim()}`;
}
