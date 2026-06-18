#!/usr/bin/env php
<?php
/**
 * PMSS Update Script (dynamic portion)
 *
 * Handles the heavy lifting of system updates once the static updater
 * (/scripts/update.php) refreshes itself. Tasks include repository setup,
 * service configuration, user environment maintenance and security tweaks.
 *
 * Package recovery invariant: after lock and fatal preflight checks, finish any
 * interrupted dpkg configuration before warning-only probes or modules execute.
 * Repository templating, dpkg baseline replay, and package installs follow that
 * early recovery pass.
 *
 * This file is refreshed from GitHub by /scripts/update.php prior to each run.
 * Keep local changes minimal or contribute them upstream.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

// Cap PHP memory use so we fail fast with a PHP fatal instead of a host-wide OOM kill.
@ini_set('memory_limit', '4096M');

// Bootstrap the shared legacy logger defaults before loading update helpers so
// include-time logs and standalone update-step2 runs share the same sink.
require_once __DIR__.'/../lib/log.php';
$GLOBALS['PMSS_LOGMSG_DEFAULTS'] = array_replace([
    'script' => __FILE__, 'dir' => '/var/log', 'fallback_dir' => '/tmp', 'base_name' => 'pmss-update', 'write_to_stderr' => true,
], isset($GLOBALS['PMSS_LOGMSG_DEFAULTS']) && is_array($GLOBALS['PMSS_LOGMSG_DEFAULTS']) ? $GLOBALS['PMSS_LOGMSG_DEFAULTS'] : []);

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
require_once __DIR__.'/../lib/update/filesystem.php';
require_once __DIR__.'/../lib/update/opensslSsh2Compat.php';
require_once __DIR__.'/../lib/update/distro.php';
require_once __DIR__.'/../lib/update/kernelHardening.php';
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
    define('PMSS_UPDATE_LOCK_FILE', '/var/lib/pmss/update.lock');
}
if (!defined('PMSS_UPDATE_LOCK_ENV')) {
    define('PMSS_UPDATE_LOCK_ENV', 'PMSS_UPDATE_LOCK_HELD');
}
if (!defined('PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS')) {
    define('PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS', 30);
}
if (!defined('PMSS_UPDATE_LOCK_RETRY_SECONDS')) {
    define('PMSS_UPDATE_LOCK_RETRY_SECONDS', 2);
}

/**
 * Acquire the global phase-2 lock without allowing stale inherited FDs to hang.
 */
function pmssUpdateStep2AcquireUpdateLock(): void
{
    if (getenv(PMSS_UPDATE_LOCK_ENV) === '1') {
        return;
    }

    pmssLogJson(['event' => 'update_lock_wait', 'path' => PMSS_UPDATE_LOCK_FILE, 'max_wait_seconds' => PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS]);
    $deadline = microtime(true) + PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS;
    $attempt = 0;

    while (true) {
        $busy = false;
        $fh = pmssLockFileAcquire(PMSS_UPDATE_LOCK_FILE, true, 'c', true, true, $busy);
        if ($fh !== false) {
            $GLOBALS['PMSS_UPDATE_LOCK_HANDLE'] = $fh;
            putenv(PMSS_UPDATE_LOCK_ENV.'=1');
            pmssLockHandleExportChildCloseFds($fh);
            pmssLogJson(['event' => 'update_lock_acquired', 'path' => PMSS_UPDATE_LOCK_FILE]);
            return;
        }

        if (!$busy) {
            logmsg('Unable to open update lock file: '.PMSS_UPDATE_LOCK_FILE);
            exit(1);
        }

        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.0) {
            logmsg('[WARN] update-step2 lock busy after '.PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS.'s; skipping standalone phase-2 run');
            pmssLogJson([
                'event' => 'update_lock_busy_skip',
                'path' => PMSS_UPDATE_LOCK_FILE,
                'wait_seconds' => PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS,
            ]);
            exit(0);
        }

        $attempt++;
        $sleepSeconds = min(PMSS_UPDATE_LOCK_RETRY_SECONDS, max(1, (int) ceil($remaining)));
        pmssLogJson([
            'event' => 'update_lock_busy',
            'path' => PMSS_UPDATE_LOCK_FILE,
            'attempt' => $attempt,
            'retry_seconds' => $sleepSeconds,
        ]);
        sleep($sleepSeconds);
    }
}

/**
 * Switch legacy lighttpd instances to nginx and refresh configs.
 */
function pmssConfigureWebStack(): void
{
    pmssUpdateStep2MarkWebRefreshRequired();

    // Stop nginx first so package upgrades and template refreshes never race against an active daemon.
    runStep('Stopping nginx prior to configuration refresh', 'systemctl stop nginx || /etc/init.d/nginx stop || true');
    runStep('Stopping lighttpd (systemd)', '/etc/init.d/lighttpd stop');
    pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');
    killProcess('lighttpd', 'Terminating lingering lighttpd processes');
    killProcess('php-cgi', 'Terminating lingering php-cgi processes');
    pmssSystemdUnitActionIfPresent('nginx', 'Enabling nginx systemd service', 'enable');

    // Regenerate all per-user nginx configs from the freshly staged template
    // before the longer app/user phases. The final refresh later in this script
    // still restarts nginx after app installers finish.
    $nginxConfigRc = runStep('Regenerating nginx configs from staged templates', '/scripts/util/createNginxConfig.php');
    if ($nginxConfigRc !== 0) {
        throw new RuntimeException('nginx_config_regeneration_failed');
    }

    // Per-user lighttpd configuration, htpasswd sync, and instance checks
    // are handled inside the consolidated per-user maintenance loop.
    // nginx stays stopped until the final post-update refresh.
    runStep('Setting /home directory permissions', 'chmod 751 /home');
    // Quota state files reject chmod; prune them so the find commands stay noise-free.
    $prune = '\( -name "aquota.*" -o -name "lost+found" \)';
    foreach ([
        ['Hardening /home tenant directories', 'd', '0700', '700'],
        ['Hardening /home tenant files', 'f', '0600', '600'],
    ] as $hardeningStep) {
        runStep(
            $hardeningStep[0],
            sprintf('find /home -mindepth 1 -maxdepth 1 %s -prune -o -type %s -not -perm %s -exec chmod %s {} +', $prune, $hardeningStep[1], $hardeningStep[2], $hardeningStep[3])
        );
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
 * Return a stable abnormal-exit reason for shutdown rescue logs.
 */
function pmssUpdateStep2ShutdownReason(): string
{
    $error = error_get_last();
    return (is_array($error) && is_string($error['message'] ?? null) && $error['message'] !== '')
        ? $error['message']
        : 'early_exit';
}

/**
 * Emit a structured JSON marker for best-effort shutdown rescue steps.
 */
function pmssUpdateStep2LogRescueEvent(string $event, string $status, array $context = array()): void
{
    pmssLogJson(array('event' => $event, 'status' => $status) + $context);
}

/**
 * Attempt a best-effort nginx start when the final refresh could not finish.
 */
function pmssUpdateStep2StartNginxShutdownFallback(string $reason): void
{
    logmsg('[WARN] update-step2 exited with nginx still pending final refresh; attempting direct start (reason: '.$reason.')');
    pmssUpdateStep2LogRescueEvent('post_update_nginx_start_fallback', 'start', ['reason' => $reason]);

    $rc = 0;
    passthru(pmssLockChildClosePrefix().'systemctl start nginx 2>/dev/null || /etc/init.d/nginx start 2>/dev/null', $rc);

    pmssUpdateStep2LogRescueEvent('post_update_nginx_start_fallback', $rc === 0 ? 'ok' : 'error', ['rc' => $rc]);
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

        $reason = pmssUpdateStep2ShutdownReason();

        logmsg('[WARN] update-step2 exited before final nginx refresh; attempting rescue run (reason: '.$reason.')');
        pmssUpdateStep2LogRescueEvent('post_update_web_refresh_rescue', 'start', ['reason' => $reason]);

        $rc = 0;
        passthru(pmssLockChildClosePrefix().'/scripts/util/createNginxConfig.php --restart', $rc);

        pmssUpdateStep2LogRescueEvent('post_update_web_refresh_rescue', $rc === 0 ? 'ok' : 'error', ['rc' => $rc]);
        logmsg(sprintf('[WARN] Rescue nginx refresh completed with rc=%d', $rc));
        if ($rc === 0) {
            pmssUpdateStep2MarkWebRefreshCompleted();
            return;
        }

        pmssUpdateStep2StartNginxShutdownFallback('web_refresh_rescue_failed');
    });
}

/**
 * Restore tenant-visible config traversal if phase 2 exits before final perms.
 */
function pmssUpdateStep2RegisterPermissionShutdownGuard(): void
{
    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['PMSS_UPDATE_STEP2_COMPLETED']) || pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            return;
        }

        $helper = '/scripts/util/setupPermissions.php';
        if (!is_file($helper)) {
            logmsg('[WARN] update-step2 exited before final permission refresh; setupPermissions.php missing');
            return;
        }

        $reason = pmssUpdateStep2ShutdownReason();

        logmsg('[WARN] update-step2 exited before final permission refresh; attempting permission rescue run (reason: '.$reason.')');
        pmssUpdateStep2LogRescueEvent('permission_refresh_rescue', 'start', ['reason' => $reason]);

        $rc = runStep('Restoring system permissions (shutdown)', $helper);

        pmssUpdateStep2LogRescueEvent('permission_refresh_rescue', $rc === 0 ? 'ok' : 'error', ['rc' => $rc]);
    });
}

pmssUpdateStep2RegisterWebRefreshShutdownGuard();
pmssUpdateStep2RegisterPermissionShutdownGuard();

pmssRunProfiledCallable('Acquiring update-step2 lock', static function (): void {
    pmssUpdateStep2AcquireUpdateLock();
    register_shutdown_function(static function (): void {
        if (!isset($GLOBALS['PMSS_UPDATE_LOCK_HANDLE'])) {
            return;
        }
        pmssLockHandleRelease($GLOBALS['PMSS_UPDATE_LOCK_HANDLE']);
        unset($GLOBALS['PMSS_UPDATE_LOCK_HANDLE']);
        putenv(PMSS_UPDATE_LOCK_ENV);
        putenv(PMSS_UPDATE_LOCK_FDS_ENV);
        pmssLogJson(['event' => 'update_lock_released', 'path' => PMSS_UPDATE_LOCK_FILE]);
    });
});
pmssRunProfiledCallable('Running update-step2 preflight checks', static function (): void { if (!pmssUpdateStep2PreflightChecks('logmsg')) { exit(1); } });

foreach (array('DEBIAN_FRONTEND=noninteractive', 'APT_LISTCHANGES_FRONTEND=none', 'UCF_FORCE_CONFOLD=1', 'UCF_FORCE_CONFNEW=0', 'UCF_FORCE_CONFDEF=1', 'NEEDRESTART_MODE=a') as $envDefault) { putenv($envDefault); }

$GLOBALS['PMSS_PACKAGES_READY'] = false;
// PMSS_PACKAGE_PHASE exposes coarse progress while packages converge.
putenv('PMSS_PACKAGE_PHASE=initializing');

pmssRunProfiledCallable('Completing pending dpkg configurations', 'pmssCompletePendingDpkg');
pmssRunProfiledCallable('Checking /home inode density', 'pmssHomeInodeDensityCheck', ['logmsg'], PMSS_UPDATE_STEP_CLASS_SOFT_FAIL);

// Ensure the root cron template is restored even if the updater exits early.
// update.php disables `/etc/cron.d/pmss` only at the immediate phase-2 handoff;
// if phase 2 crashes, cron must come back before the process exits.
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
    $status = trim((string) @shell_exec(pmssLockChildClosePrefix().'dpkg-query -W -f=${Status} repo-mediaarea 2>/dev/null'));
    if ($status === '' || stripos($status, 'not-installed') !== false) {
        return;
    }

    runStep(
        'Removing legacy MediaArea bootstrap package (repo-mediaarea)',
        dpkgCmd('--remove --force-remove-reinstreq repo-mediaarea').' || true'
    );

    $setSelection = "printf '%s\\t%s\\n' 'repo-mediaarea' 'deinstall' | ".dpkgCmd('--set-selections');
    runStep('Marking repo-mediaarea for deinstallation', $setSelection);
});
pmssRunProfiledCallable('Pruning legacy MediaArea repository entries', 'pmssPruneLegacyMediaArea');

// Remove the legacy bespoke libssl3/openssh APT pin (commit fee5cc71, reverted in
// 2ac6386d). The openssh trio is now held in the dpkg selection baseline, which is
// the canonical control point; the standalone preferences file is superseded cruft.
// Idempotent (rm -f no-ops when absent); self-heals hosts that ran update.php during
// the fee5cc71 window. Refs #436/#585.
runStep('Removing legacy bespoke libssl3/openssh APT pin (superseded by dpkg selection hold)',
    'rm -f /etc/apt/preferences.d/pmss-libssl3-openssh.pref');

// --- PACKAGE PHASE: DO NOT REORDER ---------------------------------------------------------
// Early dpkg recovery lets wedged hosts converge before PHP or apt-adjacent
// probes run. The invariant order is fix-broken -> repository refresh -> dpkg
// baseline -> recovery/autoremove; package state stays owned by dpkg selections.
// -------------------------------------------------------------------------------------------

runStep('Attempting apt fix-broken install (pre-package phase)', aptCmd('--fix-broken install -y'));
pmssRunProfiledCallable('Refreshing package repositories', 'pmssRefreshRepositories', [$distroName, $effectiveRepoVersion, 'logmsg']);
$dpkgBaselineOk = pmssRunProfiledCallable('Applying distro dpkg baseline selections', 'pmssApplyDpkgSelections', [$effectiveRepoVersion > 0 ? $effectiveRepoVersion : null, true]);

// System-wide services must not run on seedbox hosts. Stop/disable early so
// package installs cannot leave attack surface exposed for the rest of the run.
pmssRunProfiledCallable('Applying system service disable/mask policy (pre-app)', 'pmssStopDisableMaskSeedboxSystemServices');
// Purge unbound DNS resolver if it is in failed state (external nameservers used)
pmssRunProfiledCallable('Purging failed unbound daemon if present', 'pmssPurgeFailedUnbound');
// Ensure the boot-time guard is installed/enabled so masked services cannot
// start during the next reboot even if systemd enablement drifts.
pmssRunProfiledCallable('Ensuring systemd service guard boot unit', 'pmssEnsureSystemdServicesGuardBootUnit');
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

// Re-apply libssl3 + openssl hold after dpkg baseline phase.
// Belt-2: selections-debian12 hold entries are belt-1. This call handles
// hosts already at broken versions and ensures convergence to exactly 3.0.17.
// Uses simulate-guarded downgrade logic with a dpkg-direct fallback path when
// apt predicts openssh removals. Refs #436.
$opensslCompatArgs = [$effectiveRepoVersion > 0 ? $effectiveRepoVersion : null];
pmssRunProfiledCallableBatch([
    ['Holding libssl3/openssl for PECL ssh2 compat (dpkg-direct guard)', 'pmssHoldLibssl3ForPeclSsh2Compat', $opensslCompatArgs],
    ['Healing openssh-server post-cascade if missing/deleted', 'pmssHealOpensshServerIfMissing', $opensslCompatArgs],
    ['Ensuring openssh-server is libssl3-3.0.17-compatible (canonical baseline downgrade)', 'pmssEnsureOpensshCompatibleWithHeldLibssl3', $opensslCompatArgs],
]);

// Package convergence: dpkg selections are the authoritative source of package
// state.
logmsg('[OK] Package phase relies on dpkg baseline selections only');

runStep('Attempting apt fix-broken install (post-package phase)', aptCmd('--fix-broken install -y'));
runStep('Removing packages no longer required', aptCmd('autoremove -y'));

$GLOBALS['PMSS_PACKAGES_READY'] = true;
putenv('PMSS_PACKAGE_PHASE=complete');
pmssLogJson(['event' => 'package_phase', 'status' => 'ok']);

pmssRunProfiledCallable('Migrating legacy localnet config path', 'pmssMigrateLegacyLocalnet');
pmssRunProfiledCallable('Applying runtime service templates', 'pmssApplyRuntimeTemplates', [], PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED);
pmssRunProfiledCallable('Applying journald runtime limits', 'pmssApplyJournaldLimits', ['logmsg']);
pmssRunProfiledCallable('Applying remote logging configuration', 'pmssApplyRemoteLogging', ['logmsg']);
pmssRunProfiledCallable('Applying hostname configuration', 'pmssApplyHostnameConfig', ['logmsg']);
pmssRunProfiledCallable('Configuring quota mounts', 'pmssConfigureQuotaMount', ['logmsg']);
runStep('Recalculating quota integrity', 'php /scripts/util/quotaFix.php');
pmssRunProfiledCallable('Applying boot defaults', 'pmssEnsureBootDefaults', ['logmsg']);
pmssRunProfiledCallable('Applying legacy sysctl baseline', 'pmssEnsureLegacySysctlBaseline', ['logmsg']);
pmssRunProfiledCallable('Applying kernel module hardening', 'pmssApplyKernelHardening', ['logmsg']);
pmssRunProfiledCallable('Stripping ssh-keysign SUID', 'pmssEnsureSshKeysignSuidStrip', ['logmsg']);
pmssRunProfiledCallable('Applying boot-time tuning', 'pmssEnsureBootTuning', ['logmsg']);
pmssRunProfiledCallable('Configuring root shell defaults', 'pmssConfigureRootShellDefaults', ['logmsg']);
runStep('Restricting world access to /home', 'chmod o-rw /home');

pmssRunProfiledCallable('Ensuring cgroup configuration', 'pmssEnsureCgroupsConfigured', ['logmsg']);
pmssRunProfiledCallable('Ensuring systemd slices', 'pmssEnsureSystemdSlices', ['logmsg']);
pmssRunProfiledCallable('Hardening systemd D-Bus cross-user disclosure', 'pmssEnsureSystemdDbusDisclosureHardening', ['logmsg']);
runStep('Resetting /etc/seedbox permissions', 'find /etc/seedbox -not -type l -not -perm 0755 -exec chmod 0755 {} +');
runStep('Resetting /scripts permissions', 'find /scripts -not -type l -not -perm 0750 -exec chmod 0750 {} +');
pmssRunProfiledCallable('Ensuring locale baseline', 'pmssEnsureLocaleBaseline');

// Web stack hardening and per-user HTTP refresh.
pmssRunProfiledCallable('Configuring web stack', 'pmssConfigureWebStack', [], PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED);

// Configure OpenVPN via dedicated utility for better logging/observability.
runStep('Configuring OpenVPN', 'php /scripts/util/configureOpenvpn.php');
runStep('Configuring WireGuard', 'php /scripts/util/wireguardConfigure.php');
runStep('Configuring netconsole', 'php /scripts/util/netconsoleConfigure.php');

// Load application installers automatically (sorted for deterministic order),
// but skip helper-only modules and account-scoped app maintenance that no
// longer belongs in the system-wide updater.
$apps = glob('/scripts/lib/update/apps/*.php') ?: [];
sort($apps);
foreach ($apps as $app) {
    $appBase = basename($app);
    if (in_array($appBase, [
        'arr.php',
        'openvpn.php',
        'pythonVenv.php',
        'remoteBinary.php',
        'servarr.php',
    ], true)) {
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
    pmssSystemdUnitActionIfPresent($legacySvc, "Disabling {$legacySvc} systemd unit", 'disable');
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
pmssUpdateStep2HandleUserMaintenanceSummary($userMaintenanceSummary);
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

pmssLogJson(['event' => 'phase', 'name' => 'setupPermissions', 'status' => 'start']);
$setupPermissionsRc = runStep('Refreshing system permissions', '/scripts/util/setupPermissions.php');
pmssLogJson(['event' => 'phase', 'name' => 'setupPermissions', 'status' => 'end', 'rc' => $setupPermissionsRc]);
if ($setupPermissionsRc !== 0) {
    pmssUpdateStep2HandleClassifiedFailure(
        'Refreshing system permissions',
        PMSS_UPDATE_STEP_CLASS_SOFT_FAIL,
        $setupPermissionsRc,
        'setupPermissions_exit'
    );
}
pmssLogJson(['event' => 'phase', 'name' => 'transition', 'status' => 'leaving setupPermissions', 'rc' => $setupPermissionsRc]);
runStep('Refreshing FTP configuration', '/scripts/util/ftpConfig.php');

$logrotateTemplate = '/etc/seedbox/config/template.logrotate.pmss';
if (file_exists($logrotateTemplate)) {
    $logrotateTarget = '/etc/logrotate.d/pmss-update';
    $logrotateInstallRc = runStep(
        'Installing logrotate policy for PMSS update logs',
        sprintf('install -m 0644 -T %s %s', escapeshellarg($logrotateTemplate), escapeshellarg($logrotateTarget))
    );
    $logrotateVerifyRc = runStep(
        'Verifying PMSS logrotate policy matches template',
        sprintf('cmp -s %s %s', escapeshellarg($logrotateTemplate), escapeshellarg($logrotateTarget))
    );
    if ($logrotateInstallRc !== 0 || $logrotateVerifyRc !== 0) {
        pmssUpdateStep2HandleClassifiedFailure(
            'Installing logrotate policy for PMSS update logs',
            PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED,
            $logrotateInstallRc !== 0 ? $logrotateInstallRc : $logrotateVerifyRc,
            'logrotate_policy_install_or_verify_failed'
        );
    }
}

pmssRunProfiledCallable('Ensuring network template baseline', 'pmssEnsureNetworkTemplate', ['logmsg']);
runStep('Reapplying network configuration', '/scripts/util/setupNetwork.php');
runStep('Hardening access to session and network binaries', 'chmod o-r /var/log/wtmp /var/run/utmp /var/log/lastlog /var/log/faillog /usr/bin/netstat /usr/bin/who /usr/bin/w');

// Cleanup legacy runtime metadata that should never have shipped with snapshots.
if (is_dir('/etc/seedbox/config/app-versions')) { runStep('Removing legacy app version records', 'rm -rf '.escapeshellarg('/etc/seedbox/config/app-versions')); }

// Restore root cron at the very end. update.php only disables it for the
// phase-2 handoff window; we want it back for normal operations.
pmssRunProfiledCallable('Ensuring cron service is active before root cron restore', 'pmssEnsureCronServiceActive', ['update-step2 root cron restore']);
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
