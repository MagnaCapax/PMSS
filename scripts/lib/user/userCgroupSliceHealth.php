<?php
/**
 * Per-user cgroup slice health checks and self-heal helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/userConfigCli.php';
require_once __DIR__.'/userConfigStore.php';
require_once __DIR__.'/userCgroupSliceDropinRepair.php';
require_once dirname(__DIR__).'/cgroup/Manager.php';

/**
 * Return the expected MemoryMax bytes for a stored user plan.
 */
function pmssUserCgroupSliceExpectedMemoryMaxBytes(array $payload, ?int $totalMemMiB = null): int
{
    $memoryHighMiB = isset($payload['ramMiB']) && is_numeric($payload['ramMiB']) ? (int) $payload['ramMiB'] : 0;
    if ($memoryHighMiB <= 0) {
        return 0;
    }

    $memory = \PMSS\Cgroup\Manager::computeMemoryProperties(
        $memoryHighMiB,
        null,
        $totalMemMiB !== null ? $totalMemMiB : pmssProcMeminfoTotalMiBRead()
    );

    return (int) $memory['memoryMaxMiB'] * 1048576;
}

/**
 * Plan whether the live slice memory policy is lower than the stored user plan.
 *
 * @param array<string,mixed> $payload
 * @param array<string,string>|null $properties
 * @return array{needed:bool,reason:string,expectedMemoryMaxBytes:int,currentMemoryMaxBytes:?int}
 */
function pmssUserCgroupSliceMemoryRefreshPlan(
    string $username,
    array $payload,
    ?array $properties = null,
    ?int $totalMemMiB = null
): array {
    if (!pmssValidateUsername($username)) {
        return ['needed' => false, 'reason' => 'invalid_username', 'expectedMemoryMaxBytes' => 0, 'currentMemoryMaxBytes' => null];
    }

    $expected = pmssUserCgroupSliceExpectedMemoryMaxBytes($payload, $totalMemMiB);
    if ($expected <= 0) {
        return ['needed' => false, 'reason' => 'missing_ram_baseline', 'expectedMemoryMaxBytes' => 0, 'currentMemoryMaxBytes' => null];
    }

    $properties = $properties ?? pmssReadUserSlicePropertiesByUsername($username, ['MemoryMax']);
    $current = pmssSystemdPropertyTrailingInt((string) ($properties['MemoryMax'] ?? ''));
    if ($current === null) {
        return ['needed' => false, 'reason' => 'memory_max_unset_or_unreadable', 'expectedMemoryMaxBytes' => $expected, 'currentMemoryMaxBytes' => null];
    }

    return [
        'needed' => $current < $expected,
        'reason' => $current < $expected ? 'memory_max_below_plan' : 'memory_max_at_or_above_plan',
        'expectedMemoryMaxBytes' => $expected,
        'currentMemoryMaxBytes' => $current,
    ];
}

/** Build the canonical cgroup apply command for a stored user plan. */
function pmssUserCgroupSliceApplyCommand(string $username, array $payload): ?string
{
    $args = pmssUserConfigCliBuildStoredCgroupApplyArgs($username, $payload);
    return $args === null ? null : pmssBuildCommand('php', $args);
}

/** Reapply the stored cgroup plan when the live slice memory cap has drifted low. */
function pmssUserCgroupSliceSelfHeal(string $username, UserConfigStore $store): bool
{
    $dropinRepaired = pmssUserCgroupSliceRepairLegacyBareMemoryMaxForUser($username, static function (string $message) use ($username): void {
        pmssUserLog($username, $message);
        logMessage($message);
    });
    if ($dropinRepaired) {
        $rc = runUserStep($username, 'Reloading systemd after cgroup slice drop-in repair', 'systemctl daemon-reload');
        if ($rc !== 0) {
            pmssUserLog($username, sprintf('[WARN] systemd daemon-reload failed after cgroup slice drop-in repair (rc=%d)', $rc));
            return false;
        }
    }

    $payload = $store->applyFallbacks($username, $store->get($username) ?? []);
    $plan = pmssUserCgroupSliceMemoryRefreshPlan($username, $payload);
    if (!$plan['needed']) {
        return true;
    }

    $command = pmssUserCgroupSliceApplyCommand($username, $payload);
    if ($command === null) {
        pmssUserLog($username, '[WARN] cgroup slice refresh skipped: missing RAM baseline');
        return false;
    }

    $rc = runUserStep($username, 'Refreshing cgroup slice memory policy', $command);
    if ($rc !== 0) {
        pmssUserLog($username, sprintf('[WARN] cgroup slice refresh failed (rc=%d)', $rc));
        return false;
    }

    pmssUserLog($username, sprintf(
        'cgroup slice memory policy refreshed (current=%s expected=%d)',
        $plan['currentMemoryMaxBytes'] === null ? 'unknown' : (string) $plan['currentMemoryMaxBytes'],
        $plan['expectedMemoryMaxBytes']
    ));
    return true;
}
