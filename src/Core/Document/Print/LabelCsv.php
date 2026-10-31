<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

/**
 * The rows of a CSV a label sheet prints from (E.43): one label per row, each
 * mapped column filling one field.
 *
 * Read in memory and never stored. The first row names the columns; commas,
 * semicolons (spreadsheets in many locales) and tabs are recognised from it. A
 * blank row is skipped. Stateless.
 */
final class LabelCsv
{
    /** A mailing list of a few thousand rows is well under this. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const MAX_CELL = 1000;

    /**
     * @param mixed $csv The CSV's text.
     * @param mixed $columns CSV column name => field path.
     * @param array<string, true> $paths The fields the template offers.
     * @return list<array<string, string|null>>|string The rows as field values, or why not.
     */
    public static function rows(mixed $csv, mixed $columns, array $paths, int $maxRows): array|string
    {
        if (!is_string($csv) || $csv === '') {
            return 'csv must be the text of a CSV file';
        }
        if (strlen($csv) > self::MAX_BYTES) {
            return 'The CSV is too large; the limit is 2 MB';
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            return 'The CSV must be saved as UTF-8';
        }
        if (!is_array($columns) || $columns === [] || array_is_list($columns)) {
            return 'columns must map CSV column names to fields';
        }

        $csv = str_starts_with($csv, "\u{FEFF}") ? substr($csv, 3) : $csv;
        $firstLine = strtok($csv, "\r\n");
        $delimiter = self::delimiter($firstLine === false ? '' : $firstLine);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return 'The CSV could not be read';
        }
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream, null, $delimiter, '"', '');
        if (!is_array($header) || $header === [null]) {
            fclose($stream);

            return 'The CSV has no header row';
        }
        $header = array_map(static fn (?string $h): string => trim((string) $h), $header);

        $map = [];
        foreach ($columns as $column => $path) {
            $index = array_search((string) $column, $header, true);
            if ($index === false) {
                fclose($stream);

                return 'The CSV has no column "' . $column . '"';
            }
            if (!is_string($path) || !isset($paths[$path])) {
                fclose($stream);

                return 'Column "' . $column . '" is mapped to a field this template doesn\'t offer';
            }
            $map[(int) $index] = $path;
        }

        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            if (count($rows) === $maxRows) {
                fclose($stream);

                return 'The CSV has more than ' . $maxRows . ' rows; print it in parts';
            }
            $values = [];
            foreach ($map as $index => $path) {
                $value = trim((string) ($cells[$index] ?? ''));
                $values[$path] = $value === '' ? null : mb_substr($value, 0, self::MAX_CELL);
            }
            $rows[] = $values;
        }
        fclose($stream);

        if ($rows === []) {
            return 'The CSV has no rows under its header';
        }

        return $rows;
    }

    private static function delimiter(string $line): string
    {
        $best = ',';
        $most = substr_count($line, ',');
        foreach ([';', "\t"] as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $most) {
                $best = $candidate;
                $most = $count;
            }
        }

        return $best;
    }
}
