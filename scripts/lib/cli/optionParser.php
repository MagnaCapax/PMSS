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

function pmssCliArgv(?array $argv = null): array { return $argv ?? ($_SERVER['argv'] ?? []); }

/** Test for an exact argv token without duplicating fallback boilerplate. */
function pmssCliArgvHasToken(?array $argv, string $token): bool { return in_array($token, pmssCliArgv($argv), true); }

/** Remove exact option tokens from argv while preserving positional order. */
function pmssCliArgvWithoutTokens(?array $argv, array $tokens): array { $blocked = array_flip($tokens); return array_values(array_filter(pmssCliArgv($argv), static function ($arg) use ($blocked): bool { return !isset($blocked[(string) $arg]); })); }

function pmssCliArgvDebugSplit(?array $argv = null): array { return [pmssCliArgvHasToken($argv, '--debug'), pmssCliArgvWithoutTokens($argv, ['--debug'])]; }
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
    $hasDeclaredValueOptions = !empty($valueOptionLookup);

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
        $shouldConsumeNext = $next !== null && $next !== '' && (
            isset($valueOptionLookup[$body]) || (!$hasDeclaredValueOptions && ($next[0] ?? '') !== '-')
        );
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

/** Return whether a CLI option was present, accepting value-bearing flags too. */
function pmssCliOptionPresent(array $parsed, string $long, ?string $short = null, bool $bareFlagOnly = false): bool
{
    $value = pmssCliOption($parsed, $long, $short, false);
    return $bareFlagOnly ? $value === true : $value !== false;
}

/** Emit a shared mutual-exclusion error when more than one long option is selected. */
function pmssCliRejectMutuallyExclusiveOptions(array $parsed, array $longOptions, string $message, string $presenceMode = 'present'): bool
{
    $matches = 0;
    foreach ($longOptions as $longOption) {
        $longOption = (string) $longOption;
        $present = $presenceMode === 'bare' ? pmssCliOptionPresent($parsed, $longOption, null, true) : ($presenceMode === 'truthy' ? (bool) pmssCliOption($parsed, $longOption) : pmssCliOptionPresent($parsed, $longOption));
        if ($present && ++$matches > 1) { fwrite(STDERR, $message); return true; }
    }
    return false;
}

/** Emit an exact stderr payload and terminate the current CLI process. */
function pmssCliExitWithStderr(string $message, int $exitCode): void { fwrite(STDERR, $message); exit($exitCode); }

/** Return whether the standard help option was requested. */
function pmssCliHelpRequested(array $parsed, ?string $short = 'h'): bool
{
    return pmssCliOptionPresent($parsed, 'help', $short);
}

/** Emit prepared help text when requested and report whether it was printed. */
function pmssCliHelpTextEmitIfRequested(array $parsed, string $helpText, ?string $short = 'h'): bool
{
    if (!pmssCliHelpRequested($parsed, $short)) {
        return false;
    }
    echo $helpText;
    return true;
}

/** Parse CLI tokens, emit help when requested, and return null on help. */
function pmssParseCliTokensOrHelp(array $argv, string $helpText, array $valueOptions = [], ?string $short = 'h'): ?array
{
    $parsed = pmssParseCliTokens($argv, $valueOptions);
    return pmssCliHelpTextEmitIfRequested($parsed, $helpText, $short) ? null : $parsed;
}

/**
 * Return a non-empty string option value, or the caller's default.
 */
function pmssCliOptionString(array $parsed, string $long, ?string $short = null, ?string $default = null, bool $allowEmpty = false): ?string
{
    $value = pmssCliOption($parsed, $long, $short, $default);
    return is_string($value) && ($allowEmpty || $value !== '') ? $value : $default;
}

/** Return an integer option value while preserving bare-flag defaults. */
function pmssCliOptionInt(array $parsed, string $long, ?string $short = null, int $default = 0): int
{
    $value = pmssCliOption($parsed, $long, $short, null);
    return $value === null || $value === true ? $default : (int) $value;
}
