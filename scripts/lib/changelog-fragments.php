<?php

declare(strict_types=1);

/**
 * Changelog fragments (WHIT-791).
 *
 * WHY FRAGMENTS
 * -------------
 * Every pull request used to add its entry to the one `## [Unreleased]`
 * section of CHANGELOG.md. With several branches in flight, each merge put the
 * next PRs in conflict on that section, and a conflicting PR drops out of the
 * merge queue. On 2026-10-31 three green PRs (#1446, #1447, #1450) left the
 * queue for that reason alone and had to be re-merged one at a time.
 *
 * A fragment is a file of its own, so two PRs never touch the same lines:
 *
 *     changelog.d/WHIT-789.added.md
 *     changelog.d/WHIT-732.changed.md
 *
 * `release-prepare.yml` folds them into [Unreleased] (scripts/changelog-assemble.php)
 * just before it dates the section, and deletes them in the release commit.
 * Entries written straight into [Unreleased] keep working: they stay where they
 * are and the fragments join them.
 */

/** Keep a Changelog's sections, in its order, keyed by the fragment suffix. */
const CHANGELOG_SECTIONS = [
    'added'      => 'Added',
    'changed'    => 'Changed',
    'deprecated' => 'Deprecated',
    'removed'    => 'Removed',
    'fixed'      => 'Fixed',
    'security'   => 'Security',
];

const CHANGELOG_FRAGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(added|changed|deprecated|removed|fixed|security)\.md$/';

/**
 * The fragment files in a directory, by name. README.md and dotfiles are not
 * fragments; anything else is, and is checked as one.
 *
 * @return list<string>
 */
function changelog_fragment_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === 'README.md' || str_starts_with($name, '.')) {
            continue;
        }
        if (is_file("{$dir}/{$name}")) {
            $files[] = $name;
        }
    }
    sort($files, SORT_NATURAL);

    return $files;
}

/**
 * What is wrong with each fragment, by file name. An empty array means every
 * fragment is fine.
 *
 * @return array<string, list<string>>
 */
function changelog_fragment_problems(string $dir): array
{
    $problems = [];
    foreach (changelog_fragment_files($dir) as $name) {
        $found = [];
        if (preg_match(CHANGELOG_FRAGMENT_PATTERN, $name) !== 1) {
            $found[] = 'name must be <id>.<section>.md, where section is one of '
                . implode(', ', array_keys(CHANGELOG_SECTIONS));
        }

        $body = trim((string) file_get_contents("{$dir}/{$name}"));
        if ($body === '') {
            $found[] = 'file is empty';
        } elseif (!str_starts_with($body, '- ')) {
            $found[] = 'must start with a list item ("- **What changed.** Why it matters.")';
        }
        if (preg_match('/^#/m', $body) === 1) {
            $found[] = 'must not contain headings; the section comes from the file name';
        }

        if ($found !== []) {
            $problems[$name] = $found;
        }
    }

    return $problems;
}

/**
 * Fold fragments into the changelog's [Unreleased] section.
 *
 * Each section keeps Keep a Changelog's order. Within a section the fragments
 * come first, in file-name order, ahead of entries already written there, so
 * the newest work stays at the top as it always has.
 *
 * @param list<array{section: string, body: string}> $fragments section is a CHANGELOG_SECTIONS key
 */
function changelog_assemble(string $changelog, array $fragments): string
{
    if (substr_count($changelog, "## [Unreleased]") !== 1) {
        throw new RuntimeException("Expected exactly one '## [Unreleased]' heading in CHANGELOG.md");
    }

    [$head, $rest] = explode("## [Unreleased]", $changelog, 2);
    $next = strpos($rest, "\n## [");
    $unreleased = $next === false ? $rest : substr($rest, 0, $next);
    $tail       = $next === false ? '' : substr($rest, $next + 1);

    // Split [Unreleased] into the text before its first ### heading and an
    // ordered map of heading => body.
    $parts    = preg_split('/^### (.+)$/m', $unreleased, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
    $preamble = trim($parts[0]);
    $sections = [];
    for ($i = 1; $i < count($parts); $i += 2) {
        $heading            = trim($parts[$i]);
        $existing           = trim($parts[$i + 1] ?? '');
        $sections[$heading] = isset($sections[$heading])
            ? trim($sections[$heading] . "\n" . $existing)
            : $existing;
    }

    $added = [];
    foreach ($fragments as $fragment) {
        $heading           = CHANGELOG_SECTIONS[$fragment['section']];
        $added[$heading][] = trim($fragment['body']);
    }

    $ordered = [];
    foreach (CHANGELOG_SECTIONS as $heading) {
        $body = trim(implode("\n", $added[$heading] ?? []) . "\n" . ($sections[$heading] ?? ''));
        if ($body !== '') {
            $ordered[$heading] = $body;
        }
        unset($sections[$heading]);
    }
    // Headings outside Keep a Changelog's six stay, after them, as written.
    foreach ($sections as $heading => $body) {
        if ($body !== '') {
            $ordered[$heading] = $body;
        }
    }

    $out = "## [Unreleased]\n\n";
    if ($preamble !== '') {
        $out .= "{$preamble}\n\n";
    }
    foreach ($ordered as $heading => $body) {
        $out .= "### {$heading}\n\n{$body}\n\n";
    }

    return $head . $out . $tail;
}

/**
 * Read every fragment in a directory into the shape changelog_assemble() takes.
 * Call changelog_fragment_problems() first: this assumes valid names.
 *
 * @return list<array{section: string, body: string}>
 */
function changelog_read_fragments(string $dir): array
{
    $fragments = [];
    foreach (changelog_fragment_files($dir) as $name) {
        if (preg_match(CHANGELOG_FRAGMENT_PATTERN, $name, $m) !== 1) {
            continue;
        }
        $fragments[] = [
            'section' => $m[1],
            'body'    => (string) file_get_contents("{$dir}/{$name}"),
        ];
    }

    return $fragments;
}
