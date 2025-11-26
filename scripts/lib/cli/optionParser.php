<?php
/**
 * Lightweight CLI option parser shared by utility scripts.
 *
 * Provides shorthand helpers for parsing GNU-style long options, collapsed
 * short flags, and retrieving option values with sensible defaults.
 */

/**
 * Split argv tokens into associative options and positional arguments.
 */
function pmssParseCliTokens(array $argv): array
{
    $options = [];
    $positionals = [];
    $tokens = array_slice($argv, 1);

    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];

        if (substr($token, 0, 2) === '--') {
            $body = substr($token, 2);
            if ($body === '') {
                continue;
            }
            if (strpos($body, '=') !== false) {
                [$key, $value] = explode('=', $body, 2);
                $options[$key] = $value;
            } else {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next !== '' && $next[0] !== '-') {
                    $options[$body] = $next;
                    $i++;
                } else {
                    $options[$body] = true;
                }
            }
            continue;
        }

        if (substr($token, 0, 1) === '-' && strlen($token) > 1) {
            $body = substr($token, 1);
            if (strlen($body) === 1) {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next !== '' && $next[0] !== '-') {
                    $options[$body] = $next;
                    $i++;
                } else {
                    $options[$body] = true;
                }
                continue;
            }

            if (ctype_alpha($body)) {
                foreach (str_split($body) as $flag) {
                    $options[$flag] = true;
                }
            } else {
                $key = substr($body, 0, 1);
                $value = substr($body, 1) ?: true;
                $options[$key] = $value;
            }
            continue;
        }

        $positionals[] = $token;
    }

    return [
        'options' => $options,
        'arguments' => $positionals,
    ];
}

/**
 * Convenience accessor for parsed CLI options.
 */
function pmssCliOption(array $parsed, string $long, ?string $short = null, $default = null)
{
    if (isset($parsed['options'][$long])) {
        return $parsed['options'][$long];
    }
    if ($short !== null && isset($parsed['options'][$short])) {
        return $parsed['options'][$short];
    }
    return $default;
}
