<?php
/** Account UI facade: publication, preflight and legacy route entrypoints. */
require_once __DIR__.'/../lighttpd/userFileWrite.php';

/** Install root-owned static UI pages outside every customer home. */
function pmssPikkumiesUiPagesInstall(): bool
{
    $target = '/var/www/pikkumies';
    if (!pmssEnsureSafeDir($target, 0755)) return false;
    foreach (array('bar.html', 'not-running.html', 'hermes-login.html') as $page) {
        $source = '/etc/seedbox/config/pikkumies-'.$page;
        if (!is_file($source) || is_link($source)) return false;
        $content = @file_get_contents($source);
        if (!is_string($content) || !pmssWriteManagedFile($target.'/'.$page, $content, 'root', 'root', 0644)) return false;
    }
    return true;
}

/** Keep the managed writer's account-private ownership contract in one place. */
function pmssPikkumiesUiIdentityWrite(string $path, string $serviceId, string $user, ?callable $writer = null): bool
{
    $writer = $writer ?: 'pmssWriteManagedFile';
    return $writer($path, $serviceId."\n", 'root', $user, 0640);
}

function pmssPikkumiesUiIdentityPublish(string $user, ?string $serviceId, string $directory = '/var/lib/pmss/public/billing',
    string $marker = '/etc/seedbox/config/pikkumiesEternals.enabled'): bool
{
    $enabled = is_file($marker) && !is_link($marker);
    if (!pmssValidateUsername($user) || !pmssPathTargetIsSafe($directory, true, false, false)
        || is_link($directory)) return false;
    if (!is_dir($directory) && (!$enabled || !@mkdir($directory, 0711, true))) return !$enabled;
    if (!is_dir($directory) || is_link($directory) || @fileowner($directory) !== 0) return false;
    @chmod($directory, 0711);
    $path = $directory.'/'.$user;
    if (!$enabled || $serviceId === null) {
        if (!file_exists($path) && !is_link($path)) return true;
        $entry = @lstat($path);
        return pmssUserFileStatIsSingleRegular($entry) && $entry['uid'] === 0 && @unlink($path);
    }
    if (pmssUnsignedDecimalIntParse($serviceId) === null || (int) $serviceId < 1) return false;
    return pmssPikkumiesUiIdentityWrite($path, $serviceId, $user);
}

/** Refuse a port owned by a service other than nginx. */
function pmssPikkumiesUiPortAvailable(int $port, ?string $listeners = null): bool
{
    if ($listeners === null) {
        $rows = [];
        exec('ss -H -ltnp 2>/dev/null', $rows, $rc);
        if ($rc !== 0) return false;
        $listeners = implode("\n", $rows);
    }
    foreach (explode("\n", $listeners) as $row) {
        $fields = pmssConfigLineColumns($row, 0, []);
        $local = $fields[3] ?? '';
        if (preg_match('/(?:^|:|\])'.preg_quote((string) $port, '/').'$/', $local) === 1
            && strpos($row, '"nginx"') === false) return false;
    }
    return true;
}

/** The service name must already have an issued per-name certificate. */
function pmssPikkumiesUiCertificateReady(string $user, string $host, string $live = '/etc/letsencrypt/live'): bool
{
    $lineage = $live.'/'.$user.'.'.$host;
    return is_file($lineage.'/fullchain.pem') && is_file($lineage.'/privkey.pem');
}

/** Default host consumes stray SNI/Host values before an account vhost can match. */
function pmssPikkumiesUiDefaultNginxBlock(string $sslBlock): string
{
    return pmssPikkumiesUiDefaultServer("    listen 844 ssl default_server;\n", $sslBlock);
}

function pmssPikkumiesHermesDefaultNginxBlock(string $sslBlock): string
{
    return pmssPikkumiesUiDefaultServer("    listen 845 ssl default_server;\n", $sslBlock);
}

function pmssHermesUiDefaultNginxBlock(string $sslBlock): string
{
    return pmssPikkumiesUiDefaultServer("    listen 846 ssl default_server;\n", $sslBlock);
}

// Loading the facade retains every historical rendering entrypoint.
require_once __DIR__.'/pikkumiesUiFragments.php';
require_once __DIR__.'/pikkumiesUiSimplified.php';
require_once __DIR__.'/pikkumiesUiHermes.php';
require_once __DIR__.'/pikkumiesUiChoice.php';
