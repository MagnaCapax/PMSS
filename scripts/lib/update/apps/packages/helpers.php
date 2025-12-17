<?php
/**
 * Helper utilities for package installation routines.
 */

require_once __DIR__.'/../../runtime/commands.php';

putenv('DEBIAN_FRONTEND=noninteractive');
putenv('APT_LISTCHANGES_FRONTEND=none');

const PMSS_PACKAGE_QUEUE_DEFAULT = '__default__';

// Global state shared between installer helpers:
//   - PMSS_PACKAGE_QUEUE: keyed by target suite (default/backports) with unique
//     package names to install.
//   - PMSS_POST_INSTALL_COMMANDS: array of [description, command] pairs executed
//     after all queues flush successfully.
//   - PMSS_PACKAGE_WARNINGS / ERRORS: strings describing skipped or failed
//     packages; summarised into environment variables for update-step2.
$GLOBALS['PMSS_PACKAGE_QUEUE'] = $GLOBALS['PMSS_PACKAGE_QUEUE'] ?? [];
$GLOBALS['PMSS_POST_INSTALL_COMMANDS'] = $GLOBALS['PMSS_POST_INSTALL_COMMANDS'] ?? [];
$GLOBALS['PMSS_PACKAGE_WARNINGS'] = $GLOBALS['PMSS_PACKAGE_WARNINGS'] ?? [];
$GLOBALS['PMSS_PACKAGE_ERRORS'] = $GLOBALS['PMSS_PACKAGE_ERRORS'] ?? [];

function pmssQueuePackages(array $packages, ?string $target = null): void
{
    global $PMSS_PACKAGE_QUEUE;
    $key = $target ?? PMSS_PACKAGE_QUEUE_DEFAULT;
    if (!isset($PMSS_PACKAGE_QUEUE[$key])) { $PMSS_PACKAGE_QUEUE[$key] = []; }
    foreach ($packages as $pkg) {
        $pkg = trim($pkg);
        if ($pkg !== '' && !in_array($pkg, $PMSS_PACKAGE_QUEUE[$key], true)) {
            $PMSS_PACKAGE_QUEUE[$key][] = $pkg;
        }
    }
}

// #TODO Retire this queue once dpkg baselines are authoritative for every
//       package on every host. Add a diff summary step that logs packages
//       present-but-not-in-baseline and missing-from-host to help converge
//       systems before removing the queue entirely.

function pmssFlushPackageQueue(): void
{
    global $PMSS_PACKAGE_QUEUE, $PMSS_POST_INSTALL_COMMANDS, $PMSS_PACKAGE_WARNINGS, $PMSS_PACKAGE_ERRORS;
    if (empty($PMSS_PACKAGE_QUEUE)) {
        return;
    }

    $logNotice = function_exists('logmsg')
        ? 'logmsg'
        : function (string $message): void { echo $message."\n"; };

    foreach ($PMSS_PACKAGE_QUEUE as $target => $packages) {
        $isDefaultTarget = $target === PMSS_PACKAGE_QUEUE_DEFAULT;
        $missingContext = $isDefaultTarget ? 'default queue' : ('queue '.$target);
        $installContext = $isDefaultTarget ? 'package queue' : ('package queue '.$target);

        $packages = array_unique(array_filter($packages));
        if (empty($packages)) {
            continue;
        }

        $installable = [];
        $missing     = [];
        foreach ($packages as $pkg) {
            if (!pmssPackageAvailable($pkg)) { $missing[] = $pkg; continue; }
            // Skip packages already installed to reduce apt noise and runtime.
            if (pmssPackageStatus($pkg) !== 'install ok installed') { $installable[] = $pkg; }
        }
        if (!empty($missing)) {
            $message = sprintf('Skipping unavailable packages in %s: %s', $missingContext, implode(', ', $missing));
            $logNotice('[WARN] '.$message);
            $PMSS_PACKAGE_WARNINGS[] = $message;
        }
        if (empty($installable)) {
            continue;
        }

        $pkgArgs = implode(' ', array_map('escapeshellarg', $installable));
        $label = $isDefaultTarget ? 'Installing packages' : ('Installing packages ('.$target.')');
        $cmd = aptCmd('install -y'.($isDefaultTarget ? '' : (' -t '.escapeshellarg($target))).' '.$pkgArgs);

        $rc = runStep($label, $cmd);
        if ($rc !== 0) {
            runStep('Attempting apt fix-broken install ('.$installContext.')', aptCmd('--fix-broken install -y'));
            $retryRc = runStep($label.' retry', $cmd);
            if ($retryRc !== 0) {
                $message = sprintf('Final package install failure in %s: %s', $installContext, implode(', ', $installable));
                $logNotice('[ERROR] '.$message);
                $PMSS_PACKAGE_ERRORS[] = $message;
            }
        }
    }

    $PMSS_PACKAGE_QUEUE = [];

    if (!empty($PMSS_POST_INSTALL_COMMANDS)) {
        foreach ($PMSS_POST_INSTALL_COMMANDS as [$description, $command]) {
            runStep($description, $command);
        }
        $PMSS_POST_INSTALL_COMMANDS = [];
    }

    $summary = !empty($PMSS_PACKAGE_WARNINGS) ? array_unique($PMSS_PACKAGE_WARNINGS) : [];
    if (!empty($summary)) {
        $logNotice('[WARN] Package queue warnings: '.implode(' | ', $summary));
        $PMSS_PACKAGE_WARNINGS = [];
    }
    putenv('PMSS_PACKAGE_INSTALL_WARNINGS='.count($summary));

    $summary = !empty($PMSS_PACKAGE_ERRORS) ? array_unique($PMSS_PACKAGE_ERRORS) : [];
    if (!empty($summary)) {
        $logNotice('[ERROR] Package queue errors: '.implode(' | ', $summary));
        if (function_exists('pmssLogJson')) {
            pmssLogJson([
                'event'   => 'package_queue_failure',
                'issues'  => $summary,
            ]);
        }
        $PMSS_PACKAGE_ERRORS = [];
    }
    putenv('PMSS_PACKAGE_INSTALL_ERRORS='.count($summary));
}

/**
 * Return the dpkg status string for a package or an empty string when missing.
 */
function pmssPackageStatus(string $package): string
{
    $cmd = 'dpkg-query -W -f=\'${Status}\' '.escapeshellarg($package).' 2>/dev/null';
    exec($cmd, $output, $rc);
    return $rc === 0 ? trim($output[0] ?? '') : '';
}

/**
 * Determine if a package is available in the current apt cache.
 */
function pmssPackageAvailable(string $package): bool
{
    static $cache = [];
    static $availableSet = null;
    if (array_key_exists($package, $cache)) {
        return $cache[$package];
    }

    if ($availableSet === null) {
        $out = [];
        exec('apt-cache pkgnames 2>/dev/null', $out, $rc);
        $availableSet = [];
        if ($rc === 0 && !empty($out)) {
            foreach ($out as $name) {
                $name = strtolower(trim($name));
                if ($name !== '') {
                    $availableSet[$name] = true;
                }
            }
        }
    }

    // Fast path: consult a prebuilt set of available package names.
    $nameOnly = strtolower(preg_replace('/:.+$/', '', $package));
    if (!empty($availableSet)) {
        return $cache[$package] = isset($availableSet[$nameOnly]);
    }

    // Fallback: query apt-cache policy (slower, per package)
    $cmd = 'apt-cache policy '.escapeshellarg($package).' 2>/dev/null';
    exec($cmd, $output, $rc);
    if ($rc !== 0 || empty($output)) {
        return $cache[$package] = false;
    }
    foreach ($output as $line) {
        if (stripos($line, 'Candidate:') !== false) {
            return $cache[$package] = (stripos($line, '(none)') === false);
        }
    }
    return $cache[$package] = true;
}

/**
 * Install packages, allowing each entry to specify fallback candidates.
 */
function pmssInstallBestEffort(array $items, string $label = ''): void
{
    $selection = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            foreach ($item as $candidate) {
                if (pmssPackageAvailable($candidate)) {
                    $selection[] = $candidate;
                    break;
                }
            }
        } elseif (pmssPackageAvailable($item)) {
            $selection[] = $item;
        }
    }
    $selection = array_unique($selection);
    if (empty($selection)) {
        if ($label !== '') {
            echo "Notice: No packages available for {$label}\n";
        }
        return;
    }
    pmssQueuePackages($selection);
}

/**
 * Install the ProFTPD stack with recovery for half-installed states.
 *
 * ProFTPD routinely wedges dpkg when hostname lookups or TLS material are
 * missing. A failed post-install script leaves the entire package manager in a
 * broken state, so we unmask the unit up front and retry configuration with a
 * reduced package set. Keep this guarded flow intact unless ProFTPD finally
 * stops treating minor config issues as fatal errors.
 */
function pmssInstallProftpdStack(int $distroVersion): void
{
    // Avoid re-queueing ProFTPD packages; baseline selections handle presence.
    // Only ensure supporting packages we actually need (nftables on newer Debian).
    if ($distroVersion >= 10) {
        if (is_dir('/run/systemd/system')) {
            runStep('Ensuring proftpd unit is not masked', 'systemctl unmask proftpd || true');
        }
        pmssQueuePackages(['nftables']);
    }

    // Only queue a reconfigure if dpkg reports half-configured state.
    $proftpdPkgs = ['proftpd-core', 'proftpd-mod-crypto', 'proftpd-mod-wrap', 'proftpd-basic'];
    foreach ($proftpdPkgs as $pkg) {
        $status = pmssPackageStatus($pkg);
        if ($status !== '' && $status !== 'install ok installed') {
            global $PMSS_POST_INSTALL_COMMANDS;
            $PMSS_POST_INSTALL_COMMANDS[] = [
                'Reconfiguring proftpd packages',
                'dpkg --configure proftpd-core proftpd-mod-crypto proftpd-mod-wrap proftpd-basic || true',
            ];
            return;
        }
    }
    if (function_exists('logmsg')) { logmsg('[SKIP] ProFTPD packages already configured'); }
}
