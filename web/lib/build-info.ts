/**
 * The identity of the bundle this server is serving (WHIT-587).
 *
 * Reading it used to require `docker exec <web> cat /repo/web/.next/BUILD_ID`,
 * so an API/UI version mismatch was undetectable from outside the box —
 * including by monitoring. A real v0.1.0 → v0.2.0 update passed its documented
 * success criterion (`/api/health` reported 0.2.0) while the served bundle was
 * 268 frontend commits older, and nothing reported it.
 *
 * Every value here is FROZEN INTO THE BUILD by `env` in next.config.ts, not
 * read from the running process's environment. That distinction is the whole
 * point: an environment variable describes the container that started, while
 * these describe the artefact it loaded. A restart after a checkout — the
 * exact mechanism that caused the incident — changes the former and must not
 * be able to change the latter.
 *
 * Values are read through dot access on `process.env` because that is the
 * form Next's `env` config statically replaces at build time.
 */
export interface BuildInfo {
  /** Next's BUILD_ID for the served bundle; equals `commit` when git was readable at build time. */
  build_id: string | null;
  /** The checkout the bundle was built from; null when the build had no git metadata. */
  commit: string | null;
  /** `CoreVersion::VERSION` as of the build — directly comparable to `/api/health`'s `version`. */
  core_version: string | null;
  /** When the build ran (ISO 8601). */
  built_at: string | null;
}

export function buildInfo(): BuildInfo {
  return {
    build_id: present(process.env.WHITY_BUILD_ID),
    commit: present(process.env.WHITY_BUILD_COMMIT),
    core_version: present(process.env.WHITY_BUILD_CORE_VERSION),
    built_at: present(process.env.WHITY_BUILT_AT),
  };
}

/**
 * An unset value and an empty one mean the same thing here — the build could
 * not determine it. Reporting `""` would read as a real answer to a monitor
 * comparing strings.
 */
function present(value: string | undefined): string | null {
  return value === undefined || value === '' ? null : value;
}
