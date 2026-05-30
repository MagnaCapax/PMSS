<?php
/**
 * Cron helpers for reapplying explicit per-user cgroup IO policy.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/userConfigCli.php';
require_once __DIR__.'/userConfigStore.php';

/** Return true when the payload includes explicit io.latency/io.cost knobs. */
function pmssCgroupRefreshHasExplicitIoPolicy(array $payload): bool
{
    $latencyMs = $payload['ioLatencyMs'] ?? null;
    return (is_numeric($latencyMs) && (int) $latencyMs > 0)
        || trim((string) ($payload['ioCostQos'] ?? '')) !== ''
        || trim((string) ($payload['ioCostModel'] ?? '')) !== '';
}

/** Build the canonical per-user cgroup refresh command from stored config. */
function pmssCgroupRefreshBuildCommand(string $username, array $payload): ?string
{
    $args = pmssUserConfigCliBuildStoredCgroupApplyArgs($username, $payload);
    if ($args === null) {
        return null;
    }

    return pmssBuildCommand('php', $args);
}

/** Reapply explicit io.latency/io.cost settings for every matching managed user. */
function pmssCgroupPolicyRefreshRun(): int
{
    $store = new UserConfigStore();
    foreach (pmssListManagedUsers('/scripts/listUsers.php') as $user) {
        $payload = $store->applyFallbacks($user, $store->get($user) ?? []);
        if (!pmssCgroupRefreshHasExplicitIoPolicy($payload)) {
            continue;
        }

        $command = pmssCgroupRefreshBuildCommand($user, $payload);
        if ($command === null) {
            function_exists('pmssUserLog') && pmssUserLog($user, '[WARN] cgroupPolicyRefresh skipped: missing RAM baseline');
            continue;
        }

        $rc = runStep('Refreshing cgroup IO policy for '.$user, $command);
        if ($rc === 0) {
            function_exists('pmssUserLog') && pmssUserLog($user, 'cgroup IO policy refreshed from stored config');
        }
    }

    return 0;
}
