<?php
/** Account-owned vanilla Hermes lifecycle. Invoked only by the locked wrapper. */
require_once __DIR__.'/pmssHermesInstall.php';

function pmssHermesGateReady(array $paths): bool
{
    if (!is_file($paths['exe']) || is_link($paths['exe'])) return false;
    list($urlRc, $url) = pmssAgentRun(array($paths['exe'], 'config', 'get', 'dashboard.public_url'));
    list($userRc, $user) = pmssAgentRun(array($paths['exe'], 'config', 'get', 'dashboard.basic_auth.username'));
    return $urlRc === 0 && $userRc === 0
        && trim($url, " \t\r\n\"") === pmssHermesPublicUrl($paths['home'])
        && trim($user, " \t\r\n\"") === basename($paths['home']);
}

function pmssHermesRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $item) if (!$item->isDot()) pmssHermesRemoveTree($item->getPathname());
    rmdir($path);
}

function pmssHermesMain(array $args): int
{
    $verb = $args[1] ?? '';
    if (!in_array($verb, array('install', 'start', 'stop', 'status', 'uninstall', 'repair-gate', 'rotate-session', 'reconcile-key'), true)) {
        echo "Usage: install-hermes install | start | stop | status | uninstall [--purge] | repair-gate | rotate-session | reconcile-key\n";
        return $verb === '' ? 0 : 1;
    }
    $paths = pmssHermesPaths(pmssAgentHome());
    if ($verb === 'install') {
        try {
            pmssHermesInstall($paths, pmssHermesPublicUrl($paths['home']));
            file_put_contents($paths['base'].'/pmss-install.status', "ready\n", LOCK_EX);
        } catch (Throwable $error) {
            if (is_dir($paths['base']) && !is_link($paths['base']))
                file_put_contents($paths['base'].'/pmss-install.status',
                    $error instanceof PmssHermesCustomerTreeChanged ? "customer-updated\n" : "failed\n", LOCK_EX);
            throw $error;
        }
    } elseif ($verb === 'rotate-session') {
        pmssHermesSessionRotate($paths);
    } elseif ($verb === 'reconcile-key') {
        pmssHermesProviderKeyRefresh($paths);
    } elseif ($verb === 'repair-gate') {
        if (!is_file($paths['exe'])) throw new RuntimeException('Hermes is not installed');
        pmssHermesCredentialsEnsure($paths);
        pmssHermesConfigSet($paths, 'dashboard.public_url', pmssHermesPublicUrl($paths['home']));
        pmssHermesConfigSet($paths, 'dashboard.basic_auth.username', basename($paths['home']));
    } elseif ($verb === 'start') {
        if (!pmssHermesGateReady($paths)) throw new RuntimeException('Dashboard login gate is not configured');
        @unlink($paths['disabled']);
        pmssHermesStart($paths);
    } elseif ($verb === 'stop' || $verb === 'uninstall') {
        if (!is_dir($paths['base']) || is_link($paths['base'])) throw new RuntimeException('Hermes state is absent or unsafe');
        file_put_contents($paths['disabled'], "stopped\n", LOCK_EX);
        pmssHermesStop($paths);
        if ($verb === 'uninstall') {
            file_put_contents($paths['base'].'/pmss-install.status', "removed\n", LOCK_EX);
            // Remove only old installer lines; there is no new cron supervisor.
            pmssAgentCronApply($paths['home'], '# pmss-hermes', array());
            if (in_array('--purge', $args, true)) {
                $launcher = $paths['home'].'/.local/bin/hermes';
                if (is_link($launcher) && strpos((string) readlink($launcher), $paths['tree']) !== false) unlink($launcher);
                pmssHermesRemoveTree($paths['base']);
            }
        }
    } else echo pmssHermesListenerReady($paths, pmssAgentReservedPort($paths['home'].'/.media-stack-port-hermes') ?: 0)
        ? "running\n" : "stopped\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(pmssHermesMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(1); }
}
