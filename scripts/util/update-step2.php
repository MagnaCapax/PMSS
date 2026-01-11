#!/usr/bin/env php
<?php
/**
 * PMSS Update Script (dynamic portion)
 *
 * Handles the heavy lifting of system updates once the static updater
 * (/scripts/update.php) refreshes itself. Tasks include repository setup,
 * service configuration, user environment maintenance and security tweaks.
 *
 * #TODO profiling
 * In a future refactor, launch this sequence from a thinner orchestrator and
 * ensure EVERY unit of work is wrapped in profiling/structured logging. That
 * means no bare function calls or shell execs without runStep/pmssRecordProfile
 * (or a wrapper), so the JSON/profile output gives a complete breakdown.
 *
 * Package phase invariant: repository templating, dpkg baseline replay, and
 * queued package installs must succeed before any other module executes. Do
 * not insert additional orchestration ahead of the package phase.
 *
 * This file is refreshed from GitHub by /scripts/update.php prior to each run.
 * Keep local changes minimal or contribute them upstream.
 */

// Cap PHP memory use so we fail fast with a PHP fatal instead of a host-wide OOM kill.
@ini_set('memory_limit', '4096M');

// Module load order mirrors the runtime sequence. Keep shared runtime helpers
// first, followed by environment detection, repository setup, system prep, web
// stack, service bundles, user refresh, networking, and finally bootstrap
// helpers. When adding a new orchestrator, pick the insertion point carefully
// and document its failure behaviour so future maintainers know whether errors
// should halt the run or log-and-continue.
require_once __DIR__.'/../lib/update.php';
require_once __DIR__.'/../lib/update/runtime/profile.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/update/runtime/processes.php';
require_once __DIR__.'/../lib/update/environment.php';
require_once __DIR__.'/../lib/update/distro.php';
require_once __DIR__.'/../lib/update/repositories.php';
require_once __DIR__.'/../lib/update/systemPrep.php';
require_once __DIR__.'/../lib/update/webStack.php';
require_once __DIR__.'/../lib/update/services/runtime.php';
require_once __DIR__.'/../lib/update/services/legacy.php';
require_once __DIR__.'/../lib/update/services/systemd.php';
require_once __DIR__.'/../lib/update/services/mediainfo.php';
require_once __DIR__.'/../lib/update/services/certificates.php';
require_once __DIR__.'/../lib/update/services/security.php';
require_once __DIR__.'/../lib/update/userMaintenance.php';
require_once __DIR__.'/../lib/update/networking.php';
require_once __DIR__.'/../lib/update/services/bootstrap.php';
require_once __DIR__.'/../lib/motd/Generator.php';

requireRoot();

// Preflight: ensure root can keep forking during long updates even if legacy TasksMax caps are present.
// Safe before the package phase; avoids "Cannot fork" cascades inside user-0.slice.
runStep('Ensuring root user slice TasksMax is unlimited (preflight)', "systemctl set-property --runtime 'user-0.slice' MemoryHigh=infinity MemoryMax=infinity TasksMax=infinity");

$distribution  = pmssDetectDistro();
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

// logmsg is defined in /scripts/update.php when this file is loaded from there.
// Provide a very small fallback so running this script standalone won't fatal.
if (!function_exists('logmsg')) {
    /**
     * Minimal logger used when update-step2 runs outside update.php.
     */
    function logmsg(string $message): void
    {
        $timestamp = date('[Y-m-d H:i:s] ');
        @file_put_contents('/var/log/pmss-update.log', $timestamp.$message.PHP_EOL, FILE_APPEND | LOCK_EX) || @file_put_contents('/tmp/pmss-update.log', $timestamp.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
        fwrite(STDERR, $message.PHP_EOL);
    }
}

$GLOBALS['PMSS_PACKAGES_READY'] = false;
// PMSS_PACKAGE_PHASE advertises coarse progress (`initializing`/`complete`); unknown values mean "in progress".
// Flip it after logging the matching step so external monitors keep ordering intact.
putenv('PMSS_PACKAGE_PHASE=initializing');

$effectiveRepoVersion = $repoVersion > 0 ? $repoVersion : $reportedVersion;

logmsg('update-step2.php starting');
pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'start']);

pmssConfigureAptNonInteractive('logmsg');
pmssCleanupMediaareaBootstrapPackage();
pmssPruneLegacyMediaArea();

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
pmssRefreshRepositories($distroName, $effectiveRepoVersion, 'logmsg');
pmssCompletePendingDpkg();
$dpkgBaselineOk = pmssApplyDpkgSelections($effectiveRepoVersion > 0 ? $effectiveRepoVersion : null, true);

// System-wide services must not run on seedbox hosts. Stop/disable early so
// package installs cannot leave attack surface exposed for the rest of the run.
pmssStopDisableMaskSeedboxSystemServices();
// Ensure the boot-time guard is installed/enabled so masked services cannot
// start during the next reboot even if systemd enablement drifts.
pmssEnsureSystemdServicesGuardBootUnit();
// Legacy hosts occasionally re-enable Apache during package recovery; perform
// the stop/disable/mask sequence twice so hosts drifting between bullseye and
// bookworm converge reliably. Success = units masked and no apache2 processes
// left running. Failure is tolerated but logged via runStep.
pmssStopDisableMaskApacheLegacy();
// Remove legacy Apache packages; keep apache2-utils. It provides htpasswd (used by
// lighttpd basic auth) and ab; removing it breaks auth setup and other scripts.
runStep('Removing residual Apache packages', aptCmd('purge -y apache2 apache2-bin apache2-data libapache2-mod-php7.4 || true'));
pmssStopDisableMaskApacheLegacy();
if ($repoLogMessage !== '') { logmsg($repoLogMessage); }
if (!$dpkgBaselineOk) {
    logmsg('[WARN] Dpkg baseline application reported issues; attempting recovery');
    runStep('Attempting apt fix-broken install (dpkg baseline recovery)', aptCmd('--fix-broken install -y'));
    $dpkgBaselineOk = pmssApplyDpkgSelections($effectiveRepoVersion > 0 ? $effectiveRepoVersion : null, true);
    if (!$dpkgBaselineOk) {
        logmsg('[ERROR] Dpkg baseline still failing after recovery attempt; continuing with caution');
        pmssLogJson(['event' => 'package_phase', 'status' => 'warn', 'reason' => 'dpkg_baseline']);
    }
}

// #TODO Finish migration: once dpkg baselines cover all apps on all hosts,
//       replace queued installs with a diff-summary report and remove the
//       per-app package queue entirely.
include_once '/scripts/lib/update/apps/packages.php';
pmssFlushPackageQueue();

$packageWarnings = (int) (getenv('PMSS_PACKAGE_INSTALL_WARNINGS') ?: 0);
$packageErrors   = (int) (getenv('PMSS_PACKAGE_INSTALL_ERRORS') ?: 0);

if ($packageWarnings > 0) { logmsg(sprintf('[WARN] Package phase completed with %d warning(s); see earlier log entries for details', $packageWarnings)); }
if ($packageErrors > 0) { logmsg(sprintf('[ERROR] Package phase could not install %d item(s); continuing with caution', $packageErrors)); pmssLogJson(['event' => 'package_phase', 'status' => 'warn', 'reason' => 'queue_failures', 'count' => $packageErrors]); }

runStep('Attempting apt fix-broken install (post-package phase)', aptCmd('--fix-broken install -y'));
runStep('Removing packages no longer required', aptCmd('autoremove -y'));

$GLOBALS['PMSS_PACKAGES_READY'] = true;
putenv('PMSS_PACKAGE_PHASE=complete');
pmssLogJson(['event' => 'package_phase', 'status' => 'ok']);

pmssMigrateLegacyLocalnet();
pmssApplyRuntimeTemplates();
pmssApplyHostnameConfig('logmsg');
pmssConfigureQuotaMount('logmsg');
pmssEnsureLegacySysctlBaseline('logmsg');
pmssConfigureRootShellDefaults('logmsg');
pmssProtectHomePermissions();

// --- Basic system preparation ---
pmssEnsureCgroupsConfigured('logmsg');
pmssEnsureSystemdSlices('logmsg');
pmssResetCorePermissions();
pmssEnsureLocaleBaseline();

// Web stack hardening and per-user HTTP refresh.
pmssConfigureWebStack($distroVersion);
pmssReapplyLocaleDefinitions();

// Configure OpenVPN via dedicated utility for better logging/observability.
runStep('Configuring OpenVPN', 'php /scripts/util/configureOpenvpn.php');
runStep('Configuring WireGuard', 'php /scripts/util/wireguardConfigure.php');

// Load application installers automatically (sorted for deterministic order),
// but skip the legacy OpenVPN app script as it is superseded by the utility.
$apps = glob('/scripts/lib/update/apps/*.php') ?: [];
sort($apps);
foreach ($apps as $app) {
    if (basename($app) === 'openvpn.php') {
        continue;
    }
    include_once $app;
}

// Reapply system service disablement after app installers in case any package
// postinst scripts (re)started daemons mid-update.
pmssStopDisableMaskSeedboxSystemServices();

pmssEnsureLetsEncryptConfig();
pmssRemoveAutodlConfig();

// Legacy daemons that should never run globally.
$legacyServices = ['btsync', 'rslsync', 'pyload', 'sabnzbdplus'];
pmssDisableLegacyServices($legacyServices, $distroVersion);
pmssAdjustLighttpdSecurity();

// Per-user updates ensure ruTorrent stays consistent. The SHA tracks the
// skeleton ruTorrent index version so user instances can be upgraded when the
// template changes.
$rutorrentIndexSha = sha1((string) @file_get_contents('/etc/skel/www/rutorrent/index.html'));
pmssUpdateAllUsers($rutorrentIndexSha);
// #TODO(user-logs): per-user environment updates could append summary lines to /var/log/pmss/user-<username>.log

pmssEnsureAuthorizedKeysDirective();
pmssEnsureTestfile();
pmssRestrictAtopBinary();

pmssPostUpdateWebRefresh();
pmssRefreshSkeletonAndCron();
pmssInstallLogrotatePolicy();
pmssRestoreUserCrontabs();
// #TODO(per-user-loop): migrate the global web stack refresh/cron/authorized
// keys tasks above into the single per-user orchestrator so we do not run
// separate all-user sweeps.

pmssEnsureNetworkTemplate('logmsg');
pmssApplyNetworkConfig();
pmssApplySecurityHardening();

// Cleanup legacy runtime metadata that should never have shipped with snapshots.
if (is_dir('/etc/seedbox/config/app-versions')) { runStep('Removing legacy app version records', 'rm -rf '.escapeshellarg('/etc/seedbox/config/app-versions')); }

// Mark the end of phase 2 so log parsing knows we finished cleanly.
// Refresh MOTD at the very end so VPN/service status reflects final state.
// Consolidated on the Motd class generator for determinism
Motd::motdGenerate();
pmssProfileSummary();
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

// Record successful completion for MOTD/monitoring
@file_put_contents('/var/run/pmss/updated', date('Y-m-d H:i:s'));
@chmod('/var/run/pmss/updated', 0644);

pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'end']);
logmsg('update-step2.php completed');
logmsg('Completed at: '.date('Y-m-d H:i:s'));
