<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

/**
 * A webhook payload could not be proven to have come from the provider.
 *
 * THROWN RATHER THAN RETURNED, and that is a deliberate structural choice.
 *
 * The conventional shape is a `verify(): bool` alongside a `translate()`, and
 * it fails in the way this codebase has repeatedly found things fail: the
 * verification is called and its result is not checked, or a later refactor
 * moves the translate call and leaves the verify behind, and nothing looks
 * wrong. The endpoint keeps working — that is the problem — because a forged
 * payload and a genuine one both translate perfectly well.
 *
 * So {@see PaymentProviderAdapter::translateWebhook()} has no separate verify
 * step to forget. There is no way to obtain events from a payload without
 * verification having happened, because verification is the first thing
 * translation does and its failure is an exception, not a value a caller can
 * ignore.
 */
final class WebhookVerificationException extends PaymentProviderException
{
}
