<?php
/** Publish reconfiguration display copies and optional traffic/IOPS limits. @license GPL-3.0-only */

/** Preserve fail-soft writes before heavyweight service configuration. */
function pmssUserConfigLimitsApply(array $user, string $expectedHome): void
{
    // Match creation's display-only writer after the authoritative store succeeds.
    if (!pmssAddUserBonusQuotaPersist($user, $expectedHome)) {
        fwrite(STDERR, "Warning: failed to persist bonus quota display copy for {$user['name']}\n");
    }
    // Write optional traffic caps before touching heavyweight services so limits
    // persist even if later steps bail out.
    userApplyTrafficLimit($user);
    if (array_key_exists('iopsLimit', $user) && $user['iopsLimit'] !== null) {
        $targetModes = pmssIopsLimitTargetModes($user['name'], dirname($expectedHome));
        $runtimePath = array_key_first($targetModes);
        $persistError = null;
        if (!is_string($runtimePath)
            || $runtimePath === ''
            || !pmssIntegerSettingStorageDirEnsure(dirname($runtimePath), 0700)
            || !pmssIntegerSettingTargetModesPersist($targetModes, (int) $user['iopsLimit'], $persistError, 'invalid operations value')
        ) {
            fwrite(STDERR, "Warning: failed to persist IOPS limit for {$user['name']}: ".($persistError ?: 'unable to prepare runtime targets')."\n");
        }
    }
}
