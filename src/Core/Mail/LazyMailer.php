<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Closure;

/**
 * A {@see Mailer} that defers construction of the real transport until send time
 * (WC-email).
 *
 * The mail transport is settings-driven, so building it requires reading the
 * global settings from the database. Doing that at worker BOOT would (a) make a
 * transient DB issue or a lagging migration crash every FrankenPHP worker, and
 * (b) freeze the transport for the worker's lifetime, so a settings change would
 * need a restart. Wrapping the factory in this decorator fixes both: the mailer
 * is built fresh on each {@see send()}, so it always reflects the current
 * settings and a build failure is isolated to the individual send (email is a
 * best-effort side channel) rather than taking down the process.
 *
 * The factory itself ({@see MailerFactory::fromSettings}) is written to never
 * throw on a settings-read failure — it degrades to {@see NullMailer} — so a send
 * only ever surfaces a genuine transport error ({@see MailException}), which
 * callers already treat as non-fatal.
 */
final class LazyMailer implements Mailer
{
    /** @var Closure(): Mailer */
    private Closure $factory;

    /**
     * @param Closure(): Mailer $factory Builds the concrete transport on demand.
     */
    public function __construct(Closure $factory)
    {
        $this->factory = $factory;
    }

    public function send(string $toEmail, string $subject, string $textBody): void
    {
        ($this->factory)()->send($toEmail, $subject, $textBody);
    }
}
