<?php
/** Log field normalization and timestamp formatting; no filesystem access. @license GPL-3.0-only */

/** Collapse whitespace without changing caller-owned trimming or byte limits. */
function pmssLogWhitespaceCollapse(string $text): string { return (string) preg_replace('/\s+/', ' ', $text); }

/** Replace control-character runs while retaining printable column spacing. */
function pmssLogControlCharactersReplace(string $text): string { return (string) preg_replace('/[[:cntrl:]]+/', ' ', $text); }

/** Compact line separators and horizontal spacing; retain other embedded control bytes. */
function pmssLogLineCompact(string $text): string { return trim((string) preg_replace('/[\r\n\0\t ]+/', ' ', $text)); }

/** Bound diagnostic bytes, adding the caller's suffix only when text exceeds the cutoff. */
function pmssLogExcerpt(string $text, int $limit, string $suffix = '...'): string { return strlen($text) > $limit ? substr($text, 0, $limit).$suffix : $text; }

/** Keep account labels on one line while preserving printable characters. */
function pmssLogSafeLabel(string $value): string { $label = preg_replace('/[\r\n\0]+/', '?', $value); return is_string($label) && $label !== '' ? $label : '(empty)'; }

/** Convert arbitrary log fields to single-line text, defaulting only when empty. */
function pmssLogScalarText($value, string $default = ''): string
{
    if ($value === null) return $default;
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    } elseif (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
        $value = gettype($value);
    }

    // Every accepted representation crosses the same string-conversion boundary.
    $text = pmssLogWhitespaceCollapse(trim(pmssLogControlCharactersReplace((string) $value)));
    return $text !== '' ? $text : $default;
}

/** Format legacy log bytes without trimming messages or normalizing their delimiters. */
function pmssLogFormatTimestampedLine(string $message, string $timestampFormat = 'Y-m-d H:i:s', string $prefix = ': '): string
{
    return date($timestampFormat).$prefix.$message.PHP_EOL;
}
