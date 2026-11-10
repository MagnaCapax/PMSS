<?php
/** Support diagnostics collection and text projection. @license GPL-3.0-only */
require_once __DIR__.'/identity.php';
require_once dirname(__DIR__).'/user/billingIds.php';

/**
 * Validate and normalize the user-supplied support message.
 */
function pmssSupportMessageNormalize(string $message): string
{
    $message = trim(str_replace(["\r\n", "\r"], "\n", $message));
    if ($message === '') {
        throw new InvalidArgumentException('Support message is required.');
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message) === 1) {
        throw new InvalidArgumentException('Support message contains unsupported control characters.');
    }
    if (strlen($message) > 5000) {
        throw new InvalidArgumentException('Support message is too long.');
    }
    return $message;
}

/**
 * Build the read-only diagnostics snapshot body.
 *
 * @return array<string,mixed>
 */
function pmssSupportDiagnosticsBuild(string $message, ?callable $runner = null): array
{
    $runner = $runner ?: function (array $argv): array {
        $output = [];
        $rc = 0;
        exec(pmssCommandArgvShellQuote($argv).' 2>&1', $output, $rc);
        return ['rc' => $rc, 'output' => implode("\n", $output)];
    };

    $message = pmssSupportMessageNormalize($message);
    $identity = pmssSupportIdentityRead();
    // Build the returned record once; commands and text consume those same observations.
    $diagnostics = ['message' => $message, 'username' => (string) $identity['username'],
        'home' => (string) $identity['home'], 'hostname' => (string) (gethostname() ?: 'unknown-host')];
    $configDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $diagnostics['billingServiceId'] = (int) (pmssUserBillingIdentityRead($diagnostics['username'], 'billingServiceId', $configDir)
        ?? pmssSupportOwnBillingMarkerRead($diagnostics['home'], ['.billingServiceId', '.billingId']));
    $diagnostics['billingClientId'] = (int) (pmssUserBillingIdentityRead($diagnostics['username'], 'billingClientId', $configDir)
        ?? pmssSupportOwnBillingMarkerRead($diagnostics['home'], ['.billingClientId']));
    $versionPath = pmssResolvePathFromEnv('PMSS_VERSION_FILE', $configDir.'/version');
    $diagnostics['pmssVersion'] = is_file($versionPath) ? trim((string) @file_get_contents($versionPath)) : 'unknown';
    $commands = [
        'uptime' => ['uptime'],
        'disk' => ['df', '-P', '-h', $diagnostics['home'] !== '' ? $diagnostics['home'] : '.'],
    ];
    foreach (['rtorrent', 'deluged', 'deluge-web', 'qbittorrent-nox'] as $process) $commands[$process] = ['pgrep', '-a', '-u', $diagnostics['username'], '-x', $process];

    $sections = [];
    foreach ($commands as $label => $command) {
        $result = $runner($command);
        $output = trim((string) ($result['output'] ?? ''));
        $sections[] = sprintf("## %s\n%s\n[exit status: %d]", $label, $output === '' ? '[no output]' : $output, (int) ($result['rc'] ?? 1));
    }

    $diagnostics['body'] = implode("\n\n", [
        '# PMSS Support Request',
        'submitted_at='.gmdate('c'),
        'username='.$diagnostics['username'],
        'billing_service_id='.$diagnostics['billingServiceId'],
        'billing_client_id='.$diagnostics['billingClientId'],
        'hostname='.$diagnostics['hostname'],
        'pmss_version='.$diagnostics['pmssVersion'],
        '',
        '## message',
        $message,
        '',
        implode("\n\n", $sections),
        '',
    ]);

    return $diagnostics;
}
