import {
  registerPluginScreen,
  resolvePluginScreen,
  unregisterPluginScreen,
  type PluginScreenComponent,
} from '@/lib/plugin-ui-registry';
import type { PluginFeature } from '@/lib/plugin-features';

function makeFeature(overrides: Partial<PluginFeature> = {}): PluginFeature {
  return {
    id: 'hello-greetings',
    plugin: 'HelloWorld',
    label: 'Greetings',
    icon: 'message-circle',
    group: 'plugins',
    order: 10,
    screen: 'crud',
    resource: { basePath: '/api/hello/greetings', titleField: 'message' },
    requiredPermission: 'hello:view',
    ...overrides,
  };
}

const ScreenA: PluginScreenComponent = () => null;
const ScreenB: PluginScreenComponent = () => null;

describe('plugin UI registry', () => {
  afterEach(() => {
    unregisterPluginScreen('hello-greetings');
    unregisterPluginScreen('custom-thing');
  });

  it('resolves an unknown id to undefined', () => {
    expect(resolvePluginScreen('does-not-exist')).toBeUndefined();
  });

  it('returns a registered component by feature id', () => {
    registerPluginScreen('hello-greetings', ScreenA);
    expect(resolvePluginScreen('hello-greetings')).toBe(ScreenA);
  });

  it('lets a later registration override an earlier one (last wins)', () => {
    registerPluginScreen('hello-greetings', ScreenA);
    registerPluginScreen('hello-greetings', ScreenB);
    expect(resolvePluginScreen('hello-greetings')).toBe(ScreenB);
  });

  it('unregisters a component and reports whether one was removed', () => {
    registerPluginScreen('custom-thing', ScreenA);
    expect(unregisterPluginScreen('custom-thing')).toBe(true);
    expect(resolvePluginScreen('custom-thing')).toBeUndefined();
    expect(unregisterPluginScreen('custom-thing')).toBe(false);
  });

  it('keeps registrations isolated per id', () => {
    registerPluginScreen('hello-greetings', ScreenA);
    registerPluginScreen('custom-thing', ScreenB);
    expect(resolvePluginScreen('hello-greetings')).toBe(ScreenA);
    expect(resolvePluginScreen('custom-thing')).toBe(ScreenB);
  });

  it('accepts components typed against the PluginFeature prop contract', () => {
    // Compile-time contract check: a component consuming the feature prop is
    // assignable to PluginScreenComponent.
    const Typed: PluginScreenComponent = ({ feature }) =>
      feature.label.length > 0 ? null : null;
    registerPluginScreen('custom-thing', Typed);
    expect(resolvePluginScreen('custom-thing')).toBe(Typed);
    // Sanity: the fixture used across plugin tests satisfies PluginFeature.
    expect(makeFeature().screen).toBe('crud');
  });
});
