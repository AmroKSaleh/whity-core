import { resolveNavGroups, identityTranslate } from '@amroksaleh/features/nav';
import type { NavConfig } from '@amroksaleh/features/nav';

/**
 * Tests for the app-shell nav contract's resolver (@amroksaleh/features/nav):
 * the one piece of logic the contract owns — label translation and
 * active-route matching — bridging a client-authored `NavConfig` into
 * `AppSidebar`'s `AppSidebarNavGroup[]` props.
 */

const config: NavConfig = {
  groups: [
    {
      id: 'general',
      label: 'General',
      translationKey: 'nav.group.general',
      items: [
        { id: 'home', label: 'Home', href: '/' },
        {
          id: 'demo-catalog',
          label: 'Demo Catalog',
          translationKey: 'nav.demoCatalog',
          href: '/demo-catalog',
          activeMatch: '/demo-catalog/*',
        },
      ],
    },
  ],
};

describe('resolveNavGroups', () => {
  it('renders literal labels as-is when no translator is supplied', () => {
    const groups = resolveNavGroups(config, '/');

    expect(groups[0].label).toBe('General');
    expect(groups[0].items[0].label).toBe('Home');
  });

  it('resolves translationKey through an injected t(), falling back to nothing extra when absent', () => {
    const t = (key: string) => ({ 'nav.group.general': 'Général', 'nav.demoCatalog': 'Catalogue démo' })[key] ?? key;

    const groups = resolveNavGroups(config, '/', t);

    expect(groups[0].label).toBe('Général');
    expect(groups[0].items[1].label).toBe('Catalogue démo');
    // An item with no translationKey keeps its literal label even with t() supplied.
    expect(groups[0].items[0].label).toBe('Home');
  });

  it('marks an item active on an exact path match', () => {
    const groups = resolveNavGroups(config, '/');
    expect(groups[0].items[0].active).toBe(true);
    expect(groups[0].items[1].active).toBe(false);
  });

  it('marks a "/*" activeMatch item active on its own path and any sub-path', () => {
    expect(resolveNavGroups(config, '/demo-catalog')[0].items[1].active).toBe(true);
    expect(resolveNavGroups(config, '/demo-catalog/42')[0].items[1].active).toBe(true);
    expect(resolveNavGroups(config, '/demo-catalog/new')[0].items[1].active).toBe(true);
  });

  it('does not treat a "/*" activeMatch as a substring match against an unrelated path', () => {
    const groups = resolveNavGroups(config, '/demo-catalog-archive');
    expect(groups[0].items[1].active).toBe(false);
  });

  it('never marks two different items active for the same path', () => {
    const groups = resolveNavGroups(config, '/demo-catalog/1');
    const activeCount = groups[0].items.filter((item) => item.active).length;
    expect(activeCount).toBe(1);
  });

  it('identityTranslate returns the key unchanged', () => {
    expect(identityTranslate('any.key')).toBe('any.key');
  });
});
