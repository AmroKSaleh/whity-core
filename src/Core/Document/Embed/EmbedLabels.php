<?php

declare(strict_types=1);

namespace Whity\Core\Document\Embed;

use Whity\Core\i18n\ServerLabels;

/**
 * The wording core's record types and chart sources send to the editor, in the
 * reader's language (#1044's rule: text the server declares is translated at
 * serving time, and a miss is the English, never a raw key).
 *
 * Without a translator (tests, a host that has not wired one) every string is
 * its English, which is what the editor shows today for any untranslated label.
 *
 * @i18n-keys documents
 *   embeds.record.document = Issued document
 *   embeds.chart.documentsByMonth = Documents issued per month
 *   embeds.chart.documentsByMonth.value = Documents
 *   embeds.chart.documentsByMonth.range = Last 6 months
 *   embeds.month.jan = Jan
 *   embeds.month.feb = Feb
 *   embeds.month.mar = Mar
 *   embeds.month.apr = Apr
 *   embeds.month.may = May
 *   embeds.month.jun = Jun
 *   embeds.month.jul = Jul
 *   embeds.month.aug = Aug
 *   embeds.month.sep = Sep
 *   embeds.month.oct = Oct
 *   embeds.month.nov = Nov
 *   embeds.month.dec = Dec
 */
final class EmbedLabels
{
    public const DOMAIN = 'documents';

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(private readonly ?ServerLabels $labels = null)
    {
    }

    public function get(string $key, string $english): string
    {
        return $this->labels?->label(self::DOMAIN, $key, $english) ?? $english;
    }

    /** A month's short name, 1 to 12. The year stays Western digits beside it. */
    public function month(int $month): string
    {
        return $this->get('embeds.month.' . strtolower(self::MONTHS[$month - 1]), self::MONTHS[$month - 1]);
    }
}
