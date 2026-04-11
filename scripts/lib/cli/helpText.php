<?php
/**
 * Shared CLI help-formatting helpers.
 *
 * Small presentation helpers keep operator-facing usage text consistent across
 * scripts without pulling in heavier runtime dependencies.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Detect whether ANSI styling is safe for CLI help text. */
function pmssCliHelpSupportsColor(): bool
{
    $term = getenv('TERM');
    $noColor = getenv('NO_COLOR');
    if ($term === false || $term === '' || $term === 'dumb') {
        return false;
    }
    if ($noColor !== false && $noColor !== '') {
        return false;
    }

    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDOUT);
    }
    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDOUT);
    }

    return false;
}

/** Apply a basic ANSI style when the current terminal supports it. */
function pmssCliHelpStyle(string $text, string $code, bool $useColor): string
{
    if (!$useColor || $text === '') {
        return $text;
    }

    return "\033[{$code}m{$text}\033[0m";
}

/** Render a section title. */
function pmssCliHelpHeading(string $title, bool $useColor): string
{
    return pmssCliHelpStyle($title, '1', $useColor);
}

/** Render lower-priority default or hint text. */
function pmssCliHelpDim(string $text, bool $useColor): string
{
    return pmssCliHelpStyle($text, '2', $useColor);
}

/** Format a two-column help line with a stable indentation width. */
function pmssCliHelpLine(string $label, string $description, int $width = 40): string
{
    return '  '.str_pad($label, $width).$description;
}
