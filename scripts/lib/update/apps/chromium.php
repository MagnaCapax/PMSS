<?php
/**
 * Update app installer: Debian Chromium runtime for account-level agents, Debian 12/13 only (ADR 0123).
 * Not in the dpkg selection baselines, which apply on every host regardless of free space.
 * Never pulls chromium-sandbox. Fail-soft: nothing here may abort update-step2's include.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../packageState.php';
require_once __DIR__.'/../distro.php';

// Measured worst case on top of the Debian 12/13 baselines is about 0.45 GiB installed plus
// 0.13 GiB of downloaded archives; 3 GiB before the install leaves more than 2 GiB afterwards.
const PMSS_CHROMIUM_MIN_FREE_BYTES = 3 * 1073741824;

/** Packages the runtime needs on a Debian major; empty when the release is not supported. */
function pmssChromiumRuntimePackages(int $debianMajor): array
{
    if ($debianMajor === 12) return ['chromium']; // the Debian 12 baseline already has slirp4netns
    if ($debianMajor === 13) return ['chromium', 'slirp4netns'];
    return [];
}

/** Install whatever part of the runtime is missing; every skip is one log line, every failure soft. */
function pmssChromiumRuntimeEnsure(array $ops = []): void
{
    $log = $ops['log'] ?? 'logmsg';
    try {
        $packages = pmssChromiumRuntimePackages((int) ($ops['major'] ?? pmssDistroVersionFromEnv()));
        if ($packages === []) return;
        if (!($ops['packagesReady'] ?? !empty($GLOBALS['PMSS_PACKAGES_READY']))) {
            $log('[SKIP] chromium runtime: package phase not complete');
            return;
        }
        $status = $ops['status'] ?? 'pmssPackageStatus';
        $missing = [];
        foreach ($packages as $package) {
            $state = trim((string) $status($package));
            if ($state === 'install ok installed') continue;
            // An administrator's remove, purge or hold is an opt-out; do not fight it every update.
            $want = (string) strtok($state, ' ');
            if (in_array($want, ['deinstall', 'purge', 'hold'], true)) {
                $log("[SKIP] chromium runtime: {$package} is selected '{$want}' on this host");
                return;
            }
            $missing[] = $package;
        }
        if ($missing === []) return;
        $free = $ops['freeBytes'] ?? static function (string $path): ?float {
            $bytes = @disk_free_space($path);
            return $bytes === false ? null : (float) $bytes;
        };
        // /usr takes the bulk, /var the archives; both are usually on /, then this measures / thrice.
        foreach (['/', '/usr', '/var'] as $path) {
            $bytes = $free($path);
            if ($bytes !== null && $bytes >= PMSS_CHROMIUM_MIN_FREE_BYTES) continue;
            $shown = $bytes === null ? 'unknown' : sprintf('%.2f GiB', $bytes / 1073741824);
            $log("[SKIP] chromium runtime: {$path} free space {$shown}, 3 GiB required");
            return;
        }
        $run = $ops['run'] ?? 'runStep';
        // Recommends stay off so the setuid chromium-sandbox helper never comes along.
        $rc = (int) $run('[chromium] Installing browser runtime ('.implode(', ', $missing).')',
            aptCmd('install -y --no-install-recommends '.pmssCommandArgvShellQuote($missing)));
        if ($rc !== 0) $log("[WARN] chromium runtime install failed (rc={$rc}); a later update retries");
    } catch (Throwable $error) {
        $log('[WARN] chromium runtime skipped: '.$error->getMessage());
    }
}

// The development runner loads the module without host effects.
if (!defined('PMSS_TEST_MODE') || !PMSS_TEST_MODE) pmssChromiumRuntimeEnsure();
