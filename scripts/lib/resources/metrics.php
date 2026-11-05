<?php
/**
 * Comprehensive per-user performance metric collection (cgroup v1).
 *
 * Purpose: capture every available per-user cgroup performance metric as a
 * self-describing time-series, additively, WITHOUT touching the billing-critical
 * resource log (scripts/lib/resources/log.php) or its format. Output is one JSON
 * object per cycle per user (JSONL); new metrics are added by adding a read — old
 * readers ignore unknown keys, missing keys are simply absent (graceful absence).
 *
 * The fleet runs cgroup v1 legacy (systemd.unified_cgroup_hierarchy=0). This
 * collector reads the v1 per-controller trees and optional io.pressure from the
 * hybrid unified mount. That observation does not enable v2 enforcement; hosts
 * without the unified pressure interface simply omit these metrics.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/counters.php';
require_once dirname(__DIR__).'/systemStats/parse.php';

/**
 * Collect every available per-user performance metric for one UID from the
 * cgroup v1 sysfs tree.
 *
 * Every value is read independently and OMITTED when its source is missing/
 * unreadable/non-numeric. Returns [] when nothing at all is readable (caller skips).
 *
 * @param string|null $cgroupRoot sysfs root, injectable for tests (default /sys/fs/cgroup).
 * @return array<string,int|float>
 */
function pmssUserMetricsCollect(int $uid, ?string $cgroupRoot = null): array
{
    $metrics = pmssResourceV1ReadMetrics($uid, $cgroupRoot);
    // Telemetry records local memory fields and available IO, without billing's source marker.
    unset($metrics['mem_total_rss'], $metrics['mem_total_cache'], $metrics['io_source']);
    $root = rtrim($cgroupRoot ?? '/sys/fs/cgroup', '/');
    $raw = pmssReadRegularFileContents($root.'/unified/user.slice/user-'.$uid.'.slice/io.pressure');
    if ($raw !== null) {
        foreach (['avg300', 'total'] as $field) {
            foreach (['some', 'full'] as $row) {
                $value = pmssSystemStatsPsiMetricFromRaw($raw, $row, $field);
                if ($value !== null) $metrics['io_pressure_'.$row.'_'.$field] = $value;
            }
        }
    }
    return $metrics;
}
