/**
 * #409 — frontend platform/name formatting for the Devices settings list.
 * Covers the reported "flutter" device name bug: a client-supplied name that
 * is a bare framework identifier must fall back to the platform label, never
 * render on its own.
 */
import { deviceDisplayName, isLowQualityDeviceName, platformLabel } from '@/lib/device-label';

describe('platformLabel', () => {
  it.each([
    ['windows', 'Windows PC'],
    ['macos', 'Mac'],
    ['linux', 'Linux PC'],
    ['ios', 'iPhone/iPad'],
    ['android', 'Android device'],
    ['other', 'Device'],
  ])('maps %s -> %s', (platform, expected) => {
    expect(platformLabel(platform)).toBe(expected);
  });

  it('is case-insensitive and falls back to "Device" for an unrecognized platform', () => {
    expect(platformLabel('WINDOWS')).toBe('Windows PC');
    expect(platformLabel('toaster')).toBe('Device');
  });
});

describe('isLowQualityDeviceName', () => {
  it('treats missing/blank names as low quality', () => {
    expect(isLowQualityDeviceName(null, 'android')).toBe(true);
    expect(isLowQualityDeviceName(undefined, 'android')).toBe(true);
    expect(isLowQualityDeviceName('   ', 'android')).toBe(true);
  });

  it('treats the reported "flutter" framework name as low quality', () => {
    expect(isLowQualityDeviceName('flutter', 'android')).toBe(true);
    expect(isLowQualityDeviceName('Flutter', 'ios')).toBe(true);
  });

  it('treats a name equal to the platform value itself as low quality', () => {
    expect(isLowQualityDeviceName('android', 'android')).toBe(true);
  });

  it('does not flag a real device name as low quality', () => {
    expect(isLowQualityDeviceName("Amro's Phone", 'ios')).toBe(false);
    expect(isLowQualityDeviceName('Studio Desktop', 'macos')).toBe(false);
  });
});

describe('deviceDisplayName', () => {
  it('combines platform and name for a real device name', () => {
    expect(deviceDisplayName("Amro's Phone", 'ios')).toBe("iPhone/iPad — Amro's Phone");
  });

  it('falls back to the bare platform label for a low-quality name (the "flutter" bug)', () => {
    expect(deviceDisplayName('flutter', 'android')).toBe('Android device');
  });

  it('falls back to the bare platform label when the name is missing', () => {
    expect(deviceDisplayName(null, 'windows')).toBe('Windows PC');
    expect(deviceDisplayName('', 'linux')).toBe('Linux PC');
  });

  it('never renders the raw low-quality string on its own', () => {
    const label = deviceDisplayName('flutter', 'android');
    expect(label).not.toBe('flutter');
    expect(label.toLowerCase()).not.toContain('flutter');
  });
});
