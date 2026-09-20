<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

/**
 * A workspace's payment history could not be read this pass.
 *
 * ── Why this is not an empty list ──────────────────────────────────────────
 *
 * The obvious handling for a failed HTTP call is to return no payments and let
 * the sweep move on. It is also indistinguishable from the truth — a referred
 * customer who has genuinely never paid — and the two need opposite responses:
 * one is a workspace to stop looking at, the other is a commission somebody is
 * owed and has not been given.
 *
 * Returning nothing would make the sweep report success while accruing less
 * than it should, every run, for as long as the billing service stayed
 * unreachable. Nobody would find that except the affiliate, in their own
 * statement, months later.
 *
 * So a source that cannot answer says so, the sweep counts it, and the count
 * reaches the exit code of the cron that ran it. The accrual itself still
 * converges — the next successful pass re-reads the whole history — but the
 * incompleteness is visible while it lasts.
 */
final class ReferredPaymentSourceException extends \RuntimeException
{
}
