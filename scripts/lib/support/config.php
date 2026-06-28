<?php
/**
 * Support command configuration helpers.
 *
 * Loads the public support-command settings from the PMSS config directory and
 * validates the small set of values that user-facing tooling depends on.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';

/**
 * Normalize an optional SMTP relay host from support configuration.
 *
 * @param mixed $relayHost
 */
function pmssSupportRelayHostNormalize($relayHost): string
{
    if ($relayHost === null || $relayHost === false) {
        return '';
    }
    if (!is_scalar($relayHost)) {
        throw new RuntimeException('Support relay host is invalid.');
    }

    $relayHost = trim((string) $relayHost);
    if ($relayHost !== '' && !pmssHostnameIsValid($relayHost)) {
        throw new RuntimeException('Support relay host is invalid.');
    }

    return $relayHost;
}

/**
 * Read one scalar support config value before normalizing it.
 *
 * @param mixed $default
 */
function pmssSupportConfigScalarValue(array $config, string $key, $default, string $message)
{
    $value = $config[$key] ?? $default;
    if (!is_scalar($value)) {
        throw new RuntimeException($message);
    }

    return $value;
}

/**
 * Load and validate support command settings.
 *
 * @return array<string,mixed>
 */
function pmssSupportConfigRead(): array
{
    $path = pmssResolvePathFromEnv('PMSS_SUPPORT_CONFIG_PATH', pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config').'/support.php');
    if (!pmssRegularFilePathIsReadable($path) || !is_readable($path)) {
        throw new RuntimeException('Support command config is missing or unreadable.');
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Support command config must return an array.');
    }

    $targetEmail = trim((string) pmssSupportConfigScalarValue($config, 'targetEmail', '', 'Support target email is invalid.'));
    if ($targetEmail === '' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Support target email is invalid.');
    }

    $snapshotDirectory = trim((string) pmssSupportConfigScalarValue($config, 'snapshotDirectory', '.support/requests', 'Support snapshot directory must be a safe relative path.'));
    if (!pmssPathRelativeStringIsSafe($snapshotDirectory, ['allowEmptySegments' => true, 'allowDotSegments' => true, 'allowControlChars' => true, 'rejectDotDotSubstring' => true])) {
        throw new RuntimeException('Support snapshot directory must be a safe relative path.');
    }

    $smtpPort = (int) pmssSupportConfigScalarValue($config, 'smtpPort', 25, 'Support SMTP port is invalid.');
    if ($smtpPort < 1 || $smtpPort > 65535) {
        throw new RuntimeException('Support SMTP port is invalid.');
    }

    $connectTimeout = (int) pmssSupportConfigScalarValue($config, 'connectTimeout', 10, 'Support SMTP timeout is invalid.');
    if ($connectTimeout < 1 || $connectTimeout > 60) {
        throw new RuntimeException('Support SMTP timeout is invalid.');
    }

    return [
        'targetEmail' => $targetEmail,
        'snapshotDirectory' => $snapshotDirectory,
        'smtpPort' => $smtpPort,
        'connectTimeout' => $connectTimeout,
        'relayHost' => pmssSupportRelayHostNormalize($config['relayHost'] ?? ''),
    ];
}
