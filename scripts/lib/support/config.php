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
 * Return the absolute path to the support command config file.
 */
function pmssSupportConfigPath(): string
{
    return pmssResolvePathFromEnv('PMSS_SUPPORT_CONFIG_PATH', pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config').'/support.php');
}

/**
 * Load and validate support command settings.
 *
 * @return array<string,mixed>
 */
function pmssSupportConfigRead(): array
{
    $path = pmssSupportConfigPath();
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Support command config is missing.');
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Support command config must return an array.');
    }

    $targetEmail = trim((string) ($config['targetEmail'] ?? ''));
    if ($targetEmail === '' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Support target email is invalid.');
    }

    $snapshotDirectory = trim((string) ($config['snapshotDirectory'] ?? '.support/requests'));
    if ($snapshotDirectory === ''
        || strpos($snapshotDirectory, '..') !== false
        || $snapshotDirectory[0] === '/') {
        throw new RuntimeException('Support snapshot directory must be a safe relative path.');
    }

    $smtpPort = (int) ($config['smtpPort'] ?? 25);
    if ($smtpPort < 1 || $smtpPort > 65535) {
        throw new RuntimeException('Support SMTP port is invalid.');
    }

    $connectTimeout = (int) ($config['connectTimeout'] ?? 10);
    if ($connectTimeout < 1 || $connectTimeout > 60) {
        throw new RuntimeException('Support SMTP timeout is invalid.');
    }

    $relayHost = trim((string) ($config['relayHost'] ?? ''));

    return [
        'targetEmail' => $targetEmail,
        'snapshotDirectory' => $snapshotDirectory,
        'smtpPort' => $smtpPort,
        'connectTimeout' => $connectTimeout,
        'relayHost' => $relayHost,
    ];
}
