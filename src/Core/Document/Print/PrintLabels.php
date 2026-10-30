<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

use Whity\Core\i18n\ServerLabels;

/**
 * The words a printed editor document adds around its content: the footer's
 * "Page x of y", the running header and the print views of live blocks.
 * Translated at serving time (#1044); `{{page}}` and `{{pages}}` are filled by
 * Whity Render on each page and stay as they are in every language.
 *
 * @i18n-keys documents
 *   print.pageOf = Page {{page}} of {{pages}}
 *   print.continued = {title} · continued
 *   print.reference = Ref {reference}
 *   print.attachment = Attached: {name}
 *   print.recordHidden = A record that isn’t available to you
 *   print.record.amount = Amount
 *   print.record.subtitle = Details
 *   print.record.status = Status
 *   print.signature = Signature
 *   print.date = Date
 */
final class PrintLabels
{
    public const DOMAIN = 'documents';

    public function __construct(private readonly ?ServerLabels $labels = null)
    {
    }

    public function pageOf(): string
    {
        return $this->get('print.pageOf', 'Page {{page}} of {{pages}}');
    }

    public function continued(string $title): string
    {
        return str_replace('{title}', $title, $this->get('print.continued', '{title} · continued'));
    }

    public function reference(string $reference): string
    {
        return str_replace('{reference}', $reference, $this->get('print.reference', 'Ref {reference}'));
    }

    public function attachment(string $name): string
    {
        return str_replace('{name}', $name, $this->get('print.attachment', 'Attached: {name}'));
    }

    public function recordHidden(): string
    {
        return $this->get('print.recordHidden', 'A record that isn’t available to you');
    }

    /** @param 'amount'|'subtitle'|'status' $fact */
    public function recordFact(string $fact): string
    {
        return $this->get('print.record.' . $fact, match ($fact) {
            'amount' => 'Amount',
            'subtitle' => 'Details',
            'status' => 'Status',
        });
    }

    public function signature(): string
    {
        return $this->get('print.signature', 'Signature');
    }

    public function date(): string
    {
        return $this->get('print.date', 'Date');
    }

    private function get(string $key, string $english): string
    {
        return $this->labels?->label(self::DOMAIN, $key, $english) ?? $english;
    }
}
