#!/usr/bin/env php
<?php
/**
 * PMSS Update Script (dynamic portion)
 *
 * Handles the heavy lifting of system updates once the static updater
 * (/scripts/update.php) refreshes itself. Tasks include repository setup,
 * service configuration, user environment maintenance and security tweaks.
 *
 * Package phase invariant: repository templating, dpkg baseline replay, and
 * queued package installs must succeed before any other module executes. Do
 * not insert additional orchestration ahead of the package phase.
 *
 * This file is refreshed from GitHub by /scripts/update.php prior to each run.
 * Keep local changes minimal or contribute them upstream.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

// Cap PHP memory use so we fail fast with a PHP fatal instead of a host-wide OOM kill.
@ini_set('memory_limit', '4096M');

// Bootstrap the shared logger before loading update helpers so include-time
// logs and standalone update-step2 runs share the same sink.
require_once __DIR__.'/../lib/logger.php';
if (!isset($GLOBALS['logmsg_default_logger'])) {
    $GLOBALS['logmsg_default_logger'] = new Logger(__FILE__, '/var/log', '/tmp', 'pmss-update', true);
}

// Module load order mirrors the runtime sequence. Keep shared runtime helpers
// first, followed by environment detection, repository setup, system prep, web
// stack, service bundles, user refresh, networking, and finally bootstrap
// helpers. When adding a new orchestrator, pick the insertion point carefully
// and document its failure behaviour so future maintainers know whether errors
// should halt the run or log-and-continue.
require_once __DIR__.'/../lib/update.php';
require_once __DIR__.'/../lib/update/runtime/profile.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/update/runtime/stepPolicy.php';
require_once __DIR__.'/../lib/update/runtime/processes.php';
require_once __DIR__.'/../lib/update/environment.php';
require_once __DIR__.'/../lib/update/distro.php';
require_once __DIR__.'/../lib/update/repositories.php';
require_once __DIR__.'/../lib/update/systemPrep.php';
require_once __DIR__.'/../lib/update/services/systemd.php';
require_once __DIR__.'/../lib/update/services/logging.php';
require_once __DIR__.'/../lib/update/services/mountHardening.php';
require_once __DIR__.'/../lib/update/userMaintenance.php';
require_once __DIR__.'/../lib/update/networking.php';
require_once __DIR__.'/../lib/update/services/bootstrap.php';
require_once __DIR__.'/../lib/motd/Generator.php';

requireRoot();

if (!defined('PMSS_UPDATE_LOCK_FILE')) {
    define('PMSS_UPDATE_LOCK_FILE', '/var/run/pmss/update.lock');
}
if (!defined('PMSS_UPDATE_LOCK_ENV')) {
    define('PMSS_UPDATE_LOCK_ENV', 'PMSS_UPDATE_LOCK_HELD');
}

/**
 * Switch legacy lighttpd instances to nginx and refresh configs.
 */
function pmssConfigureWebStack(int $distroVersion): void
{
    pmssUpdateStep2MarkWebRefreshRequired();

    // Stop nginx first so package upgrades and template refreshes never race against an active daemon.
    runStep('Stopping nginx prior to configuration refresh', 'systemctl stop nginx || /etc/init.d/nginx stop || true');
    runStep($distroVersion < 10 ? 'Stopping lighttpd (init.d)' : 'Stopping lighttpd (systemd)', '/etc/init.d/lighttpd stop');
    if ($distroVersion < 10) {
        runStep('Disabling lighttpd from sysvinit runlevels', 'update-rc.d lighttpd stop 2 3 4 5');
        runStep('Removing lighttpd sysvinit hooks', 'update-rc.d lighttpd remove');
    } else {
        pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');
    }
    killProcess('lighttpd', 'Terminating lingering lighttpd processes');
    killProcess('php-cgi', 'Terminating lingering php-cgi processes');
    if ($distroVersion < 10) {
        runStep('Ensuring nginx defaults set in sysvinit', 'update-rc.d nginx defaults');
    } else {
        pmssSystemdUnitActionIfPresent('nginx', 'Enabling nginx systemd service', 'enable');
    }

    // Per-user lighttpd configuration, htpasswd sync, and instance checks
    // are handled inside the consolidated per-user maintenance loop.
    // nginx config regeneration runs once after app installers finish, so do
    // not duplicate it here. nginx stays stopped until that final refresh.
    runStep('Setting /home directory permissions', 'chmod 751 /home');
    // Quota state files reject chmod; prune them so the find commands stay noise-free.
    $prune = '\( -name "aquota.*" -o -name "lost+found" \)';
    foreach ([
        ['Hardening /home tenant directories', 'd', '700'],
        ['Hardening /home tenant files', 'f', '600'],
    ] as $hardeningStep) {
        runStep(
            $hardeningStep[0],
            sprintf('find /home -mindepth 1 -maxdepth 1 %s -prune -o -type %s -exec chmod %s {} +', $prune, $hardeningStep[1], $hardeningStep[2])
        );
    }
}

/**
 * Run non-shell orchestration work with profiling metadata.
 *
 * Wrapper keeps phase-2 profiling complete even when a step is pure PHP and
 * does not invoke runStep() directly.
 *
 * @param callable $step
 * @return mixed
 */
function pmssRunProfiledStep(string $description, callable $step)
{
    $started = microtime(true);
    logmsg('[START] '.$description.' :: [callable]');

    try {
        $result = $step();
    } catch (\Throwable $throwable) {
        $duration = microtime(true) - $started;
        pmssLogStatus('ERR', $description, 1, $duration);
        throw $throwable;
    }

    $duration = microtime(true) - $started;
    pmssLogStatus('OK', $description, 0, $duration);

    return $result;
}

/**
 * Profile a direct function/method invocation with optional arguments.
 *
 * @param callable $callable
 * @param array<int, mixed> $arguments
 * @return mixed
 */
function pmssRunProfiledCallable(string $description, callable $callable, array $arguments = [])
{
    return pmssRunProfiledStep($description, static function () use ($callable, $arguments) { return $callable(...$arguments); });
}

/**
 * Execute a profiled callable using the configured step classification policy.
 */
function pmssUpdateStep2RunClassifiedCallable(string $description, callable $callable, array $arguments, string $classification): void
{
    try {
        pmssRunProfiledCallable($description, $callable, $arguments);
    } catch (\Throwable $throwable) {
        $reason = get_class($throwable).($throwable->getMessage() !== '' ? ': '.$throwable->getMessage() : '');
        pmssUpdateStep2HandleClassifiedFailure($description, $classification, 1, $reason);
    }
}

/**
 * Mark that nginx has been stopped and needs a final refresh before exit.
 */
function pmssUpdateStep2MarkWebRefreshRequired(): void
{
    $GLOBALS['PMSS_UPDATE_STEP2_WEB_REFRESH_REQUIRED'] = true;
}

/**
 * Mark that the final nginx refresh has completed successfully.
 */
function pmssUpdateStep2MarkWebRefreshCompleted(): void
{
    $GLOBALS['PMSS_UPDATE_STEP2_WEB_REFRESH_COMPLETED'] = true;
}

/**
 * Attempt a best-effort nginx start when the final refresh could not finish.
 */
function pmssUpdateStep2StartNginxShutdownFallback(string $reason): void
{
    logmsg('[WARN] update-step2 exited with nginx still pending final refresh; attempting direct start (reason: '.$reason.')');
    pmssLogJson([
        'event' => 'post_update_nginx_start_fallback',
        'status' => 'start',
        'reason' => $reason,
    ]);

    $rc = 0;
    passthru('systemctl start nginx 2>/dev/null || /etc/init.d/nginx start 2>/dev/null', $rc);

    pmssLogJson([
        'event' => 'post_update_nginx_start_fallback',
        'status' => $rc === 0 ? 'ok' : 'error',
        'rc' => $rc,
    ]);
    logmsg(sprintf('[WARN] Direct nginx start fallback completed with rc=%d', $rc));
}

/**
 * Attempt a best-effort nginx config regeneration if update-step2 exits early.
 */
function pmssUpdateStep2RegisterWebRefreshShutdownGuard(): void
{
    register_shutdown_function(static function (): void {
        $refreshRequired = !empty($GLOBALS['PMSS_UPDATE_STEP2_WEB_REFRESH_REQUIRED']);
        $refreshCompleted = !empty($GLOBALS['PMSS_UPDATE_STEP2_WEB_REFRESH_COMPLETED']);
        $scriptCompleted = !empty($GLOBALS['PMSS_UPDATE_STEP2_COMPLETED']);
        if (!$refreshRequired || $refreshCompleted || $scriptCompleted) {
            return;
        }

        if (!file_exists('/scripts/util/createNginxConfig.php')) {
            logmsg('[WARN] update-step2 exited before final nginx refresh; createNginxConfig.php missing');
            pmssUpdateStep2StartNginxShutdownFallback('create_nginx_config_missing');
            return;
        }

        $error = error_get_last();
        $reason = 'early_exit';
        if (is_array($error) && isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
            $reason = $error['message'];
        }

        logmsg('[WARN] update-step2 exited before final nginx refresh; attempting rescue run (reason: '.$reason.')');
        pmssLogJson([
            'event' => 'post_update_web_refresh_rescue',
            'status' => 'start',
            'reason' => $reason,
        ]);

        $rc = 0;
        passthru('/scripts/util/createNginxConfig.php --restart', $rc);

        pmssLogJson([
            'event' => 'post_update_web_refresh_rescue',
            'status' => $rc === 0 ? 'ok' : 'error',
            'rc' => $rc,
        ]);
        logmsg(sprintf('[WARN] Rescue nginx refresh completed with rc=%d', $rc));
        if ($rc === 0) {
            pmssUpdateStep2MarkWebRefreshCompleted();
            return;
        }

        pmssUpdateStep2StartNginxShutdownFallback('web_refresh_rescue_failed');
    });
}

pmssUpdateStep2RegisterWebRefreshShutdownGuard();

pmssRunProfiledCallable('Acquiring update-step2 lock', static function (): void {
    if (getenv(PMSS_UPDATE_LOCK_ENV) === '1') {
        return;
    }
    $dir = dirname(PMSS_UPDATE_LOCK_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fh = @fopen(PMSS_UPDATE_LOCK_FILE, 'c');
    if ($fh === false) {
        logmsg('Unable to open update lock file: '.PMSS_UPDATE_LOCK_FILE);
        exit(1);
    }
    pmssLogJson(['event' => 'update_lock_wait', 'path' => PMSS_UPDATE_LOCK_FILE]);
    if (!flock($fh, LOCK_EX)) {
        logmsg('Unable to acquire update lock (flock failed)');
        exit(1);
    }
    $GLOBALS['PMSS_UPDATE_LOCK_HANDLE'] = $fh;
    putenv(PMSS_UPDATE_LOCK_ENV.'=1');
    pmssLogJson(['event' => 'update_lock_acquired', 'path' => PMSS_UPDATE_LOCK_FILE]);
    register_shutdown_function(static function (): void {
        if (!isset($GLOBALS['PMSS_UPDATE_LOCK_HANDLE'])) {
            return;
        }
        @flock($GLOBALS['PMSS_UPDATE_LOCK_HANDLE'], LOCK_UN);
        @fclose($GLOBALS['PMSS_UPDATE_LOCK_HANDLE']);
        unset($GLOBALS['PMSS_UPDATE_LOCK_HANDLE']);
        putenv(PMSS_UPDATE_LOCK_ENV);
        pmssLogJson(['event' => 'update_lock_released', 'path' => PMSS_UPDATE_LOCK_FILE]);
    });
});
pmssRunProfiledCallable('Running update-step2 preflight checks', static function (): void {
    $required = 3.0 * 1024 * 1024 * 1024;
    $fatalError = false;

    foreach (['/', '/home'] as $path) {
        if (!is_dir($path)) {
            continue;
        }
        $free = @disk_free_space($path);
        if ($free === false) {
            logmsg("[WARN] Unable to determine free space for {$path}");
            pmssLogJson(['event' => 'preflight_error', 'check' => 'disk_space', 'path' => $path, 'status' => 'warn', 'reason' => 'stat_failed']);
            continue;
        }
        if ($free < $required) {
            $availableGb = round($free / 1073741824, 2);
            $requiredGb  = round($required / 1073741824, 2);
            $fatalError  = true;
            $payload = [
                'event'           => 'preflight_error',
                'check'           => 'disk_space',
                'path'            => $path,
                'status'          => 'error',
                'available_bytes' => $free,
                'required_bytes'  => $required,
            ];
            pmssLogJson($payload);
            logmsg("Insufficient free space on {$path}: {$availableGb} GiB available, {$requiredGb} GiB required");
        }
    }

    // dpkg lock availability (warn only)
    foreach (['/var/lib/dpkg/lock-frontend', '/var/lib/dpkg/lock'] as $lockFile) {
        $fh = @fopen($lockFile, 'c');
        if ($fh === false) {
            pmssLogJson(['event' => 'preflight_error', 'check' => 'dpkg_lock', 'status' => 'warn', 'path' => $lockFile, 'reason' => 'open_failed']);
            logmsg("[WARN] Unable to open dpkg lock file: {$lockFile}");
            continue;
        }
        $locked = flock($fh, LOCK_EX | LOCK_NB);
        if (!$locked) {
            pmssLogJson(['event' => 'preflight_error', 'check' => 'dpkg_lock', 'status' => 'warn', 'path' => $lockFile, 'reason' => 'busy']);
            logmsg("[WARN] dpkg lock appears busy: {$lockFile}");
        } else {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    // apt cache presence/writability (warn only)
    foreach (['/var/cache/apt/archives', '/var/lib/apt/lists'] as $path) {
        if (!is_dir($path) || !is_writable($path)) {
            pmssLogJson(['event' => 'preflight_error', 'check' => 'apt_cache', 'status' => 'warn', 'path' => $path, 'reason' => 'unwritable']);
            logmsg("[WARN] APT cache path missing or not writable: {$path}");
        }
    }

    // Basic network reachability (warn only; skip in dry-run/test mode)
    if (!pmssEnvFlagEnabled('PMSS_DRY_RUN') && !pmssEnvFlagEnabled('PMSS_TEST_MODE')) {
        $sock = @fsockopen('deb.debian.org', 80, $errno, $errstr, 3.0);
        if ($sock === false) {
            pmssLogJson(['event' => 'preflight_error', 'check' => 'network', 'status' => 'warn', 'reason' => 'unreachable', 'host' => 'deb.debian.org', 'errno' => $errno, 'error' => $errstr]);
            logmsg('[WARN] Unable to reach deb.debian.org: '.$errstr.' ('.$errno.')');
        } else {
            fclose($sock);
        }
    }

    if ($fatalError) {
        logmsg('Preflight checks failed (fatal) - aborting update-step2');
        exit(1);
    }

    pmssLogJson(['event' => 'preflight_ok']);
});

// Ensure the root cron template is restored even if the updater exits early.
// Phase 1 disables `/etc/cron.d/pmss` to avoid cron activity while the tree is
// partially refreshed; if phase 2 crashes, we still want cron back on the next boot.
$pmssRootCronRestored = false;
register_shutdown_function(function () use (&$pmssRootCronRestored): void {
    if ($pmssRootCronRestored) {
        return;
    }
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        return;
    }
    $helper = '/scripts/util/setupRootCron.php';
    if (!is_file($helper)) {
        return;
    }
    runStep('Restoring root cron configuration (shutdown)', $helper);
});

// Preflight: ensure root can keep forking during long updates even if legacy TasksMax caps are present.
// Safe before the package phase; avoids "Cannot fork" cascades inside user-0.slice.
runStep('Ensuring root user slice TasksMax is unlimited (preflight)', "systemctl set-property --runtime 'user-0.slice' MemoryHigh=infinity MemoryMax=infinity TasksMax=infinity");

$distribution  = pmssRunProfiledCallable('Detecting distribution metadata', 'pmssDetectDistro');
$distroName    = $distribution['name'];
$distroVersion = $distribution['version'];
$lsbCodename   = $distribution['codename'];
$reportedVersion = (int) $distroVersion;
$repoVersion     = pmssVersionFromCodename($lsbCodename);
$repoLogMessage  = '';
if ($repoVersion === 0 && $reportedVersion > 0) {
    $repoVersion    = $reportedVersion;
    $repoLogMessage = sprintf('Repository codename %s unresolved; falling back to VERSION_ID %d', $lsbCodename !== '' ? $lsbCodename : 'unknown', $repoVersion);
} elseif ($repoVersion === 0 && $reportedVersion === 0) {
    $repoLogMessage = sprintf('Repository detection failed for distro=%s codename=%s; skipping repository updates', $distroName, $lsbCodename !== '' ? $lsbCodename : 'unknown');
}
if ($repoVersion !== 0 && $reportedVersion !== 0 && $repoVersion !== $reportedVersion) {
    $repoLogMessage = sprintf('Repository version mapped via codename %s -> %d (reported=%s)', $lsbCodename !== '' ? $lsbCodename : 'unknown', $repoVersion, $distroVersion);
}

if ($reportedVersion > 0 && $reportedVersion < 10) {
    logmsg(sprintf('Detected unsupported Debian release %s; aborting', $distroVersion));
    pmssLogJson(['event' => 'update-step2', 'status' => 'error', 'reason' => 'unsupported_debian', 'version' => $distroVersion]);
    exit(1);
}

putenv('PMSS_DISTRO_NAME='.$distroName);
putenv('PMSS_DISTRO_VERSION='.(string) $reportedVersion);
putenv('PMSS_DISTRO_CODENAME='.$lsbCodename);

putenv('DEBIAN_FRONTEND=noninteractive');
putenv('APT_LISTCHANGES_FRONTEND=none');
putenv('UCF_FORCE_CONFOLD=1');
putenv('UCF_FORCE_CONFNEW=0');
putenv('UCF_FORCE_CONFDEF=1');
putenv('NEEDRESTART_MODE=a');

$GLOBALS['PMSS_PACKAGES_READY'] = false;
// PMSS_PACKAGE_PHASE advertises coarse progress (`initializing`/`complete`); unknown values mean "in progress".
// Flip it after logging the matching step so external monitors keep ordering intact.
putenv('PMSS_PACKAGE_PHASE=initializing');

$effectiveRepoVersion = $repoVersion > 0 ? $repoVersion : $reportedVersion;

logmsg('Update-step2 log: /var/log/pmss-update.log (fallback /tmp/pmss-update.log)');
$jsonPath = pmssJsonLogPath();
$pmssCorrelationId = pmssCorrelationId();
if ($jsonPath !== '') {
    logmsg('JSON events: '.$jsonPath);
}
if ($pmssCorrelationId !== '') {
    logmsg('PMSS correlation ID: '.$pmssCorrelationId);
}
logmsg('update-step2.php starting');
pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'start']);

pmssRunProfiledCallable('Preparing noninteractive apt defaults', 'pmssConfigureAptNonInteractive', ['logmsg']);
pmssRunProfiledStep('Cleaning mediaarea bootstrap package state', static function (): void {
    $status = trim((string) @shell_exec('dpkg-query -W -f=${Status} repo-mediaarea 2>/dev/null'));
    if ($status === '' || stripos($status, 'not-installed') !== false) {
        return;
    }

    runStep(
        'Removing legacy MediaArea bootstrap package (repo-mediaarea)',
        'dpkg --remove --force-remove-reinstreq repo-mediaarea || true'
    );

    $setSelection = "printf '%s\\t%s\\n' 'repo-mediaarea' 'deinstall' | dpkg --set-selections";
    runStep('Marking repo-mediaarea for deinstallation', $setSelection);
});
pmssRunProfiledCallable('Pruning legacy MediaArea repository entries', 'pmssPruneLegacyMediaArea');

// --- PACKAGE PHASE: DO NOT REORDER ---------------------------------------------------------
// Everything below depends on distro packages being in a good state. Toolchains, service
// binaries, and build scripts all assume apt has already delivered their dependencies. If
// this sequence changes, expect cascading failures across the update flow.
//   1. Attempt to recover partially configured packages (`apt --fix-broken`)
//   2. Refresh repositories (apt update) so we pull the latest metadata
//   3. Autoremove strays that block upgrades
//   4. Apply the dpkg baseline and queued package installs
// Resist the urge to move or delete any of these steps.
// -------------------------------------------------------------------------------------------

runStep('Attempting apt fix-broken install (pre-package phase)', aptCmd('--fix-broken install -y'));
// Ensure core packaging tools are current before bootstrapping third-party repos
// This is now handled by the main repo refresh + dpkg baseline to avoid redundant updates.
pmssRunProfiledCallable('Refreshing package repositories', 'pmssRefreshRepositories', [$distroName, $effectiveRepoVersion, 'logmsg']);
pmssRunProfiledCallable('Completing pending dpkg configurations', 'pmssCompletePendingDpkg');
$dpkgBaselineOk = pmssRunProfiledCallable('Applying distro dpkg baseline selections', 'pmssApplyDpkgSelections', [$effectiveRepoVersion > 0 ? $effectiveRepoVersion : null, true]);

// System-wide services must not run on seedbox hosts. Stop/disable early so
// package installs cannot leave attack surface exposed for the rest of the run.
pmssRunProfiledCallable('Applying system service disable/mask policy (pre-app)', 'pmssStopDisableMaskSeedboxSystemServices');
// Purge unbound DNS resolver if it is in failed state (external nameservers used)
pmssRunProfiledCallable('Purging failed unbound daemon if present', 'pmssPurgeFailedUnbound');
// Ensure the boot-time guard is installed/enabled so masked services cannot
// start during the next reboot even if systemd enablement drifts.
pmssRunProfiledCallable('Ensuring systemd service guard boot unit', 'pmssEnsureSystemdServicesGuardBootUnit');
// Legacy hosts occasionally re-enable Apache during package recovery; perform
// the stop/disable/mask sequence twice so hosts drifting between bullseye and
// bookworm converge reliably. Success = units masked and no apache2 processes
// left running. Failure is tolerated but logged via runStep.
pmssRunProfiledCallable('Hardening legacy Apache systemd unit (pre-purge)', 'pmssStopDisableMaskSystemdUnit', ['apache2', 'Apache httpd (legacy)', true]);
// Remove legacy Apache packages; keep apache2-utils. It provides htpasswd (used by
// lighttpd basic auth) and ab; removing it breaks auth setup and other scripts.
runStep('Removing residual Apache packages', aptCmd('purge -y apache2 apache2-bin apache2-data libapache2-mod-php7.4 || true'));
pmssRunProfiledCallable('Hardening legacy Apache systemd unit (post-purge)', 'pmssStopDisableMaskSystemdUnit', ['apache2', 'Apache httpd (legacy)', true]);
if ($repoLogMessage !== '') { logmsg($repoLogMessage); }
if (!$dpkgBaselineOk) {
    logmsg('[WARN] Dpkg baseline application reported issues; attempting recovery');
    runStep('Attempting apt fix-broken install (dpkg baseline recovery)', aptCmd('--fix-broken install -y'));
    $dpkgBaselineOk = pmssRunProfiledCallable('Reapplying distro dpkg baseline selections', 'pmssApplyDpkgSelections', [$effectiveRepoVersion > 0 ? $effectiveRepoVersion : null, true]);
    if (!$dpkgBaselineOk) {
        logmsg('[ERROR] Dpkg baseline still failing after recovery attempt; continuing with caution');
        pmssLogJson(['event' => 'package_phase', 'status' => 'warn', 'reason' => 'dpkg_baseline']);
    }
}

// Package convergence: dpkg selections are now the authoritative source of
// package state. The legacy per-app queue module remains in-tree for
// compatibility tooling, but update-step2 no longer executes it.
putenv('PMSS_PACKAGE_INSTALL_WARNINGS=0');
putenv('PMSS_PACKAGE_INSTALL_ERRORS=0');
logmsg('[OK] Package phase relies on dpkg baseline selections only');

runStep('Attempting apt fix-broken install (post-package phase)', aptCmd('--fix-broken install -y'));
runStep('Removing packages no longer required', aptCmd('autoremove -y'));

$GLOBALS['PMSS_PACKAGES_READY'] = true;
putenv('PMSS_PACKAGE_PHASE=complete');
pmssLogJson(['event' => 'package_phase', 'status' => 'ok']);

pmssRunProfiledCallable('Migrating legacy localnet config path', 'pmssMigrateLegacyLocalnet');
pmssUpdateStep2RunClassifiedCallable('Applying runtime service templates', 'pmssApplyRuntimeTemplates', [], PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED);
pmssRunProfiledCallable('Applying journald runtime limits', 'pmssApplyJournaldLimits', ['logmsg']);
pmssRunProfiledCallable('Applying remote logging configuration', 'pmssApplyRemoteLogging', ['logmsg']);
pmssRunProfiledCallable('Applying hostname configuration', 'pmssApplyHostnameConfig', ['logmsg']);
pmssRunProfiledCallable('Configuring quota mounts', 'pmssConfigureQuotaMount', ['logmsg']);
runStep('Recalculating quota integrity', 'php /scripts/util/quotaFix.php');
pmssRunProfiledCallable('Applying boot defaults', 'pmssEnsureBootDefaults', ['logmsg']);
pmssRunProfiledCallable('Applying legacy sysctl baseline', 'pmssEnsureLegacySysctlBaseline', ['logmsg']);
pmssRunProfiledCallable('Applying boot-time tuning', 'pmssEnsureBootTuning', ['logmsg']);
pmssRunProfiledCallable('Configuring root shell defaults', 'pmssConfigureRootShellDefaults', ['logmsg']);
runStep('Restricting world access to /home', 'chmod o-rw /home');

// --- Basic system preparation ---
pmssRunProfiledCallable('Ensuring cgroup configuration', 'pmssEnsureCgroupsConfigured', ['logmsg']);
pmssRunProfiledCallable('Ensuring systemd slices', 'pmssEnsureSystemdSlices', ['logmsg']);
runStep('Resetting /etc/seedbox permissions', 'chmod -R 755 /etc/seedbox');
runStep('Resetting /scripts permissions', 'chmod -R 750 /scripts');
pmssRunProfiledCallable('Ensuring locale baseline', 'pmssEnsureLocaleBaseline');

// Web stack hardening and per-user HTTP refresh.
pmssUpdateStep2RunClassifiedCallable('Configuring web stack', 'pmssConfigureWebStack', [$distroVersion], PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED);

// Configure OpenVPN via dedicated utility for better logging/observability.
runStep('Configuring OpenVPN', 'php /scripts/util/configureOpenvpn.php');
runStep('Configuring WireGuard', 'php /scripts/util/wireguardConfigure.php');
runStep('Configuring netconsole', 'php /scripts/util/netconsoleConfigure.php');

// Load application installers automatically (sorted for deterministic order),
// but skip helper-only modules and legacy app scripts that are superseded by
// dedicated utilities or retired package-phase orchestration.
$apps = glob('/scripts/lib/update/apps/*.php') ?: [];
sort($apps);
foreach ($apps as $app) {
    $appBase = basename($app);
    if (in_array($appBase, ['arr.php', 'openvpn.php', 'packages.php', 'pythonVenv.php', 'remoteBinary.php'], true)) {
        continue;
    }
    pmssRunProfiledStep('Loading app installer '.basename($app), static function () use ($app): void {
        include_once $app;
    });
}

// Reapply system service disablement after app installers in case any package
// postinst scripts (re)started daemons mid-update.
pmssRunProfiledCallable('Applying system service disable/mask policy (post-app)', 'pmssStopDisableMaskSeedboxSystemServices');

runStep('Updating Let\'s Encrypt configuration', '/scripts/util/setupLetsEncrypt.php noreplies@pulsedmedia.com');
// Drop obsolete global autodl configuration
if (file_exists('/etc/autodl.cfg')) { unlink('/etc/autodl.cfg'); }

// Legacy daemons that should never run globally.
foreach (['btsync', 'rslsync', 'pyload', 'sabnzbdplus'] as $legacySvc) {
    if (file_exists('/etc/init.d/'.$legacySvc)) {
        runStep("Stopping legacy service {$legacySvc}", "/etc/init.d/{$legacySvc} stop");
    }
    if ($reportedVersion < 10) {
        runStep("Disabling {$legacySvc} in sysvinit", "update-rc.d {$legacySvc} disable");
    } else {
        pmssSystemdUnitActionIfPresent($legacySvc, "Disabling {$legacySvc} systemd unit", 'disable');
    }
}
pmssRunProfiledStep('Adjusting lighttpd security settings', static function (): void {
    $configDir  = '/etc/lighttpd';
    $configFile = $configDir.'/lighttpd.conf';
    $htpasswd   = $configDir.'/.htpasswd';

    if (!is_dir($configDir)) {
        logmsg('[SKIP] /etc/lighttpd missing; skipping lighttpd hardening');
        return;
    }

    runStep('Restricting /etc/lighttpd directory permissions', 'chmod 750 /etc/lighttpd');

    if (is_file($configFile)) {
        runStep('Adjusting /etc/lighttpd/lighttpd.conf permissions', 'chmod 750 '.$configFile);
        runStep('Setting ownership on /etc/lighttpd/lighttpd.conf', 'chown root:root '.$configFile);
    } else {
        logmsg('[SKIP] lighttpd.conf missing; skipping lighttpd permission adjustments');
    }

    if (is_file($htpasswd)) {
        runStep('Setting ownership on /etc/lighttpd/.htpasswd', 'chown root:root '.$htpasswd);
        runStep('Adjusting /etc/lighttpd/.htpasswd permissions', 'chmod 640 '.$htpasswd);
    } else {
        logmsg('[SKIP] lighttpd .htpasswd missing; per-user instances manage authentication');
    }
});

// Per-user updates ensure ruTorrent stays consistent. The SHA tracks the
// skeleton ruTorrent index version so user instances can be upgraded when the
// template changes.
$rutorrentIndexSha = sha1((string) @file_get_contents('/etc/skel/www/rutorrent/index.html'));
$userMaintenanceSummary = pmssRunProfiledCallable('Updating all user environments', 'pmssUpdateAllUsers', [$rutorrentIndexSha]);
if (is_array($userMaintenanceSummary)) {
    $totalUsers = isset($userMaintenanceSummary['total']) ? (int) $userMaintenanceSummary['total'] : 0;
    $processedUsers = isset($userMaintenanceSummary['processed']) ? (int) $userMaintenanceSummary['processed'] : 0;
    if ($processedUsers < $totalUsers) {
        pmssUpdateStep2HandleClassifiedFailure(
            'Updating all user environments',
            PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED,
            1,
            sprintf('processed_users_mismatch:%d_of_%d', $processedUsers, $totalUsers)
        );
    }
}
// Per-user maintenance now owns crontab restores, htpasswd sync, and lighttpd instance checks.

pmssUpdateStep2RunClassifiedCallable('Ensuring sshd AuthorizedKeysFile directive', 'pmssEnsureAuthorizedKeysDirective', [], PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED);
// Ensure the standard download speed test file exists
$testfilePath = '/var/www/testfile';
if (!file_exists($testfilePath) || filesize($testfilePath) !== 104857600) {
    runStep('Generating /var/www/testfile sample', 'dd if=/dev/urandom of='.$testfilePath.' bs=1M count=100 status=none');
}
runStep('Restricting atop binary permissions', 'chmod 750 /usr/bin/atop');

pmssRunProfiledStep('Running post-update web refresh', static function (): void {
    $refreshRc = runStep('Post-update nginx configuration refresh', '/scripts/util/createNginxConfig.php --restart');
    if ($refreshRc === 0) {
        pmssUpdateStep2MarkWebRefreshCompleted();
    }
});

pmssRunProfiledCallable('Configuring /tmp disk-backed baseline', 'pmssConfigureTempDiskBackedMount', ['logmsg', $distroVersion]);
pmssRunProfiledCallable('Configuring /tmp tmpfs mount policy', 'pmssConfigureTempTmpfsMount', ['logmsg']);
pmssRunProfiledCallable('Configuring /tmp noexec hardening', 'pmssConfigureTempMountNoexec', ['logmsg']);

runStep('Refreshing skeleton permissions', '/scripts/util/setupSkelPermissions.php');
runStep('Refreshing FTP configuration', '/scripts/util/ftpConfig.php');

$logrotateTemplate = '/etc/seedbox/config/template.logrotate.pmss';
if (file_exists($logrotateTemplate)) {
    runStep('Installing logrotate policy for PMSS update logs', sprintf('cp %s /etc/logrotate.d/pmss-update', escapeshellarg($logrotateTemplate)));
    runStep('Setting permissions on PMSS logrotate policy', 'chmod 644 /etc/logrotate.d/pmss-update');
}

pmssRunProfiledCallable('Ensuring network template baseline', 'pmssEnsureNetworkTemplate', ['logmsg']);
runStep('Reapplying network configuration', '/scripts/util/setupNetwork.php');
runStep('Hardening access to session and network binaries', 'chmod o-r /var/log/wtmp /var/run/utmp /usr/bin/netstat /usr/bin/who /usr/bin/w');

// Cleanup legacy runtime metadata that should never have shipped with snapshots.
if (is_dir('/etc/seedbox/config/app-versions')) { runStep('Removing legacy app version records', 'rm -rf '.escapeshellarg('/etc/seedbox/config/app-versions')); }

// Restore root cron at the very end. Phase 1 disables it to avoid cron activity
// during a partial-update window; we want it back for normal operations.
runStep('Refreshing root cron configuration', '/scripts/util/setupRootCron.php');
$pmssRootCronRestored = true;

// Record successful completion for MOTD/monitoring.
pmssWriteManagedPathFile('/var/run/pmss/updated', date('Y-m-d H:i:s'), 'update completion marker', 'logmsg');

// Surface log locations for operators to review after updates.
try {
    $plainLog   = defined('PMSS_LOG_FILE') ? PMSS_LOG_FILE : '/var/log/pmss-update.log';
    $jsonLog    = getenv('PMSS_JSON_LOG') ?: '';
    $profileOut = getenv('PMSS_PROFILE_OUTPUT') ?: ($jsonLog !== '' ? $jsonLog.'.profile.json' : '');

    logmsg('Update logs saved to:');
    logmsg('  - Text:   '.$plainLog);
    if ($jsonLog !== '') { logmsg('  - JSON:   '.$jsonLog); }
    if ($profileOut !== '') { logmsg('  - Profile: '.$profileOut); }
} catch (\Throwable $e) {
    // Fail-soft: logging paths are best-effort only.
}

// Refresh MOTD at the very end so service status reflects final state.
// Consolidated on the Motd class generator for determinism.
pmssRunProfiledCallable('Refreshing MOTD', ['Motd', 'motdGenerate']);

// Mark the end of phase 2 and emit the final summary after all work completed.
$GLOBALS['PMSS_UPDATE_STEP2_COMPLETED'] = true;
pmssProfileSummary();

pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'end']);
if ($pmssCorrelationId !== '') {
    logmsg('PMSS correlation ID: '.$pmssCorrelationId);
}
logmsg('update-step2.php completed');
logmsg('Completed at: '.date('Y-m-d H:i:s'));
