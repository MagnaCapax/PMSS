<?php
/** Apply opt-in per-account cgroup-v1 bandwidth caps in the existing IOPS cycle. @license GPL-3.0-only */

require_once __DIR__.'/directApply.php';
require_once __DIR__.'/policy.php';

/** Parse a positive byte rate with systemd's decimal K/M/G/T suffixes, without overflow. */
function pmssCgroupBandwidthSpecParse($raw): ?array
{
    if (!is_string($raw) || preg_match('#^(/home|/dev/[^:\s\x00]+):([0-9]+)([KMGT]?)\z#', $raw, $match) !== 1) return null;
    if ($match[1] !== '/home' && !pmssCgroupPolicyDeviceTargetIsSafe($match[1])) return null;
    $rate = pmssUnsignedDecimalIntParse($match[2]);
    $factor = ['' => 1, 'K' => 1000, 'M' => 1000000, 'G' => 1000000000, 'T' => 1000000000000][$match[3]];
    if ($rate === null || $rate <= 0 || $rate > intdiv(PHP_INT_MAX, $factor)) return null;
    return [$match[1], $rate * $factor];
}

/** Build only explicit account caps; shipped mount/profile defaults are not implicit account limits. */
function pmssCgroupBandwidthPlan(array $json, array $policy, string $homeMajorMinor, ?callable $resolver = null): array
{
    if (($policy['cgroup']['bandwidthEnforce'] ?? false) !== true) return [];
    $resolver = $resolver ?? static function (string $device): ?string { return pmssCgroupPolicyDeviceMajorMinorResolve($device); };
    $plan = [];
    foreach (['read' => 'IOReadBW', 'write' => 'IOWriteBW'] as $direction => $key) {
        $spec = pmssCgroupBandwidthSpecParse($json[$key] ?? null);
        if ($spec === null) continue; // Empty/invalid never clears a previously applied cap.
        [$device, $rate] = $spec;
        // An unresolved explicit device reaches the writer as an invalid target, never as /home.
        $plan[] = [$direction, $device === '/home' ? $homeMajorMinor : ($resolver($device) ?? ''), $rate];
    }
    return $plan;
}

/** Write only bandwidth throttle files in positive-UID slices; no clear operation is exposed. */
function pmssCgroupBandwidthWriteThrottle(string $path, string $device, int $rate, bool $dryRun): array
{
    if ($rate <= 0 || !pmssCgroupPolicyMajorMinorIsValid($device)
        || !pmssCgroupDirectUserBlkioPathAllowed($path, ['blkio.throttle.read_bps_device', 'blkio.throttle.write_bps_device'])) {
        return ['ok' => false, 'reason' => 'invalid-target', 'cur' => null];
    }
    if (!pmssCgroupDirectWritableFileTarget($path)) return ['ok' => false, 'reason' => 'unwritable', 'cur' => null];
    $raw = @file_get_contents($path);
    if ($raw === false) return ['ok' => false, 'reason' => 'unreadable', 'cur' => null];
    $cur = trim($raw);
    if (pmssCgroupDirectDeviceValueParse($cur, $device) === $rate) return ['ok' => true, 'reason' => 'already-set', 'cur' => $cur];
    if ($dryRun || pmssEnvFlagEnabled('PMSS_DRY_RUN')) return ['ok' => true, 'reason' => 'dry-run', 'cur' => $cur];
    $ok = pmssFileWriteComplete($path, $device.' '.$rate);
    return ['ok' => $ok, 'reason' => $ok ? 'written' : 'write-failed', 'cur' => $cur];
}

/** Share the cron's counters and private audit, after its census and missing-slice guards. */
function pmssCgroupBandwidthApply(string $user, int $uid, array $plan, bool $dryRun, int &$written, int &$errors): void
{
    foreach ($plan as [$direction, $device, $rate]) {
        $path = pmssCgroupDirectUserBlkioFilePath($uid, 'blkio.throttle.'.$direction.'_bps_device');
        $result = pmssCgroupBandwidthWriteThrottle($path, $device, $rate, $dryRun);
        if (!$result['ok']) {
            $errors++;
            syslog(LOG_WARNING, "$direction-bps $user uid=$uid: ".$result['reason']);
        } elseif ($result['reason'] === 'written') {
            $written++;
            syslog(LOG_INFO, "$direction-bps $user uid=$uid: $device $rate");
            pmssCgroupDirectAudit($user, $uid, $direction.'_bps',
                pmssCgroupDirectDeviceValueParse($result['cur'], $device), $rate, __FILE__);
        } elseif ($result['reason'] === 'dry-run') {
            printf("[DRY-RUN] %-20s uid=%d %s-bps: %s %d\n", $user, $uid, $direction, $device, $rate);
        }
    }
}
