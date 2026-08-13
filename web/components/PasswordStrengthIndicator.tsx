'use client';

/**
 * PasswordStrengthIndicator
 *
 * Renders a 4-bar strength meter beneath a password input.  The score is
 * computed from four independent criteria so each bar lights up as the user
 * types, giving immediate, actionable feedback without revealing a magic
 * algorithm.
 *
 * Criteria (one point each):
 *  1. Length ≥ 8 characters   (meets the minimum policy)
 *  2. Contains an uppercase letter
 *  3. Contains a digit
 *  4. Contains a special character
 *
 * Only semantic design tokens are used (no raw Tailwind colour classes like
 * "red-500") so the indicator respects both light and dark themes.
 */

import { useTranslation } from '@amroksaleh/features/i18n';

export type StrengthLevel = 'weak' | 'fair' | 'good' | 'strong';

interface Criteria {
  minLength: boolean;
  hasUppercase: boolean;
  hasNumber: boolean;
  hasSpecial: boolean;
}

function evaluate(password: string): Criteria {
  return {
    minLength: password.length >= 8,
    hasUppercase: /[A-Z]/.test(password),
    hasNumber: /[0-9]/.test(password),
    hasSpecial: /[^A-Za-z0-9]/.test(password),
  };
}

function score(criteria: Criteria): number {
  return (
    (criteria.minLength ? 1 : 0) +
    (criteria.hasUppercase ? 1 : 0) +
    (criteria.hasNumber ? 1 : 0) +
    (criteria.hasSpecial ? 1 : 0)
  );
}

function level(s: number): StrengthLevel {
  if (s <= 1) return 'weak';
  if (s === 2) return 'fair';
  if (s === 3) return 'good';
  return 'strong';
}

/**
 * The four strength labels reach `t()` through a lookup (`LEVEL_META[lvl]`)
 * rather than a literal, which no static scanner can read — so they are
 * declared here and the extractor takes the catalogue from this block. The
 * English stays on the record as the runtime fallback, exactly as the sign-in
 * screen's SSO error map does.
 *
 * The four sit under `password.strength.level.` rather than alongside
 * `password.strength.announcement`, so "everything under this prefix is the
 * lookup" stays a true statement — see the declaration drift test.
 *
 * @i18n-keys auth
 *   password.strength.level.weak = Weak
 *   password.strength.level.fair = Fair
 *   password.strength.level.good = Good
 *   password.strength.level.strong = Strong
 */
export const LEVEL_META: Record<
  StrengthLevel,
  { key: string; label: string; bars: number; barClass: string; labelClass: string }
> = {
  weak:   { key: 'password.strength.level.weak',   label: 'Weak',   bars: 1, barClass: 'bg-destructive',     labelClass: 'text-destructive' },
  fair:   { key: 'password.strength.level.fair',   label: 'Fair',   bars: 2, barClass: 'bg-warning',          labelClass: 'text-warning' },
  good:   { key: 'password.strength.level.good',   label: 'Good',   bars: 3, barClass: 'bg-muted-foreground', labelClass: 'text-muted-foreground' },
  strong: { key: 'password.strength.level.strong', label: 'Strong', bars: 4, barClass: 'bg-success',          labelClass: 'text-success' },
};

interface PasswordStrengthIndicatorProps {
  password: string;
}

export function PasswordStrengthIndicator({
  password,
}: PasswordStrengthIndicatorProps) {
  // Called before the early return: a hook must run on every render.
  const t = useTranslation('auth');

  if (!password) return null;

  const criteria = evaluate(password);
  const s = score(criteria);
  const lvl = level(s);
  const meta = LEVEL_META[lvl];
  const label = t(meta.key, meta.label);

  return (
    <div
      className="space-y-1"
      aria-live="polite"
      aria-label={t('password.strength.announcement', 'Password strength: {level}', { level: label })}
    >
      <div className="flex gap-1">
        {Array.from({ length: 4 }, (_, i) => (
          <div
            key={i}
            className={[
              'h-1 flex-1 rounded-full transition-colors duration-200',
              i < meta.bars ? meta.barClass : 'bg-border',
            ].join(' ')}
            aria-hidden="true"
          />
        ))}
      </div>
      <p className={['text-xs', meta.labelClass].join(' ')}>
        {label}
      </p>
    </div>
  );
}
