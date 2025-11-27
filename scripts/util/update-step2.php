#!/usr/bin/php
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
require_once __DIR__.'/../lib/update/services/mediainfo.php';
require_once __DIR__.'/../lib/update/services/certificates.php';
require_once __DIR__.'/../lib/update/services/security.php';
require_once __DIR__.'/../lib/update/userMaintenance.php';
require_once __DIR__.'/../lib/update/networking.php';
require_once __DIR__.'/../lib/update/services/bootstrap.php';
require_once __DIR__.'/../lib/motd/Generator.php';

requireRoot();

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
        $primary   = '/var/log/pmss-update.log';
        $fallback  = '/tmp/pmss-update.log';

        @file_put_contents($primary, $timestamp.$message.PHP_EOL, FILE_APPEND | LOCK_EX)
     || @file_put_contents($fallback, $timestamp.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
        fwrite(STDERR, $message.PHP_EOL);
    }
}

$GLOBALS['PMSS_PACKAGES_READY'] = false;
// PMSS_PACKAGE_PHASE advertises coarse progress to sub-processes. Current
// values: `initializing` (pre-package work) and `complete` (package queues and
// baselines finished). Future phases may slot in between; consumers should
// treat unknown values as "in progress". Always flip the flag after logging the
// matching step so external monitors keep ordering intact.
putenv('PMSS_PACKAGE_PHASE=initializing');

$effectiveRepoVersion = $repoVersion > 0 ? $repoVersion : $reportedVersion;

logmsg('update-step2.php starting');
pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'start']);

pmssConfigureAptNonInteractive('logmsg');

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
$repoRefreshed = pmssRefreshRepositories($distroName, $effectiveRepoVersion, 'logmsg');
pmssCompletePendingDpkg();
$dpkgBaselineOk = pmssApplyDpkgSelections($effectiveRepoVersion > 0 ? $effectiveRepoVersion : null, $repoRefreshed);
// Legacy hosts occasionally re-enable Apache during package recovery; perform
// the stop/disable/mask sequence twice so hosts drifting between bullseye and
// bookworm converge reliably. Success = units masked and no apache2 processes
// left running. Failure is tolerated but logged via runStep.
runStep('Stopping Apache httpd (legacy)', 'systemctl stop apache2 || true');
runStep('Disabling Apache httpd service', 'systemctl disable apache2 || true');
runStep('Masking Apache httpd service', 'systemctl mask apache2 || true');
// Remove legacy Apache packages; keep apache2-utils. It provides htpasswd (used by
// lighttpd basic auth) and ab; removing it breaks auth setup and other scripts.
runStep('Removing residual Apache packages', aptCmd('purge -y apache2 apache2-bin apache2-data libapache2-mod-php7.4 || true'));
runStep('Stopping Apache httpd (legacy)', 'systemctl stop apache2 || true');
runStep('Disabling Apache httpd service', 'systemctl disable apache2 || true');
runStep('Masking Apache httpd service', 'systemctl mask apache2 || true');
if ($repoLogMessage !== '') {
    logmsg($repoLogMessage);
}
if (!$dpkgBaselineOk) {
    logmsg('[WARN] Dpkg baseline application reported issues; attempting recovery');
    runStep('Attempting apt fix-broken install (dpkg baseline recovery)', aptCmd('--fix-broken install -y'));
    $dpkgBaselineOk = pmssApplyDpkgSelections($effectiveRepoVersion > 0 ? $effectiveRepoVersion : null, true);
    if (!$dpkgBaselineOk) {
        logmsg('[ERROR] Dpkg baseline still failing after recovery attempt; continuing with caution');
        pmssLogJson(['event' => 'package_phase', 'status' => 'warn', 'reason' => 'dpkg_baseline']);
    }
}

require_once __DIR__.'/../lib/update/users.php';

// #TODO Finish migration: once dpkg baselines cover all apps on all hosts,
//       replace queued installs with a diff-summary report and remove the
//       per-app package queue entirely.
include_once '/scripts/lib/update/apps/packages.php';
pmssFlushPackageQueue();

$packageWarnings = (int) (getenv('PMSS_PACKAGE_INSTALL_WARNINGS') ?: 0);
$packageErrors   = (int) (getenv('PMSS_PACKAGE_INSTALL_ERRORS') ?: 0);

if ($packageWarnings > 0) {
    logmsg(sprintf('[WARN] Package phase completed with %d warning(s); see earlier log entries for details', $packageWarnings));
}
if ($packageErrors > 0) {
    logmsg(sprintf('[ERROR] Package phase could not install %d item(s); continuing with caution', $packageErrors));
    pmssLogJson(['event' => 'package_phase', 'status' => 'warn', 'reason' => 'queue_failures', 'count' => $packageErrors]);
}

runStep('Attempting apt fix-broken install (post-package phase)', aptCmd('--fix-broken install -y'));
pmssAutoremovePackages();

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

pmssEnsureLetsEncryptConfig();
pmssRemoveAutodlConfig();

// Legacy daemons that should never run globally.
$legacyServices = ['btsync', 'rslsync', 'pyload', 'sabnzbdplus', 'lighttpd'];
pmssDisableLegacyServices($legacyServices, $distroVersion);
pmssAdjustLighttpdSecurity();

// Per-user updates ensure ruTorrent stays consistent. The SHA tracks the
// skeleton ruTorrent index version so user instances can be upgraded when the
// template changes.
$rutorrentIndexSha = sha1((string) @file_get_contents('/etc/skel/www/rutorrent/index.html'));
pmssUpdateAllUsers($rutorrentIndexSha);
// #TODO(user-logs): per-user environment updates could append summary lines to /var/log/pmss/user-<username>.log
// Ensure user linger/systemd and rootless Docker are enabled and running; logs go to per-user files.
if (function_exists('pmssEnsureLingerAndDockerAllUsers')) {
    pmssEnsureLingerAndDockerAllUsers();
}

pmssEnsureAuthorizedKeysDirective();
pmssEnsureTestfile();
pmssRestrictAtopBinary();

pmssPostUpdateWebRefresh();
pmssRefreshSkeletonAndCron();
pmssInstallLogrotatePolicy();
pmssRestoreUserCrontabs();

pmssEnsureNetworkTemplate('logmsg');
pmssApplyNetworkConfig();
pmssApplySecurityHardening();

// Cleanup legacy runtime metadata that should never have shipped with snapshots.
$legacyAppVersionDir = '/etc/seedbox/config/app-versions';
if (is_dir($legacyAppVersionDir)) {
    runStep('Removing legacy app version records', 'rm -rf '.escapeshellarg($legacyAppVersionDir));
}

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
    if ($jsonLog !== '') {
        logmsg('  - JSON:   '.$jsonLog);
    }
    if ($profileOut !== '') {
        logmsg('  - Profile: '.$profileOut);
    }
} catch (\Throwable $e) {
        // Fail-soft: logging paths are best-effort only.
    }
    
    // Record successful completion for MOTD/monitoring
    @file_put_contents('/var/run/pmss/updated', date('Y-m-d H:i:s'));
    @chmod('/var/run/pmss/updated', 0644);
    
    pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'end']);
    logmsg('update-step2.php completed');
logmsg('Completed at: '.date('Y-m-d H:i:s'));
