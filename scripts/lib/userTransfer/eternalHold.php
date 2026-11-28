<?php
/** Source/target account holds keep Eternal state cold during the final pass. */

function pmssUserTransferTargetEternalCommand(string $user, ?int $until): string
{
    $binary = '"$HOME/.mcxPikkumies/pikkumies/bin/pikkumies"';
    $marker = '"$HOME/.mcxPikkumies/pikkumies/local/eternals.hold"';
    $accountCommand = $until === null
        ? 'rm -f -- '.$marker
        : 'if [ -x '.$binary.' ] && [ -f /etc/seedbox/config/pikkumiesEternals.enabled ]; then '
            .$binary.' eternal hold --until '.(string) $until.'; fi';
    return pmssBuildUserShellNoPamCommand($user, $accountCommand);
}

/** Retry a partial target stop, but never allow final copying without a successful hold. */
function pmssUserTransferTargetEternalHold(string $user, int $until): int
{
    $command = pmssUserTransferTargetEternalCommand($user, $until);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $rc = runStep(sprintf('Holding target Eternals (attempt %d/3)', $attempt), $command);
        if ($rc === 0) {
            return 0;
        }

        // Capture state before retrying; a failed or hung listing must not mask the hold result.
        $listRc = runStep('Inspecting target Eternals after failed hold', pmssBuildUserShellNoPamCommand($user,
            '/usr/bin/timeout --kill-after=2s 5s "$HOME/.mcxPikkumies/pikkumies/bin/pikkumies" eternal list'));
        $output = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? [];
        $snapshot = trim(pmssLogWhitespaceCollapse(substr(
            ($output['stdout'] ?? '')."\n".($output['stderr'] ?? ''), 0, 8192
        )));
        logMessage(sprintf('[WARN] Target Eternals hold attempt %d/3 failed (rc=%d, list rc=%d): %s',
            $attempt, $rc, $listRc, $snapshot === '' ? '(no output)' : $snapshot));
    }
    return $rc;
}

/** Choose one active side after transfer; a failed copy must leave the target cold. */
function pmssUserTransferEternalCleanupPlan(bool $complete, bool $sourceHeld, bool $targetHeld): array
{
    return [
        'releaseSource' => $sourceHeld && !$complete,
        'holdTarget' => $targetHeld && !$complete,
        'releaseTarget' => $targetHeld && $complete,
        'ensureTarget' => $targetHeld && $complete,
    ];
}
