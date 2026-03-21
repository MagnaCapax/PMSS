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

/**
 * Split argv tokens into associative options and positional arguments.
 */
function pmssParseCliTokens(array $argv): array
{
    $options = [];
    $positionals = [];

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
            if (ctype_alpha($body)) {
                foreach (str_split($body) as $flag) {
                    $options[$flag] = true;
                }
            } else {
                $options[$body[0]] = substr($body, 1) ?: true;
            }
            continue;
        }

        $next = $argv[$i + 1] ?? null;
        if ($next !== null && $next !== '' && ($next[0] ?? '') !== '-') {
            $options[$body] = $next;
            $i++;
            continue;
        }

        $options[$body] = true;
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
