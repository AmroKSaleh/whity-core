<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Renders transactional email into the branded Whity shell (WC-email): a clean,
 * table-based, inline-CSS HTML layout that survives real email clients, plus the
 * matching plain-text alternative. Dependency-free (no template engine).
 *
 * "Easy to customise" without touching this file: the header wordmark, brand
 * colour, support address and footer all come from {@see EmailBranding} (resolved
 * from instance settings). Callers supply only the message {@see EmailContent};
 * the layout owns all structure and HTML-escaping, so a caller can never inject
 * markup.
 */
final class EmailLayout
{
    public function render(EmailContent $content, EmailBranding $branding): RenderedEmail
    {
        return new RenderedEmail(
            text: $this->renderText($content, $branding),
            html: $this->renderHtml($content, $branding),
        );
    }

    private function renderHtml(EmailContent $c, EmailBranding $b): string
    {
        $brand = self::esc($b->brandColor);
        $site = self::esc($b->siteName);
        $initial = self::esc($b->initial());
        $year = gmdate('Y');

        // Body paragraphs.
        $paras = '';
        foreach ($c->paragraphs as $p) {
            $paras .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#33405a;">'
                . self::esc($p) . '</p>';
        }

        // Optional callout box (e.g. "X invited you to Y").
        $callout = '';
        if ($c->callout !== null && $c->callout !== '') {
            $callout = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">'
                . '<tr><td style="background:#f3f7fd;border:1px solid #dbe6f7;border-radius:8px;'
                . 'padding:13px 15px;font-size:14px;line-height:1.5;color:#33405a;">'
                . self::esc($c->callout) . '</td></tr></table>';
        }

        // Bulletproof-ish CTA button (only when the URL is safe).
        $cta = '';
        $ctaUrl = $c->hasCta() ? self::safeUrl((string) $c->ctaUrl) : null;
        if ($ctaUrl !== null) {
            $cta = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0;">'
                . '<tr><td style="border-radius:8px;background:' . $brand . ';">'
                . '<a href="' . self::esc($ctaUrl) . '" '
                . 'style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:600;'
                . 'color:#ffffff;text-decoration:none;border-radius:8px;">'
                . self::esc((string) $c->ctaLabel) . '</a></td></tr></table>';
        }

        // Optional footnote (small print under the CTA).
        $footnote = '';
        if ($c->footnote !== null && $c->footnote !== '') {
            $footnote = '<p style="margin:0 0 4px;font-size:13px;line-height:1.5;color:#8a94a6;">'
                . self::esc($c->footnote) . '</p>';
        }

        // Footer.
        $footerLines = '';
        if ($b->footerText !== '') {
            $footerLines .= '<p style="margin:0 0 6px;font-size:12.5px;line-height:1.5;color:#8a94a6;">'
                . self::esc($b->footerText) . '</p>';
        }
        if ($b->supportEmail !== '') {
            $support = self::esc($b->supportEmail);
            $footerLines .= '<p style="margin:0 0 6px;font-size:12.5px;line-height:1.5;color:#8a94a6;">'
                . 'Need help? Contact <a href="mailto:' . $support . '" style="color:#6b7688;">'
                . $support . '</a>.</p>';
        }
        $footerLines .= '<p style="margin:10px 0 0;font-size:12.5px;line-height:1.5;color:#8a94a6;">&copy; '
            . $year . ' ' . $site . '</p>';

        $heading = self::esc($c->heading);

        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light only">
<title>{$heading}</title></head>
<body style="margin:0;padding:0;background:#eef2f8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f8;">
<tr><td align="center" style="padding:28px 16px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e1e7f0;border-radius:12px;">
    <tr><td style="padding:28px 36px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="width:34px;height:34px;background:{$brand};border-radius:9px;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-weight:bold;font-size:18px;text-align:center;vertical-align:middle;">{$initial}</td>
        <td style="padding-left:10px;font-family:Arial,Helvetica,sans-serif;font-size:17px;font-weight:bold;color:#0f1b2d;">{$site}</td>
      </tr></table>
      <div style="height:3px;background:{$brand};border-radius:999px;margin-top:22px;"></div>
    </td></tr>
    <tr><td style="padding:26px 36px 8px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
      <h1 style="margin:0 0 14px;font-size:22px;line-height:1.25;color:#0f1b2d;">{$heading}</h1>
      {$paras}
      {$callout}
      {$cta}
      {$footnote}
    </td></tr>
    <tr><td style="padding:0 36px;"><div style="height:1px;background:#e1e7f0;"></div></td></tr>
    <tr><td style="padding:20px 36px 30px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
      {$footerLines}
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>
HTML;
    }

    private function renderText(EmailContent $c, EmailBranding $b): string
    {
        $lines = [$c->heading, ''];
        foreach ($c->paragraphs as $p) {
            $lines[] = $p;
            $lines[] = '';
        }
        if ($c->callout !== null && $c->callout !== '') {
            $lines[] = $c->callout;
            $lines[] = '';
        }
        if ($c->hasCta() && self::safeUrl((string) $c->ctaUrl) !== null) {
            $lines[] = $c->ctaLabel . ': ' . $c->ctaUrl;
            $lines[] = '';
        }
        if ($c->footnote !== null && $c->footnote !== '') {
            $lines[] = $c->footnote;
            $lines[] = '';
        }
        $lines[] = '--';
        if ($b->footerText !== '') {
            $lines[] = $b->footerText;
        }
        if ($b->supportEmail !== '') {
            $lines[] = 'Need help? Contact ' . $b->supportEmail;
        }
        $lines[] = '(c) ' . gmdate('Y') . ' ' . $b->siteName;

        return implode("\n", $lines) . "\n";
    }

    /**
     * Allow only http(s)/mailto URLs into the HTML (blocks javascript:, data:,
     * etc.). Returns the URL when safe, else null (the button is omitted).
     */
    private static function safeUrl(string $url): ?string
    {
        return preg_match('#^(https?:)?//#i', $url) === 1
            || preg_match('#^https?:#i', $url) === 1
            || preg_match('#^mailto:#i', $url) === 1
            ? $url
            : null;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
