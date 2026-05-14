<?php
/**
 * Certbot setup helpers for PMSS host provisioning.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';

/**
 * Build a shell-safe argv command string for certbot setup primitives.
 */
function pmssSetupLetsEncryptCommandBuild(string $binary, array $args = array()): string
{
    $command = escapeshellcmd($binary);
    foreach ($args as $arg) {
        $command .= ' '.escapeshellarg((string) $arg);
    }

    return $command;
}

/**
 * Normalize one command result, including test double payloads.
 *
 * @return array{rc:int,stdout:string,stderr:string}
 */
function pmssSetupLetsEncryptCommandResultNormalize($result, string $description): array
{
    if (!is_array($result)) {
        throw new RuntimeException($description.' returned invalid command result');
    }

    return array(
        'rc' => isset($result['rc']) ? (int) $result['rc'] : 1,
        'stdout' => isset($result['stdout']) ? (string) $result['stdout'] : '',
        'stderr' => isset($result['stderr']) ? (string) $result['stderr'] : '',
    );
}

/**
 * Execute one provisioning primitive and fail loudly when it does not succeed.
 */
function pmssSetupLetsEncryptRunCommand(string $command, string $description, ?callable $runner = null): string
{
    $result = $runner !== null
        ? pmssSetupLetsEncryptCommandResultNormalize($runner($command, $description), $description)
        : pmssCommandCapture($command);

    if ($result['rc'] !== 0) {
        $detail = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
        throw new RuntimeException(
            $description.' failed (rc='.(int) $result['rc'].')'.($detail !== '' ? ': '.$detail : '')
        );
    }

    return (string) $result['stdout'];
}

/**
 * Validate one absolute filesystem path before touching the host filesystem.
 */
function pmssSetupLetsEncryptPathResolve(string $path, string $label): string
{
    $path = trim($path);
    if ($path === '' || preg_match('/[\r\n\0]/', $path) === 1 || $path[0] !== '/') {
        throw new RuntimeException('Invalid '.$label.': '.$path);
    }

    $normalized = rtrim($path, '/');
    return $normalized !== '' ? $normalized : '/';
}

/**
 * Return the managed certbot renewal cron payload.
 */
function pmssSetupLetsEncryptRenewalCronContents(): string
{
    return "0 0,12 * * * root python -c 'import random; import time; time.sleep(random.random() * 3600)' && /usr/bin/certbot renew\n";
}

/**
 * Seed the renewal cron stub once without shelling out through tee.
 */
function pmssSetupLetsEncryptEnsureRenewalCron(string $cronPath, callable $fileExists): void
{
    if ($fileExists($cronPath)) {
        return;
    }

    $directory = dirname($cronPath);
    if (!pmssDirEnsureExists($directory, 0755)) {
        throw new RuntimeException('Unable to create certbot cron directory: '.$directory);
    }

    if (@file_put_contents($cronPath, pmssSetupLetsEncryptRenewalCronContents()) === false) {
        throw new RuntimeException('Unable to write certbot cron file: '.$cronPath);
    }

    if (!@chmod($cronPath, 0644)) {
        throw new RuntimeException('Unable to set certbot cron permissions: '.$cronPath);
    }
}

/**
 * Provision certbot, request the host certificate, and refresh nginx.
 */
function pmssSetupLetsEncryptRun(string $domain, string $email, string $codename, array $options = array()): void
{
    $commandRunner = isset($options['commandRunner']) && is_callable($options['commandRunner'])
        ? $options['commandRunner']
        : null;
    $fileExists = isset($options['fileExists']) && is_callable($options['fileExists'])
        ? $options['fileExists']
        : 'file_exists';
    $liveDir = pmssSetupLetsEncryptPathResolve(
        isset($options['liveDir']) ? (string) $options['liveDir'] : '/etc/letsencrypt/live',
        'live certificate directory'
    );
    $cronPath = pmssSetupLetsEncryptPathResolve(
        isset($options['cronPath']) ? (string) $options['cronPath'] : '/etc/cron.d/certbot',
        'certbot renewal cron'
    );
    $createNginxConfigCommand = isset($options['createNginxConfigCommand'])
        ? (string) $options['createNginxConfigCommand']
        : pmssSetupLetsEncryptCommandBuild('/scripts/util/createNginxConfig.php');
    $nginxRestartCommand = isset($options['nginxRestartCommand'])
        ? (string) $options['nginxRestartCommand']
        : pmssSetupLetsEncryptCommandBuild('/etc/init.d/nginx', array('restart'));
    $certbotBinary = '/usr/bin/certbot';
    $certbotVirtualenvBinary = '/opt/certbot/bin/certbot';
    $liveCertPath = $liveDir.'/'.$domain;

    // Debian 10 still needs the upstream virtualenv layout for a new enough certbot.
    if ($codename === 'buster') {
        if (!$fileExists($certbotVirtualenvBinary)) {
            foreach (array(
                array('python3', array('-m', 'venv', '/opt/certbot/'), 'Create certbot virtualenv'),
                array('/opt/certbot/bin/pip', array('install', '--upgrade', 'pip'), 'Upgrade certbot pip'),
                array('/opt/certbot/bin/pip', array('install', 'certbot', 'certbot-nginx'), 'Install certbot virtualenv packages'),
            ) as $step) {
                pmssSetupLetsEncryptRunCommand(
                    pmssSetupLetsEncryptCommandBuild($step[0], $step[1]),
                    $step[2],
                    $commandRunner
                );
            }
        }

        if (!$fileExists($certbotBinary)) {
            pmssSetupLetsEncryptRunCommand(
                pmssSetupLetsEncryptCommandBuild('ln', array('-s', $certbotVirtualenvBinary, $certbotBinary)),
                'Link certbot binary',
                $commandRunner
            );
        }
    } else {
        echo pmssSetupLetsEncryptRunCommand(
            'apt-get -y install certbot python3-certbot-nginx',
            'Install certbot packages',
            $commandRunner
        );
    }

    if (!$fileExists($liveCertPath)) {
        echo pmssSetupLetsEncryptRunCommand(
            pmssSetupLetsEncryptCommandBuild(
                $certbotBinary,
                array('certonly', '-d', $domain, '-n', '--nginx', '--agree-tos', '--email', $email)
            ),
            'Request Let\'s Encrypt certificate',
            $commandRunner
        );
    }

    pmssSetupLetsEncryptEnsureRenewalCron($cronPath, $fileExists);
    pmssSetupLetsEncryptRunCommand($createNginxConfigCommand, 'Rebuild nginx configuration', $commandRunner);
    pmssSetupLetsEncryptRunCommand($nginxRestartCommand, 'Restart nginx', $commandRunner);
}
