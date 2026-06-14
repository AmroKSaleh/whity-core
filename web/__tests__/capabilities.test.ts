import { RELATIONS_MANAGE, parsePermissions } from '@/lib/capabilities';

/**
 * `parsePermissions` is the fail-closed parser the bespoke relations admin page
 * uses to decide whether to show write controls. Its security contract is: it
 * NEVER throws and ONLY returns `string[]`. Any payload that does not match the
 * `{ data: { permissions: string[] } }` shape collapses to `[]`, so the UI hides
 * write affordances rather than dangling controls that would 403 on submit.
 */
describe('parsePermissions', () => {
  it('returns the permission slugs for a well-formed payload', () => {
    expect(
      parsePermissions({
        data: { permissions: ['relations:read', 'relations:manage'] },
      })
    ).toEqual(['relations:read', 'relations:manage']);
  });

  it('returns [] for an empty permissions array (happy path, no perms)', () => {
    expect(parsePermissions({ data: { permissions: [] } })).toEqual([]);
  });

  describe('fails closed to [] for non-object input', () => {
    it('null', () => {
      expect(parsePermissions(null)).toEqual([]);
    });

    it('undefined', () => {
      expect(parsePermissions(undefined)).toEqual([]);
    });

    it('a string', () => {
      expect(parsePermissions('relations:manage')).toEqual([]);
    });

    it('a number', () => {
      expect(parsePermissions(42)).toEqual([]);
    });

    it('a boolean', () => {
      expect(parsePermissions(true)).toEqual([]);
    });
  });

  it('fails closed to [] when the object is missing `data`', () => {
    expect(parsePermissions({ permissions: ['relations:manage'] })).toEqual([]);
  });

  it('fails closed to [] when `data` is null', () => {
    expect(parsePermissions({ data: null })).toEqual([]);
  });

  it('fails closed to [] when `data` is missing `permissions`', () => {
    expect(parsePermissions({ data: { roles: ['admin'] } })).toEqual([]);
  });

  it('fails closed to [] when `permissions` is not an array', () => {
    expect(
      parsePermissions({ data: { permissions: 'relations:manage' } })
    ).toEqual([]);
    expect(parsePermissions({ data: { permissions: { a: 1 } } })).toEqual([]);
    expect(parsePermissions({ data: { permissions: null } })).toEqual([]);
  });

  it('drops non-string elements, keeping only the string slugs', () => {
    expect(
      parsePermissions({
        data: {
          permissions: ['relations:read', 42, null, 'relations:manage', {}],
        },
      })
    ).toEqual(['relations:read', 'relations:manage']);
  });

  it('returns [] when every element of `permissions` is non-string', () => {
    expect(
      parsePermissions({ data: { permissions: [1, 2, null, {}, []] } })
    ).toEqual([]);
  });
});

describe('RELATIONS_MANAGE', () => {
  it('is the relations:manage slug enforced by RbacMiddleware', () => {
    expect(RELATIONS_MANAGE).toBe('relations:manage');
  });
});
