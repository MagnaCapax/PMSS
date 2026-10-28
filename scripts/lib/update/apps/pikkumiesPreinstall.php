<?php
/**
 * Preinstall Pikkumies in existing homes (ADR 0090). New accounts get it at creation; this reaches the
 * accounts made before that. A new clone needs the host marker, so the rollout goes host by host; an install
 * that stopped after its clone is finished on every host. An account is marked once its install succeeds: the
 * state file stops a retry after the user removed Pikkumies.
 * The installs run one after another in the background, each as its user in the user's slice, and
 * build no agent runtime (that follows at each account's own daily update time).
 */

/** Never let the optional preinstall abort update-step2's uncaught include. */
function pmssPikkumiesPreinstallExistingRun(
    string $marker = '/etc/seedbox/config/pikkumiesPreinstall.enabled',
    string $stateDir = '/var/lib/pmss/pikkumies-preinstall',
    array $ops = []
): void {
    $hostMarked = is_file($marker);
    try {
        require_once dirname(__DIR__, 2).'/user/selection.php';
        require_once dirname(__DIR__, 2).'/user/add/postProvision.php';
        require_once dirname(__DIR__, 2).'/user/pikkumiesPreinstallState.php';
        $users = $ops['users'] ?? static function (): array { return pmssListManagedUsers('/scripts/listUsers.php'); };
        $ramMiB = $ops['ramMiB'] ?? static function (string $user): int {
            $payload = pmssUserConfigResolvePayload($user);
            return (int) ($payload['ramMiB'] ?? 0);
        };
        $run = $ops['run'] ?? static function (string $command): void { exec($command); };
        $homeBase = $ops['homeBase'] ?? '/home';
        // Reject redirected ancestors before mkdir or any marker work, not just a linked leaf.
        if (is_link($stateDir) || !pmssPathTargetIsSafe($stateDir, true)
            || (!is_dir($stateDir) && !mkdir($stateDir, 0700, true) && !is_dir($stateDir))) {
            error_log('[WARN] pikkumies preinstall: cannot create '.$stateDir);
            return;
        }
        $launches = [];
        foreach ($users() as $user) {
            $user = (string) $user;
            if (!pmssValidateUsername($user)) continue;
            $home = $homeBase.'/'.$user;
            // Null for an existing marker or a link (a dangling link must not become the target of the later root-owned touch).
            $marker = pmssPikkumiesPreinstallStateMarkerPath($user, $stateDir);
            $clone = !is_link($home.'/.mcxPikkumies') && is_dir($home.'/.mcxPikkumies');
            $config = $home.'/.mcxPikkumies/pikkumies/local/hermes-managed/config.yaml';
            // Every finished install and every session start writes the runtime config. A link, or a parent resolving
            // outside the home, counts as no config, so root's answer never depends on where an account's link points.
            if ($clone && !is_link($config) && pmssPathWithinResolvedRoot($config, $home) && is_file($config)) {
                if ($marker !== null) touch($marker);
                continue;
            }
            if ($clone) {
                // A clone without it is an install that stopped (new accounts preinstalled before their key arrived,
                // 2026-10-25..28) or one that finished before its key: run it again on every host until it succeeds
                // once, also where an earlier hook or a new account left an empty state file. This run writes
                // "finished" into the state file, so it never repeats (a user who turned auto-update off keeps that).
                $statePath = $stateDir.'/'.$user;
                if (is_link($statePath) || (file_exists($statePath) && (!is_file($statePath) || filesize($statePath) > 0))) continue;
                $after = ' && printf finished > '.escapeshellarg($statePath);
            } else {
                // Without a clone: rollout hosts only, once.
                if (!$hostMarked || $marker === null) continue;
                $after = ' && touch -- '.escapeshellarg($marker);
            }
            // Below the RAM floor or no installer yet: not marked, so a later update can try again.
            $launch = pmssPikkumiesPreinstallLaunch(['name' => $user, 'memory' => $ramMiB($user)], $home, $clone);
            if ($launch === null) continue;
            // Marked only when the command exits 0, so a failed download is tried again at a later update.
            $launches[] = '( '.$launch.' )'.$after;
        }
        if ($launches === []) return;
        $run('nohup sh -c '.escapeshellarg(implode('; ', $launches)).' >/dev/null 2>&1 &');
        error_log('[INFO] pikkumies preinstall: started for '.count($launches).' account(s)');
    } catch (Throwable $error) {
        error_log('[WARN] pikkumies preinstall skipped: '.$error->getMessage());
    }
}

// The development runner can load and exercise the hook without host effects.
if (!defined('PMSS_TEST_MODE') || !PMSS_TEST_MODE) pmssPikkumiesPreinstallExistingRun();
