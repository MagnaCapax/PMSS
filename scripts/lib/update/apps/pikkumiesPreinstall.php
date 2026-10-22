<?php
/**
 * Preinstall Pikkumies in existing homes (ADR 0090). New accounts get it at creation; this reaches the
 * accounts made before that. Strictly inert unless the host marker exists, so the rollout goes host by
 * host. An account is marked once its install succeeds: the state file stops a retry after the user removed Pikkumies.
 * The installs run one after another in the background, each as its user in the user's slice, and
 * build no agent runtime (that follows at each account's own daily update time).
 */

/** Never let the optional preinstall abort update-step2's uncaught include. */
function pmssPikkumiesPreinstallExistingRun(
    string $marker = '/etc/seedbox/config/pikkumiesPreinstall.enabled',
    string $stateDir = '/var/lib/pmss/pikkumies-preinstall',
    array $ops = []
): void {
    if (!is_file($marker)) return;
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
            $home = $homeBase.'/'.$user;
            // Null for an invalid name, an existing marker or a link (a dangling link must not become the target of
            // the later root-owned touch).
            $marker = pmssPikkumiesPreinstallStateMarkerPath($user, $stateDir);
            if ($marker === null) continue;
            if (is_dir($home.'/.mcxPikkumies')) {
                touch($marker);
                continue;
            }
            // Below the RAM floor or no installer yet: not marked, so a later update can try again.
            $launch = pmssPikkumiesPreinstallLaunch(['name' => $user, 'memory' => $ramMiB($user)], $home);
            if ($launch === null) continue;
            // Marked only when the install exits 0, so a failed download is tried again at a later update.
            $launches[] = '( '.$launch.' ) && touch -- '.escapeshellarg($marker);
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
