import { audiencePreviewRequest } from '@/app/(protected)/admin/document-route-templates/types';

/**
 * Which preview endpoint answers for which rule kind (#1027).
 *
 * WHY THIS HAS A TEST OF ITS OWN
 * ------------------------------
 * This function is three lines of routing that were WRONG in the first draft,
 * and the wrongness was invisible to every gate. `POST /user-groups/preview`
 * refuses `rule_kind: "group"` — a group cannot be defined as another group
 * (#999) — so a group stage, which is the single most important node type in
 * this feature, came back with "a user group cannot be defined as another user
 * group" printed where its size should be. TypeScript was happy, the lint was
 * happy, the request succeeded in the sense of returning a response, and the
 * card rendered a plausible-looking error string.
 *
 * It was found by running the real server against the real seed, and this test
 * is what stops it coming back.
 *
 * The expectations are written as the ENDPOINT AND METHOD an author's browser
 * would send, derived from what the server actually accepts — not from re-reading
 * the function.
 */
describe('audiencePreviewRequest', () => {
  it('previews a GROUP stage through the stored group, not the draft endpoint', () => {
    const request = audiencePreviewRequest('group', { group_id: 7 });

    expect(request).toEqual({ url: '/api/v1/user-groups/7/preview' });
  });

  it('previews a ROLE stage through the draft endpoint, as a POST carrying the rule', () => {
    const request = audiencePreviewRequest('role', { role_id: 4 });

    expect(request?.url).toBe('/api/v1/user-groups/preview');
    expect(request?.init?.method).toBe('POST');
    expect(JSON.parse(String(request?.init?.body))).toEqual({
      rule_kind: 'role',
      rule_config: { role_id: 4 },
    });
  });

  it('previews a plugin-contributed kind the same way as a core one', () => {
    // Nothing here enumerates the core kinds, so a plugin's rule is previewed
    // rather than silently skipped — the registry is the server's business.
    const request = audiencePreviewRequest('acme:committee', { committee_id: 2 });

    expect(request?.url).toBe('/api/v1/user-groups/preview');
  });

  it('asks nothing for a stage whose rule is not configured yet', () => {
    // A half-built stage is not a broken one. Asking would return a 422 that the
    // card would render as though the rule were wrong rather than unfinished.
    expect(audiencePreviewRequest('role', {})).toBeNull();
  });

  it('asks nothing for a group stage with no group chosen', () => {
    expect(audiencePreviewRequest('group', {})).toBeNull();
    // A non-numeric id cannot address the by-id route at all; asking would build
    // `/user-groups/undefined/preview`.
    expect(audiencePreviewRequest('group', { group_id: 'seven' })).toBeNull();
  });
});
