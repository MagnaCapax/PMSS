<?php
/** Weekly discard policy; loaded by services/systemd.php. @license GPL-3.0-only */
/**
 * Start the first TRIM inside the batched update so hosts do not all issue their
 * first large discard in the same weekly window. This is hygiene, not a performance claim.
 */
function pmssEnsureFstrimTimer(string $context = 'update'): void
{
    if (pmssSystemdActionSkip(pmssSystemdActionSkipReason('fstrim.timer'), 'Ensuring weekly TRIM timer', false)) return;
    pmssEnsureFstrimTimerForState(pmssSystemdUnitState('is-enabled', 'fstrim.timer'), $context);
}

/** Apply the timer state decision after the systemd probe; kept separate for hermetic tests. */
function pmssEnsureFstrimTimerForState(?string $state, string $context): void
{
    if ($state === null || $state === 'enabled') return;
    if (in_array($state, ['', 'not-found', 'masked'], true)) {
        logmsg('[INFO] fstrim.timer '.($state === 'masked'
            ? 'is masked by the operator; left as is' : 'is not shipped on this release; weekly TRIM skipped'));
        return;
    }

    runStep('Enabling weekly TRIM timer ('.$context.')', 'systemctl enable --now fstrim.timer || true');
    runStep('Starting first TRIM run in the background ('.$context.')', 'systemctl start --no-block fstrim.service || true');
}
