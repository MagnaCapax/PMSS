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
 * collector reads the v1 per-controller sysfs trees only. Per-cgroup PSI is a
 * cgroup-v2-only kernel feature and is therefore NOT collected — it is not a
 * pending decision, it does not exist on v1.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/counters.php';

/**
 * Collect every available per-user performance metric for one UID from the
 * cgroup v1 sysfs tree.
 *
 * Every value is read independently and OMITTED when its source is missing/
 * unreadable/non-numeric. Returns [] when nothing at all is readable (caller skips).
 *
 * @param string|null $cgroupRoot sysfs root, injectable for tests (default /sys/fs/cgroup).
 * @return array<string,int>
 */
function pmssUserMetricsCollect(int $uid, ?string $cgroupRoot = null): array
{
    $metrics = pmssResourceV1ReadMetrics($uid, $cgroupRoot);
    // Telemetry records local memory fields and available IO, without billing's source marker.
    unset($metrics['mem_total_rss'], $metrics['mem_total_cache'], $metrics['io_source']);
    return $metrics;
}
