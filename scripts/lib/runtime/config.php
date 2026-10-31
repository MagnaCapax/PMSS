<?php
/** Ordered text/config transforms; loaded by environment.php. @license GPL-3.0-only */

function pmssNonEmptyStrings(array $values): array { return array_values(array_filter($values, 'strlen')); }

function pmssConfigLineTrimmed(string $line, array $commentPrefixes = ['#']): string
{
    $trimmed = trim($line);
    foreach ($commentPrefixes as $prefix) if ($prefix !== '' && strpos($trimmed, $prefix) === 0) return '';
    return $trimmed;
}

/** Yield trimmed, nonempty lines with source keys and caller-owned comment policy. */
function pmssConfigLines(array $lines, array $commentPrefixes = ['#']): \Generator
{
    foreach ($lines as $index => $line) {
        if (($trimmed = pmssConfigLineTrimmed((string) $line, $commentPrefixes)) !== '') {
            yield $index => $trimmed;
        }
    }
}

/** Split LF/CRLF text, retaining whitespace, comments, empty fields and lone CR bytes. */
function pmssTextLines(string $text, int $limit = -1): array { return preg_split('/\r?\n/', $text, $limit); }

/** Split LF/CRLF text into filtered lines without losing source indices. */
function pmssConfigTextLines(string $text, array $commentPrefixes = ['#']): \Generator { return pmssConfigLines(pmssTextLines($text), $commentPrefixes); }
/** Parse LF/CRLF text through the same column and comment rules as array rows. */
function pmssConfigTextRows(string $text, int $minColumns = 0, array $commentPrefixes = ['#'], int $limit = -1): \Generator { return pmssConfigRows(pmssTextLines($text), $minColumns, $commentPrefixes, $limit); }

/** A positive split limit preserves the remaining text in the final column. */
function pmssConfigLineColumns(string $line, int $minColumns = 0, array $commentPrefixes = ['#'], int $limit = -1): array
{
    $trimmed = pmssConfigLineTrimmed($line, $commentPrefixes);
    if ($trimmed === '') return [];
    $columns = preg_split('/\s+/', $trimmed, $limit);
    return is_array($columns) && count($columns) >= $minColumns ? $columns : [];
}

/** Yield accepted column rows lazily, preserving source keys and caller-owned validation. */
function pmssConfigRows(array $lines, int $minColumns = 0, array $commentPrefixes = ['#'], int $limit = -1): \Generator
{
    foreach ($lines as $index => $line) {
        if (($columns = pmssConfigLineColumns((string) $line, $minColumns, $commentPrefixes, $limit)) !== []) {
            yield $index => $columns;
        }
    }
}

function pmssConfigOptionsUpdatePlan(string $optionList, array $requiredOptions = [], array $removeOptions = [], bool $dropDefaultsOnly = false): array
{
    $options = pmssNonEmptyStrings(explode(',', $optionList));
    if ($dropDefaultsOnly && $options === ['defaults']) $options = [];
    $removed = [];
    foreach ($removeOptions as $removeOption) {
        $index = array_search($removeOption, $options, true);
        if ($index === false) continue;
        unset($options[$index]);
        $removed[] = $removeOption;
    }
    $added = array_values(array_diff($requiredOptions, $options));
    return ['options' => array_merge($options, $added), 'added' => $added, 'removed' => $removed];
}
