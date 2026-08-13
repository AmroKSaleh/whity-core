<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * SeedAuthTranslations migration
 *
 * Seeds the `auth` domain — the first screen converted from hardcoded English
 * to real translations (the sign-in screen, web/app/login/page.tsx) — in both
 * base languages.
 *
 * THE TEMPLATE THIS ESTABLISHES
 * -----------------------------
 * Every subsequent screen follows this shape, so the pattern is worth reading
 * once:
 *
 *  1. Pick the DOMAIN. Core domains are bare (`auth`); a plugin's are prefixed
 *     with its source slug (`acme:catalog`) — see
 *     {@see \Whity\Core\i18n\TranslationDomain} for the rule and why it matches
 *     the resource-type registry's `acme:record`.
 *  2. Pick KEYS that name the screen and the element, never the English words:
 *     `login.email.label`, not `enter_your_email`. Reworded copy is then a
 *     translation edit, not a key rename that orphans every other language.
 *  3. Seed EVERY key in EVERY enabled language, English included. English rows
 *     are not redundant: they are what an admin edits in the translations
 *     console, and what a per-tenant override overrides.
 *  4. Rows are SYSTEM DEFAULTS (`tenant_id IS NULL`). A tenant that wants
 *     different wording writes an override row through the translations API;
 *     nothing here is per-tenant.
 *
 * A missing row is not a failure: `useTranslation()` falls back to the fallback
 * text, then the key, so a half-seeded domain degrades to English rather than
 * to a blank screen.
 *
 * Idempotent: ON CONFLICT DO NOTHING on the (language_id, domain, key,
 * tenant_id) unique index. down() removes only this domain's system-default
 * rows, leaving any tenant override of the same keys to the tenant that made it.
 */
class SeedAuthTranslations
{
    /** The domain these strings belong to. Core domains are bare. */
    private const DOMAIN = 'auth';

    /**
     * key => [en, ar].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const STRINGS = [
        // --- Card chrome -----------------------------------------------------
        'login.welcome'                 => ['Welcome to {site}', 'مرحباً بك في {site}'],
        'login.subtitle'                => ['Sign in to your account to continue', 'سجّل الدخول إلى حسابك للمتابعة'],
        'login.subtitle.twoFactor'      => ['Enter your authenticator code', 'أدخل رمز تطبيق المصادقة'],
        'login.subtitle.enrollment'     => ['Set up two-factor authentication to continue', 'أعدّ المصادقة الثنائية للمتابعة'],
        'login.subtitle.workspace'      => ['Choose a workspace to continue', 'اختر مساحة عمل للمتابعة'],

        // --- Credentials form ------------------------------------------------
        'login.email.label'             => ['Email', 'البريد الإلكتروني'],
        'login.email.placeholder'       => ['Enter your email', 'أدخل بريدك الإلكتروني'],
        'login.email.required'          => ['Email is required', 'البريد الإلكتروني مطلوب'],
        'login.password.label'          => ['Password', 'كلمة المرور'],
        'login.password.placeholder'    => ['Enter your password', 'أدخل كلمة المرور'],
        'login.password.required'       => ['Password is required', 'كلمة المرور مطلوبة'],
        'login.password.forgot'         => ['Forgot password?', 'هل نسيت كلمة المرور؟'],
        'login.submit'                  => ['Sign in', 'تسجيل الدخول'],
        'login.submit.pending'          => ['Signing in...', 'جارٍ تسجيل الدخول...'],
        'login.register.prompt'         => ['New here?', 'جديد هنا؟'],
        'login.register.link'           => ['Create a workspace', 'أنشئ مساحة عمل'],
        'login.recovery.prompt'         => ['Lost your authenticator too?', 'فقدت تطبيق المصادقة أيضاً؟'],
        'login.recovery.link'           => ['Recover your account', 'استعد حسابك'],

        // --- Credentials failures --------------------------------------------
        'login.error.invalidCredentials' => ['Invalid credentials', 'بيانات الدخول غير صحيحة'],
        'login.error.generic'            => ['Login failed', 'فشل تسجيل الدخول'],
        'login.error.withStatus'         => ['Login failed ({status}): {message}', 'فشل تسجيل الدخول ({status}): {message}'],
        'login.error.transport'          => ['Login failed: {message}', 'فشل تسجيل الدخول: {message}'],

        // --- Workspace (multi-membership) selection ---------------------------
        'workspace.prompt'              => [
            'Your account has access to multiple workspaces. Choose one to continue.',
            'حسابك يملك صلاحية الوصول إلى أكثر من مساحة عمل. اختر واحدة للمتابعة.',
        ],
        'workspace.back'                => ['Back to login', 'العودة إلى تسجيل الدخول'],
        'workspace.error.notMember'     => ['You are not a member of that workspace.', 'أنت لست عضواً في مساحة العمل تلك.'],
        'workspace.error.generic'       => ['Could not select workspace', 'تعذّر اختيار مساحة العمل'],
        'workspace.error.withStatus'    => ['Workspace selection failed ({status}): {message}', 'فشل اختيار مساحة العمل ({status}): {message}'],
        'workspace.error.transport'     => ['Workspace selection failed: {message}', 'فشل اختيار مساحة العمل: {message}'],

        // --- Two-factor challenge ---------------------------------------------
        'twoFactor.instructions'        => [
            'Enter the 6-digit code from your authenticator app or a backup code',
            'أدخل الرمز المكوّن من ٦ أرقام من تطبيق المصادقة أو أحد رموز الاسترداد',
        ],
        'twoFactor.code.label'          => ['Authenticator Code', 'رمز تطبيق المصادقة'],
        'twoFactor.submit'              => ['Verify', 'تحقّق'],
        'twoFactor.submit.pending'      => ['Verifying...', 'جارٍ التحقّق...'],
        'twoFactor.back'                => ['Back to Login', 'العودة إلى تسجيل الدخول'],
        'twoFactor.error.length'        => ['Code must be exactly 6 digits', 'يجب أن يتكوّن الرمز من ٦ أرقام بالضبط'],
        'twoFactor.error.invalid'       => ['Invalid authenticator code. Please try again.', 'رمز المصادقة غير صحيح. حاول مرة أخرى.'],
        'twoFactor.error.generic'       => ['Verification failed. Please try again.', 'فشل التحقّق. حاول مرة أخرى.'],
        'twoFactor.error.transport'     => ['An error occurred. Please try again.', 'حدث خطأ. حاول مرة أخرى.'],
        'twoFactor.error.withStatus'    => ['Verification failed ({status}): {message}', 'فشل التحقّق ({status}): {message}'],
        'twoFactor.enrolled'            => [
            'Two-factor authentication is now set up. Please sign in again with your authenticator code.',
            'تم إعداد المصادقة الثنائية. سجّل الدخول مرة أخرى باستخدام رمز تطبيق المصادقة.',
        ],

        // --- Recovery codes ----------------------------------------------------
        'recovery.instructions'         => [
            'are the XXXX-XXXX-XXXX codes you saved when setting up two-factor authentication. Enter one exactly as it was issued.',
            'هي الرموز بصيغة XXXX-XXXX-XXXX التي حفظتها عند إعداد المصادقة الثنائية. أدخل أحدها تماماً كما صدر.',
        ],
        'recovery.instructions.term'    => ['Recovery codes', 'رموز الاسترداد'],
        'recovery.code.label'           => ['Recovery Code', 'رمز الاسترداد'],
        'recovery.code.hint'            => ['Format: XXXX-XXXX-XXXX (e.g., A1B2-C3D4-E5F6)', 'الصيغة: XXXX-XXXX-XXXX (مثال: A1B2-C3D4-E5F6)'],
        'recovery.submit'               => ['Verify Recovery Code', 'تحقّق من رمز الاسترداد'],
        'recovery.back'                 => ['Back to Authenticator', 'العودة إلى تطبيق المصادقة'],
        'recovery.switch'               => [
            'Can\'t access your authenticator? Use a recovery code instead',
            'لا يمكنك الوصول إلى تطبيق المصادقة؟ استخدم رمز استرداد بدلاً من ذلك',
        ],
        'recovery.error.format'         => ['Recovery code must be in the format XXXX-XXXX-XXXX', 'يجب أن يكون رمز الاسترداد بالصيغة XXXX-XXXX-XXXX'],
        'recovery.error.invalid'        => ['Invalid recovery code. Please try again.', 'رمز الاسترداد غير صحيح. حاول مرة أخرى.'],

        // --- Federated sign-in return markers ----------------------------------
        'sso.multipleWorkspaces'        => [
            'Your account has multiple workspaces — sign in to choose one.',
            'حسابك يملك أكثر من مساحة عمل — سجّل الدخول لاختيار واحدة.',
        ],
        'sso.error.disabled'            => ['Single sign-on is currently disabled for this instance.', 'الدخول الموحّد معطّل حالياً في هذا النظام.'],
        'sso.error.providerUnavailable' => ['That sign-in provider is unavailable right now. Please try again later.', 'مزوّد تسجيل الدخول هذا غير متاح حالياً. حاول لاحقاً.'],
        'sso.error.unknownProvider'     => ['That sign-in provider is not available.', 'مزوّد تسجيل الدخول هذا غير متاح.'],
        'sso.error.emailUnverified'     => ['Your email with that provider is not verified. Verify it and try again.', 'بريدك لدى هذا المزوّد غير موثّق. وثّقه ثم حاول مرة أخرى.'],
        'sso.error.linkConflict'        => ['An account with that email already exists. Sign in with your password to link it.', 'يوجد حساب بهذا البريد بالفعل. سجّل الدخول بكلمة المرور لربطه.'],
        'sso.error.noAccount'           => ['No account here matches that identity. Ask an administrator for an invite.', 'لا يوجد حساب هنا يطابق هذه الهوية. اطلب دعوة من المسؤول.'],
        'sso.error.noMembership'        => ['Your account has no active workspace yet. Ask an administrator for access.', 'لا يوجد لحسابك مساحة عمل نشطة بعد. اطلب صلاحية الوصول من المسؤول.'],
        'sso.error.stateMismatch'       => ['Your sign-in session could not be verified. Please try again.', 'تعذّر التحقّق من جلسة تسجيل الدخول. حاول مرة أخرى.'],
        'sso.error.expired'             => ['Your sign-in attempt timed out. Please try again.', 'انتهت مهلة محاولة تسجيل الدخول. حاول مرة أخرى.'],
        'sso.error.denied'              => ['Sign-in was cancelled.', 'تم إلغاء تسجيل الدخول.'],
        'sso.error.failed'              => ['Sign-in failed. Please try again.', 'فشل تسجيل الدخول. حاول مرة أخرى.'],
    ];

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        $languageIds = [];
        foreach (['en' => 0, 'ar' => 1] as $code => $index) {
            $stmt = $pdo->prepare('SELECT id FROM languages WHERE code = :code');
            $stmt->execute([':code' => $code]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                $languageIds[$index] = (int) $id;
            }
        }

        if ($languageIds === []) {
            // No base language present (a truncated or partially-seeded install):
            // nothing to attach these rows to. Replaying this migration after
            // 082 has run will seed them.
            return;
        }

        // Idempotent via NOT EXISTS rather than ON CONFLICT: the unique index is
        // (language_id, domain, key, tenant_id) and these rows carry a NULL
        // tenant_id, which BOTH Postgres and SQLite treat as distinct from every
        // other NULL — so the constraint would never fire an ON CONFLICT here and
        // a replay would silently duplicate every string.
        $insert = $pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             SELECT :language_id, :domain, :key, :translation, NULL, NOW(), NOW()
             WHERE NOT EXISTS (
                 SELECT 1 FROM translations
                 WHERE language_id = :existing_language_id
                   AND domain = :existing_domain
                   AND key = :existing_key
                   AND tenant_id IS NULL
             )'
        );

        foreach (self::STRINGS as $key => $byLanguage) {
            foreach ($languageIds as $index => $languageId) {
                $insert->execute([
                    ':language_id' => $languageId,
                    ':domain' => self::DOMAIN,
                    ':key' => $key,
                    ':translation' => $byLanguage[$index],
                    ':existing_language_id' => $languageId,
                    ':existing_domain' => self::DOMAIN,
                    ':existing_key' => $key,
                ]);
            }
        }
    }

    public static function down(Database $db): void
    {
        // System defaults only — a tenant's override rows belong to that tenant.
        $stmt = $db->getPdo()->prepare('DELETE FROM translations WHERE domain = :domain AND tenant_id IS NULL');
        $stmt->execute([':domain' => self::DOMAIN]);
    }
}
