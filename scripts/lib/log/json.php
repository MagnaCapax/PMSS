<?php
/** JSON codecs and stdout payload emission; no filesystem access. @license GPL-3.0-only */

/**
 * Encode a payload as JSON while tolerating invalid UTF-8.
 */
function pmssJsonEncodeSafe(array $payload, int $flags = 0): ?string
{
    $encoded = json_encode($payload, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($encoded) ? $encoded : null;
}

/** Decode JSON through associative arrays, rejecting invalid or scalar payloads. */
function pmssJsonDecodeAssoc(string $payload): ?array { $decoded = json_decode($payload, true); return is_array($decoded) ? $decoded : null; }

/** Encode data with PMSS's standard pretty file-output flags. */
function pmssJsonEncodePretty($payload, int $extraFlags = 0): ?string { $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | $extraFlags); return is_string($encoded) ? $encoded : null; }
/** Encode pretty JSON with the newline expected by PMSS file writers. */
function pmssJsonEncodePrettyLine($payload, int $extraFlags = 0): ?string { $encoded = pmssJsonEncodePretty($payload, $extraFlags); return is_string($encoded) ? $encoded.PHP_EOL : null; }

/** Emit one JSON payload to stdout while keeping encode failures on stderr. */
function pmssJsonEmitPayload(array $payload, string $errorMessage, int $flags = 0): int
{
    if (!is_string($encoded = pmssJsonEncodeSafe($payload, $flags))) {
        fwrite(STDERR, rtrim($errorMessage, "\n").PHP_EOL);
        return 1;
    }

    echo $encoded.PHP_EOL;
    return 0;
}
