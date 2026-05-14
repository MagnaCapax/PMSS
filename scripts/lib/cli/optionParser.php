<?php
/**
 * Lightweight CLI option parser shared by utility scripts.
 *
 * Provides shorthand helpers for parsing GNU-style long options, collapsed
 * short flags, and retrieving option values with sensible defaults.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/helpText.php';

/**
 * Split argv tokens into associative options and positional arguments.
 * @param array<int,string> $valueOptions Option names that may consume dashed values.
 */
function pmssParseCliTokens(array $argv, array $valueOptions = []): array
{
    $options = [];
    $positionals = [];
    $valueOptionLookup = [];
    foreach ($valueOptions as $option) { $valueOptionLookup[ltrim((string) $option, '-')] = true; }
    unset($valueOptionLookup['']);

    for ($i = 1, $argc = count($argv); $i < $argc; $i++) {
        $token = $argv[$i];
        if (($token[0] ?? '') !== '-' || $token === '-') {
            $positionals[] = $token;
            continue;
        }

        $isLong = ($token[1] ?? '') === '-';
        $body = substr($token, $isLong ? 2 : 1);
        if ($body === '') {
            continue;
        }

        if ($isLong && ($equalsOffset = strpos($body, '=')) !== false) {
            $options[substr($body, 0, $equalsOffset)] = substr($body, $equalsOffset + 1);
            continue;
        }
        if (!$isLong && strlen($body) > 1) {
            if (!ctype_alpha($body)) {
                $options[$body[0]] = substr($body, 1) ?: true;
                continue;
            }
            foreach (str_split($body) as $flag) {
                $options[$flag] = true;
            }
            continue;
        }

        $next = $argv[$i + 1] ?? null;
        $shouldConsumeNext = $next !== null && $next !== '' && (isset($valueOptionLookup[$body]) || ($next[0] ?? '') !== '-');
        $options[$body] = $shouldConsumeNext ? $next : true;
        $i += $shouldConsumeNext ? 1 : 0;
    }

    return ['options' => $options, 'arguments' => $positionals];
}

/**
 * Convenience accessor for parsed CLI options.
 */
function pmssCliOption(array $parsed, string $long, ?string $short = null, $default = null)
{
    return $parsed['options'][$long]
        ?? ($short !== null ? ($parsed['options'][$short] ?? $default) : $default);
}

/**
 * Return a non-empty string option value, or the caller's default.
 */
function pmssCliOptionString(array $parsed, string $long, ?string $short = null, ?string $default = null): ?string
{
    $value = pmssCliOption($parsed, $long, $short, $default);
    return is_string($value) && $value !== '' ? $value : $default;
}
