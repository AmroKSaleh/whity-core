<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use Whity\Core\i18n\ServerLabels;

/**
 * The wording core's merge fields send to the editor, in the reader's language
 * (#1044: text the server declares is translated at serving time, and a miss
 * is the English, never a raw key).
 *
 * @i18n-keys documents
 *   fields.group.company = Company
 *   fields.group.today = Today
 *   fields.group.document = Document
 *   fields.company.name = Company name
 *   fields.company.address = Company address
 *   fields.company.taxNumber = Company tax number
 *   fields.today = Today
 *   fields.document.title = Document title
 *   fields.document.number = Document number
 *   fields.document.template = Template
 *   fields.document.issued = Issued on
 *   fields.month.january = January
 *   fields.month.february = February
 *   fields.month.march = March
 *   fields.month.april = April
 *   fields.month.may = May
 *   fields.month.june = June
 *   fields.month.july = July
 *   fields.month.august = August
 *   fields.month.september = September
 *   fields.month.october = October
 *   fields.month.november = November
 *   fields.month.december = December
 *   fields.yes = Yes
 *   fields.no = No
 */
final class FieldLabels
{
    public const DOMAIN = 'documents';

    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public function __construct(private readonly ?ServerLabels $labels = null)
    {
    }

    public function get(string $key, string $english): string
    {
        return $this->labels?->label(self::DOMAIN, $key, $english) ?? $english;
    }

    /**
     * "29 October 2026": the day and year stay Western digits (stage 5), the
     * month is in the reader's language.
     */
    public function date(\DateTimeInterface $date): string
    {
        $month = self::MONTHS[(int) $date->format('n') - 1];

        return $date->format('j') . ' ' . $this->get('fields.month.' . strtolower($month), $month) . ' ' . $date->format('Y');
    }
}
