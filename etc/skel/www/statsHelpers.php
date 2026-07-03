<?php
/**
 * Customer-side helper functions for stats.php.
 *
 * This file is bundled into each customer tree with stats.php. Keep it
 * self-contained and never require operator-only code from /scripts.
 *
 * @license GPL-3.0-only
 */

/**
 * Run a fail-soft shell command for the stats page.
 *
 * @return array{output:?string,error:?string}
 */
function pmssInfoShellExec($command, $label): array
{
    if (!pmssFrontendShellExecAvailable()) {
        return array('output' => null, 'error' => $label.' unavailable: shell_exec disabled');
    }

    $output = pmssFrontendShellExec($command);
    if ($output === null) {
        return array('output' => '', 'error' => null);
    }

    return array('output' => $output, 'error' => null);
}

/**
 * Read one customer-visible serialized state file.
 *
 * @return array{data:?array,time:int|false|null,error:?string}
 */
function pmssStatsSerializedStateRead(string $path, string $invalidMessage): array
{
    $state = array('data' => null, 'time' => null, 'error' => null);
    if (!is_file($path) || is_link($path)) {
        return $state;
    }

    $state['time'] = @filemtime($path);
    if (function_exists('pmssCustomerSerializedArrayFileRead')) {
        $state['data'] = pmssCustomerSerializedArrayFileRead($path, 1048576);
    }
    if ($state['data'] === null) {
        $state['error'] = $invalidMessage;
    }

    return $state;
}

/** Format traffic counters stored as MiB without requiring operator-side code. */
function pmssStatsTrafficAmountFormat($valueMiB): string
{
    $valueMiB = max(0.0, (float) $valueMiB);
    if ($valueMiB > (1024 * 1024)) {
        return round($valueMiB / 1024 / 1024, 2).'TiB';
    }
    if ($valueMiB > 1024) {
        return round($valueMiB / 1024, 2).'GiB';
    }

    return round($valueMiB, 2).'MiB';
}

/** Return a display value from persisted traffic state, deriving it from raw MiB when absent. */
function pmssStatsTrafficDisplayValue(array $trafficData, string $period): string
{
    if (isset($trafficData['display']) && is_array($trafficData['display'])
        && isset($trafficData['display'][$period]) && trim((string) $trafficData['display'][$period]) !== '') {
        return (string) $trafficData['display'][$period];
    }

    if (isset($trafficData['raw']) && is_array($trafficData['raw'])
        && isset($trafficData['raw'][$period]) && is_numeric($trafficData['raw'][$period])) {
        return pmssStatsTrafficAmountFormat($trafficData['raw'][$period]);
    }

    return 'n/a';
}

/**
 * Resolve the current account UID without depending on systemd status output.
 *
 * @return array{uid:?string,error:?string}
 */
function pmssStatsCurrentUidResolve(?callable $runner = null): array
{
    if ($runner === null && function_exists('posix_getuid')) {
        return array('uid' => (string) posix_getuid(), 'error' => null);
    }

    $runner = $runner ?? 'pmssInfoShellExec';
    $uidResult = $runner('/usr/bin/id -u', 'User ID');
    if ($uidResult['error'] !== null) {
        return array('uid' => null, 'error' => $uidResult['error']);
    }

    $uid = trim((string) $uidResult['output']);
    if ($uid === '' || !ctype_digit($uid)) {
        return array('uid' => null, 'error' => 'Error: Could not determine user ID.');
    }

    return array('uid' => $uid, 'error' => null);
}

/** Detect a readable cgroup directory for display purposes. */
function pmssStatsCgroupDirDetect(string $uid, array $overrides = []): string
{
    if (isset($overrides['cgroup_dir']) && is_string($overrides['cgroup_dir']) && $overrides['cgroup_dir'] !== '') {
        return rtrim($overrides['cgroup_dir'], '/');
    }

    foreach (array('/sys/fs/cgroup/user.slice/user-'.$uid.'.slice', '/sys/fs/cgroup/unified/user.slice/user-'.$uid.'.slice', '/sys/fs/cgroup/memory/user.slice/user-'.$uid.'.slice') as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }

    $cgroupFile = (string) ($overrides['self_cgroup_file'] ?? '/proc/self/cgroup');
    $entries = function_exists('pmssCustomerCgroupSelfEntries') ? pmssCustomerCgroupSelfEntries($cgroupFile) : array();
    foreach ($entries as $entry) {
        $controllers = isset($entry['controllers']) && is_array($entry['controllers']) ? $entry['controllers'] : array();
        $roots = in_array('memory', $controllers, true)
            ? array('/sys/fs/cgroup/memory', '/sys/fs/cgroup', '/sys/fs/cgroup/unified')
            : array('/sys/fs/cgroup', '/sys/fs/cgroup/unified');
        foreach ($roots as $root) {
            $candidate = $root.(string) ($entry['path'] ?? '');
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
    }

    return '';
}

/** Build cgroup v2 and v1 candidate paths for one counter. */
function pmssStatsCgroupCounterCandidatePaths(string $uid, string $cgroupDir, string $v2File, string $v1Controller, string $v1File): array
{
    $paths = array();
    if ($cgroupDir !== '') {
        $paths[] = $cgroupDir.'/'.$v2File;
        $paths[] = $cgroupDir.'/'.$v1File;
    }

    $slice = 'user.slice/user-'.$uid.'.slice';
    $paths[] = '/sys/fs/cgroup/'.$slice.'/'.$v2File;
    $paths[] = '/sys/fs/cgroup/unified/'.$slice.'/'.$v2File;
    $paths[] = '/sys/fs/cgroup/'.$v1Controller.'/'.$slice.'/'.$v1File;

    return array_values(array_unique($paths));
}

/** Read the first valid unsigned cgroup counter, ignoring v1 unlimited sentinels for limits. */
function pmssStatsCgroupUnsignedIntegerRead(array $paths, bool $limit = false)
{
    foreach ($paths as $path) {
        $value = function_exists('pmssCustomerUnsignedIntegerFileRead')
            ? pmssCustomerUnsignedIntegerFileRead($path)
            : null;
        if ($value === null || ($limit && $value >= 1125899906842624)) {
            continue;
        }

        return $value;
    }

    return null;
}

/**
 * Read user-slice counters directly from cgroupfs.
 *
 * @return array<string,int|null>
 */
function pmssStatsCgroupCountersRead(string $uid, string $cgroupDir): array
{
    return array(
        'memory_current' => pmssStatsCgroupUnsignedIntegerRead(pmssStatsCgroupCounterCandidatePaths($uid, $cgroupDir, 'memory.current', 'memory', 'memory.usage_in_bytes')),
        'memory_high' => pmssStatsCgroupUnsignedIntegerRead(pmssStatsCgroupCounterCandidatePaths($uid, $cgroupDir, 'memory.high', 'memory', 'memory.soft_limit_in_bytes'), true),
        'memory_max' => pmssStatsCgroupUnsignedIntegerRead(pmssStatsCgroupCounterCandidatePaths($uid, $cgroupDir, 'memory.max', 'memory', 'memory.limit_in_bytes'), true),
        'tasks_current' => pmssStatsCgroupUnsignedIntegerRead(pmssStatsCgroupCounterCandidatePaths($uid, $cgroupDir, 'pids.current', 'pids', 'pids.current')),
    );
}

/** Format a cgroup counter for the base-resource text block. */
function pmssStatsCgroupCounterText($value, bool $bytes = false): string
{
    if ($value === null) {
        return 'n/a';
    }

    return $bytes && function_exists('pmssFormatBytes') ? pmssFormatBytes((float) $value) : (string) $value;
}

/**
 * Build the base-resource text from cgroupfs plus a bounded user process list.
 *
 * @return array{uid:?string,text:string}
 */
function pmssStatsBaseResourcesBuild(?callable $runner = null, array $overrides = []): array
{
    $commandRunner = $runner ?? 'pmssInfoShellExec';
    $uidState = pmssStatsCurrentUidResolve($runner);
    if ($uidState['error'] !== null) {
        return array('uid' => null, 'text' => $uidState['error']."\n");
    }

    $uid = (string) $uidState['uid'];
    $cgroupDir = pmssStatsCgroupDirDetect($uid, $overrides);
    $counters = pmssStatsCgroupCountersRead($uid, $cgroupDir);
    $processResult = $commandRunner('ps -u '.escapeshellarg($uid).' --forest -o pid=,ppid=,stat=,comm=,args= 2>&1', 'User process list');
    $processText = $processResult['error'] !== null
        ? $processResult['error']
        : trim((string) $processResult['output']);
    if ($processText === '') {
        $processText = 'No user processes found.';
    }

    $lines = array(
        'User slice: user-'.$uid.'.slice',
        'Cgroup path: '.($cgroupDir !== '' ? $cgroupDir : 'unavailable'),
        'Memory current: '.pmssStatsCgroupCounterText($counters['memory_current'], true),
        'Memory high: '.pmssStatsCgroupCounterText($counters['memory_high'], true),
        'Memory max: '.pmssStatsCgroupCounterText($counters['memory_max'], true),
        'Tasks current: '.pmssStatsCgroupCounterText($counters['tasks_current']),
        '',
        'Processes:',
        $processText,
    );

    return array('uid' => $uid, 'text' => implode("\n", $lines));
}

/** Return active when any expected customer-readable network interface exists. */
function pmssStatsNetworkInterfaceStatus(array $interfaceNames, string $interfacesRoot = '/sys/class/net'): string
{
    $interfacesRoot = rtrim($interfacesRoot, '/');
    foreach ($interfaceNames as $interfaceName) {
        if (!is_string($interfaceName) || $interfaceName === '') {
            continue;
        }
        if (@is_dir($interfacesRoot.'/'.$interfaceName)) {
            return 'active';
        }
    }

    return 'inactive';
}

/**
 * Read service, app, and Docker states for the compact server-info grid.
 *
 * @return array{wgStatus:string,ovpnStatus:string,apps:array<string,string>,dockerInactiveNote:string}
 */
function pmssStatsStatusModelBuild(?string $uid, ?bool $dockerEnabledPolicy, ?callable $runner = null, array $overrides = []): array
{
    $runner = $runner ?? 'pmssInfoShellExec';
    $interfacesRoot = isset($overrides['network_interfaces_root'])
        && is_string($overrides['network_interfaces_root'])
        && $overrides['network_interfaces_root'] !== ''
            ? $overrides['network_interfaces_root']
            : '/sys/class/net';

    $processNeedles = array('rTorrent' => 'rtorrent', 'qBittorrent' => 'qbittorrent-nox', 'Deluge' => 'deluged', 'rclone' => 'rclone');
    $psResult = $runner('ps aux | grep -E "(rtorrent|qbittorrent-nox|deluged|rclone)" | grep -v grep', 'App status');
    $psOutput = $psResult['error'] === null ? (string) $psResult['output'] : null;
    $apps = array();
    foreach ($processNeedles as $name => $needle) {
        $apps[$name] = $psOutput === null ? 'error' : (stripos($psOutput, $needle) !== false ? 'active' : 'stopped');
    }

    $dockerStatus = 'error';
    if ($uid !== null && file_exists('/run/user/'.$uid.'/docker.sock')) {
        $dockerStatus = 'active';
    } elseif ($uid !== null) {
        $dockerResult = $runner('docker ps --no-trunc 2>&1', 'Docker status');
        $dockerStatus = $dockerResult['error'] !== null
            ? 'error'
            : (strpos((string) $dockerResult['output'], 'docker ps') === false && trim((string) $dockerResult['output']) === '' ? 'active' : 'inactive');
    }
    $apps['Docker'] = $dockerStatus;

    return array(
        'wgStatus' => pmssStatsNetworkInterfaceStatus(array('wg0'), $interfacesRoot),
        'ovpnStatus' => pmssStatsNetworkInterfaceStatus(array('tun0'), $interfacesRoot),
        'apps' => $apps,
        'dockerInactiveNote' => pmssStatsDockerInactiveNote($dockerStatus, $dockerEnabledPolicy),
    );
}

/** Build the server resource text block from bounded local reads. */
function pmssStatsServerResourceTextBuild(): string
{
    $uptimeResult = pmssInfoShellExec('uptime', 'Uptime');
    $text = $uptimeResult['error'] !== null
        ? $uptimeResult['error']."\n\n"
        : trim((string) $uptimeResult['output'])."\n\n";
    $text .= "Memory usage:\n";

    $meminfo = @file_get_contents('/proc/meminfo');
    if (!$meminfo || preg_match_all('/(\w+):\s+(\d+)/', $meminfo, $m) < 1) {
        return $text."Failed to read /proc/meminfo\n";
    }

    $info = array_combine($m[1], $m[2]);
    $fmt = static function (string $key) use ($info) { return isset($info[$key]) ? $info[$key] : 0; };
    $text .= sprintf("Memory total:     %6s MiB\n", round($fmt('MemTotal') / 1024, 0));
    $text .= sprintf("Memory available: %6s MiB\n", round($fmt('MemAvailable') / 1024, 0));
    $text .= sprintf("Swap total:       %6s MiB\n", round($fmt('SwapTotal') / 1024, 0));
    $text .= sprintf("Swap free:        %6s MiB\n", round($fmt('SwapFree') / 1024, 0));

    $psi = @file_get_contents('/proc/pressure/memory');
    if ($psi && preg_match('/some avg10=([0-9.]+) avg60=([0-9.]+) avg300=([0-9.]+)/', $psi, $m) === 1) {
        $text .= sprintf("Memory pressure (some):  %s / %s / %s\n", $m[1], $m[2], $m[3]);
        if (preg_match('/full avg10=([0-9.]+) avg60=([0-9.]+) avg300=([0-9.]+)/', $psi, $f) === 1) {
            $text .= sprintf("Memory pressure (full):  %s / %s / %s\n", $f[1], $f[2], $f[3]);
        }
    } else {
        $text .= "Memory pressure: unavailable\n";
    }

    return $text;
}

/** Render the outbound/inbound traffic block from customer-readable snapshots. */
function pmssStatsRenderTrafficUsageBlock(): void
{
    $trafficState = pmssStatsSerializedStateRead('../.trafficData', 'Invalid traffic data format.');
    $trafficIngressState = pmssStatsSerializedStateRead('../.trafficDataIngress', 'Invalid inbound traffic data format.');
    $trafficData = $trafficState['data'];
    $trafficIngressData = $trafficIngressState['data'];
    $trafficLimitState = function_exists('pmssTrafficLimitStateRead')
        ? pmssTrafficLimitStateRead('../.trafficLimit', '../.bonusTraffic')
        : array('limitGiB' => 0, 'bonusGiB' => 0, 'effectiveLimitGiB' => 0);
    $trafficOutboundMonth = ($trafficData !== null && isset($trafficData['raw']['month']) && is_numeric($trafficData['raw']['month'])) ? (float) $trafficData['raw']['month'] : null;
    $trafficInboundMonth = ($trafficIngressData !== null && isset($trafficIngressData['raw']['month']) && is_numeric($trafficIngressData['raw']['month'])) ? (float) $trafficIngressData['raw']['month'] : null;
    $trafficRatioState = function_exists('pmssTrafficRatioStateBuild') ? pmssTrafficRatioStateBuild($trafficOutboundMonth, $trafficInboundMonth) : array('available' => false);

    if ($trafficData === null && $trafficIngressData === null) {
        echo '<div class="stats-block"><h6>Traffic usage</h6><pre>Traffic data not available.</pre></div>';
        return;
    }

    echo '<div class="stats-block"><h6>Traffic usage</h6><pre style="margin-bottom:12px;">'."\n";
    if ($trafficData !== null) {
        echo 'Traffic consumption at '.date('Y-m-d H:i:s', (int) $trafficState['time']).":\n";
        echo 'Week: '.pmssStatsTrafficDisplayValue($trafficData, 'week').', Day: '.pmssStatsTrafficDisplayValue($trafficData, 'day')."\n";
        echo 'Past 30 days upload traffic: '.pmssStatsTrafficDisplayValue($trafficData, 'month')."\n";
        if (file_exists('../.trafficLimit') && (int) $trafficLimitState['limitGiB'] > 0) {
            echo 'Traffic limit: '.number_format((int) $trafficLimitState['effectiveLimitGiB'])." GiB\n";
            if ($trafficLimitState['bonusGiB'] > 0) {
                echo 'Bonus traffic: '.number_format((int) $trafficLimitState['bonusGiB'])." GiB\n";
            }
        }
    } elseif ($trafficState['error'] !== null) {
        echo $trafficState['error']."\n";
    }

    echo "\n";
    if ($trafficIngressData !== null) {
        echo 'Inbound traffic at '.date('Y-m-d H:i:s', (int) $trafficIngressState['time']).":\n";
        echo 'Past 30 days inbound traffic: '.pmssStatsTrafficDisplayValue($trafficIngressData, 'month')."\n";
    } elseif ($trafficIngressState['error'] !== null) {
        echo $trafficIngressState['error']."\n";
    }
    if (!empty($trafficRatioState['available'])) {
        echo 'Inbound:Outbound ratio (month): <span class="traffic-ratio '.$trafficRatioState['class'].'">'.$trafficRatioState['display']."</span>\n";
    }
    echo "</pre>\n";

    if ($trafficData !== null && !empty($trafficData['daily']) && count($trafficData['daily']) >= 2) {
        $trafficValues = array_map(static function ($value) { return round((float) $value, 2); }, array_values($trafficData['daily']));
        pmssStatsRenderLineChart('trafficChart', array_keys($trafficData['daily']), array(
            pmssStatsChartDataset('Daily Traffic (MiB)', $trafficValues, 'rgba(75, 192, 192, 0.2)', 'rgb(75, 192, 192)'),
        ));
    } elseif ($trafficData !== null) {
        echo '<div class="docker-note">Chart requires 2+ days of data.</div>';
    }
    echo "</div>\n";
}

/**
 * Build the resource display model consumed by the resource and I/O blocks.
 */
function pmssStatsResourceSnapshotBuild(?array $resourceData): array
{
    $snapshot = array(
        'ioReadDisplay' => array(),
        'ioWriteDisplay' => array(),
        'cpuDisplay' => array(),
        'memoryDisplay' => array(),
        'ramHoursDisplay' => array(),
        'memoryCurrent' => 'n/a',
        'memoryAnon' => 'n/a',
        'memoryFile' => 'n/a',
        'tasksCurrent' => 'n/a',
        'ioOperationsMonth' => 0.0,
        'ioDailyLabels' => array(),
        'ioDailyRead' => array(),
        'ioDailyWrite' => array(),
        'ioDailyOperations' => array(),
        'cpuDailyHours' => array(),
    );
    if ($resourceData === null) {
        return $snapshot;
    }

    foreach (array('io_read' => 'ioReadDisplay', 'io_write' => 'ioWriteDisplay', 'cpu' => 'cpuDisplay', 'memory' => 'memoryDisplay', 'ram_hours' => 'ramHoursDisplay') as $section => $target) {
        $snapshot[$target] = pmssStatsNestedArrayRead($resourceData, $section, 'display');
    }
    $cpuRaw = pmssStatsNestedArrayRead($resourceData, 'cpu', 'raw');
    $ioReadOpsRaw = pmssStatsNestedArrayRead($resourceData, 'io_read_ops', 'raw');
    $ioWriteOpsRaw = pmssStatsNestedArrayRead($resourceData, 'io_write_ops', 'raw');
    $snapshot['ioOperationsMonth'] = (float)($ioReadOpsRaw['month'] ?? 0.0) + (float)($ioWriteOpsRaw['month'] ?? 0.0);
    if (isset($cpuRaw['month'])) { $snapshot['cpuDisplay']['month'] = pmssFormatCpuHours($cpuRaw['month']); }
    foreach (array('week', 'day', 'hour') as $period) {
        if (!isset($snapshot['cpuDisplay'][$period]) && isset($cpuRaw[$period])) {
            $snapshot['cpuDisplay'][$period] = pmssFormatDurationSeconds($cpuRaw[$period] / 1000000000);
        }
    }
    foreach (array('current' => 'memoryCurrent', 'anon' => 'memoryAnon', 'file' => 'memoryFile') as $source => $target) {
        if (isset($resourceData['memory'][$source])) {
            $snapshot[$target] = pmssFormatBytesShort($resourceData['memory'][$source]);
        }
    }
    if (isset($resourceData['tasks']['current'])) { $snapshot['tasksCurrent'] = (string) round((float)$resourceData['tasks']['current'], 2); }

    if (isset($resourceData['daily']) && is_array($resourceData['daily'])) {
        $dailySeries = array('ioDailyRead' => array('io_read', 1048576, 2), 'ioDailyWrite' => array('io_write', 1048576, 2), 'cpuDailyHours' => array('cpu', 3600000000000, 4));
        foreach ($resourceData['daily'] as $day => $totals) {
            $snapshot['ioDailyLabels'][] = $day;
            foreach ($dailySeries as $target => $series) {
                $snapshot[$target][] = round((float)($totals[$series[0]] ?? 0.0) / $series[1], $series[2]);
            }
            $snapshot['ioDailyOperations'][] = round((float)($totals['io_read_ops'] ?? 0.0) + (float)($totals['io_write_ops'] ?? 0.0), 2);
        }
    }

    return $snapshot;
}

/** Render the resource snapshot and storage I/O blocks. */
function pmssStatsRenderResourceBlocks(array $resourceState): void
{
    $resourceData = isset($resourceState['data']) && is_array($resourceState['data']) ? $resourceState['data'] : null;
    if ($resourceData === null) {
        $message = $resourceState['error'] !== null ? $resourceState['error'] : 'Resource data not available.';
        echo '<div class="stats-block resource-summary-block"><h6>Resource snapshot</h6><pre>'.pmssCustomerHtmlAttr($message).'</pre></div>';
        return;
    }

    $resourceTime = $resourceState['time'];
    $snapshot = pmssStatsResourceSnapshotBuild($resourceData);
    ?>
<div class="stats-block resource-summary-block">
    <h6>Resource snapshot</h6>
    <div class="resource-summary-strip">
        <div class="resource-summary-item">
            <span class="resource-summary-label">CPU</span>
            <span class="resource-summary-value"><?php echo pmssCustomerHtmlAttr($snapshot['cpuDisplay']['month'] ?? 'n/a'); ?></span>
            <span class="resource-summary-detail">Week/day/hour: <?php echo pmssCustomerHtmlAttr($snapshot['cpuDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['cpuDisplay']['day'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['cpuDisplay']['hour'] ?? 'n/a'); ?></span>
        </div>
        <div class="resource-summary-item">
            <span class="resource-summary-label">Memory</span>
            <span class="resource-summary-value"><?php echo pmssCustomerHtmlAttr($snapshot['memoryCurrent']); ?></span>
            <span class="resource-summary-detail">Process: <?php echo pmssCustomerHtmlAttr($snapshot['memoryAnon']); ?> / Cache: <?php echo pmssCustomerHtmlAttr($snapshot['memoryFile']); ?></span>
            <span class="resource-summary-detail">RAM-hours: <?php echo pmssCustomerHtmlAttr($snapshot['ramHoursDisplay']['month'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ramHoursDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ramHoursDisplay']['day'] ?? 'n/a'); ?></span>
            <span class="resource-summary-detail">Average: <?php echo pmssCustomerHtmlAttr($snapshot['memoryDisplay']['month'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['memoryDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['memoryDisplay']['day'] ?? 'n/a'); ?></span>
        </div>
        <div class="resource-summary-item">
            <span class="resource-summary-label">Processes</span>
            <span class="resource-summary-value"><?php echo pmssCustomerHtmlAttr($snapshot['tasksCurrent']); ?></span>
            <span class="resource-summary-detail">Current account process count</span>
        </div>
    </div>
    <?php if (count($snapshot['cpuDailyHours']) >= 2): ?>
    <div class="resource-summary-chart">
        <?php
        pmssStatsRenderLineChart('cpuChart', $snapshot['ioDailyLabels'], array(
            pmssStatsChartDataset('Daily CPU (hours)', $snapshot['cpuDailyHours'], 'rgba(129, 199, 132, 0.2)', 'rgb(129, 199, 132)'),
        ));
        ?>
    </div>
    <?php else: ?>
        <div class="docker-note">CPU chart requires 2+ days of data.</div>
    <?php endif; ?>
</div>

<div class="stats-block">
    <h6>Storage I/O</h6>
    <pre style="margin-bottom:12px;">
Resource usage at <?php echo date('Y-m-d H:i:s', (int)$resourceTime); ?>:
I/O Read (month/week/day/hour): <?php echo pmssCustomerHtmlAttr($snapshot['ioReadDisplay']['month'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioReadDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioReadDisplay']['day'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioReadDisplay']['hour'] ?? 'n/a'); ?>
I/O Write (month/week/day/hour): <?php echo pmssCustomerHtmlAttr($snapshot['ioWriteDisplay']['month'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioWriteDisplay']['week'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioWriteDisplay']['day'] ?? 'n/a'); ?> / <?php echo pmssCustomerHtmlAttr($snapshot['ioWriteDisplay']['hour'] ?? 'n/a'); ?>
Past 30 days total I/O operations: <?php echo pmssCustomerHtmlAttr(pmssFormatIoOperationsShort($snapshot['ioOperationsMonth'])); ?>
    </pre>

    <?php if (count($snapshot['ioDailyLabels']) >= 2): ?>
        <?php
        pmssStatsRenderLineChart('ioChart', $snapshot['ioDailyLabels'], array(
            pmssStatsChartDataset('Daily I/O Read (MiB)', $snapshot['ioDailyRead'], 'rgba(75, 192, 192, 0.2)', 'rgb(75, 192, 192)'),
            pmssStatsChartDataset('Daily I/O Write (MiB)', $snapshot['ioDailyWrite'], 'rgba(244, 67, 54, 0.2)', 'rgb(244, 67, 54)'),
        ));
        pmssStatsRenderLineChart('iopsChart', $snapshot['ioDailyLabels'], array(
            pmssStatsChartDataset('Daily I/O Operations', $snapshot['ioDailyOperations'], 'rgba(255, 193, 7, 0.2)', 'rgb(255, 193, 7)'),
        ));
        ?>
    <?php else: ?>
        <div class="docker-note">Chart requires 2+ days of data.</div>
    <?php endif; ?>
</div>
    <?php
}

/**
 * Build the Docker inactive note shown beside the Docker app status.
 */
function pmssStatsDockerInactiveNote(
    string $dockerStatus,
    ?bool $dockerEnabledPolicy,
    string $osReleasePath = '/etc/os-release',
    string $debianVersionPath = '/etc/debian_version',
    string $cmdlinePath = '/proc/cmdline'
): string {
    if ($dockerStatus !== 'inactive') {
        return '';
    }

    $cmdline = (string) @file_get_contents($cmdlinePath);
    if (strpos($cmdline, 'unified_cgroup_hierarchy=0') !== false) {
        if ($dockerEnabledPolicy === false) {
            return ' (Docker is available but currently disabled by policy. Contact support if it should be enabled.)';
        }

        return $dockerEnabledPolicy === true
            ? ' (Docker is enabled by policy but not currently running. Contact support if it should be restarted.)'
            : ' (Docker is available but not currently running. Contact support if it should be enabled for this account.)';
    }

    $debianLabel = 'Debian';
    $osRelease = @parse_ini_file($osReleasePath);
    if (is_array($osRelease)) {
        $versionId = trim((string) ($osRelease['VERSION_ID'] ?? ''));
        if ($versionId !== '') {
            $debianLabel = preg_match('/^([0-9]+)/', $versionId, $matches) === 1
                ? 'Debian '.$matches[1]
                : 'Debian '.$versionId;
        }
    }
    if ($debianLabel === 'Debian') {
        $debianVersion = trim((string) @file_get_contents($debianVersionPath));
        if (preg_match('/^([0-9]+)/', $debianVersion, $matches) === 1) {
            $debianLabel = 'Debian '.$matches[1];
        }
    }

    return sprintf(
        ' (%s: User bus restricted. Requires `systemd.unified_cgroup_hierarchy=0` in GRUB. Contact support.)',
        $debianLabel
    );
}

/**
 * Build an inline enable/disable control next to a customer-managed app status.
 */
function pmssStatsAppToggleButtonHtmlBuild(string $appName, array $definitions): string
{
    if (!isset($definitions[$appName]) || !is_array($definitions[$appName])) {
        return '';
    }

    $definition = $definitions[$appName];
    $endpoint = isset($definition['endpoint']) ? (string) $definition['endpoint'] : '';
    $enableFile = isset($definition['enable']) ? (string) $definition['enable'] : '';
    $endpointPath = __DIR__.'/'.$endpoint;
    if ($endpoint === '' || $enableFile === '' || !is_file($endpointPath) || is_link($endpointPath)) {
        return '';
    }

    $enabled = is_file($enableFile);
    $action = $enabled ? 'disable' : 'start';
    $label = $enabled ? 'Disable' : 'Enable';
    $class = $enabled ? 'pmss-app-toggle-disable' : 'pmss-app-toggle-enable';

    return '<button type="button" class="pmss-app-toggle '.$class.'"'
        .' data-app="'.pmssCustomerHtmlAttr($appName).'"'
        .' data-endpoint="'.pmssCustomerHtmlAttr($endpoint).'"'
        .' data-action="'.pmssCustomerHtmlAttr($action).'"'
        .' aria-label="'.pmssCustomerHtmlAttr($label.' '.$appName).'"'
        .' onclick="return pmssStatsToggleApp(this);">'.$label.'</button><span class="pmss-app-toggle-feedback" aria-live="polite"></span>';
}

/** Build one Chart.js dataset row with the defaults expected by stats.php. */
function pmssStatsChartDataset(string $label, array $data, string $backgroundColor, string $borderColor): array
{
    return array('label' => $label, 'data' => $data, 'backgroundColor' => $backgroundColor, 'borderColor' => $borderColor);
}

/**
 * Render a Chart.js line chart with the shared PMSS stats defaults.
 */
function pmssStatsRenderLineChart($canvasId, array $labels, array $datasets): void
{
    $normalizedDatasets = array();
    foreach ($datasets as $dataset) {
        if (!is_array($dataset) || !isset($dataset['label'], $dataset['data'], $dataset['backgroundColor'], $dataset['borderColor'])) {
            continue;
        }

        $normalizedDatasets[] = array(
            'label' => (string) $dataset['label'],
            'data' => array_values((array) $dataset['data']),
            'fill' => array_key_exists('fill', $dataset) ? (bool) $dataset['fill'] : true,
            'backgroundColor' => (string) $dataset['backgroundColor'],
            'borderColor' => (string) $dataset['borderColor'],
            'tension' => array_key_exists('tension', $dataset) ? (float) $dataset['tension'] : 0.4,
            'pointRadius' => array_key_exists('pointRadius', $dataset) ? (int) $dataset['pointRadius'] : 3,
        );
    }

    if ($normalizedDatasets === array()) {
        return;
    }

    $encodedLabels = json_encode(array_values($labels));
    $encodedDatasets = json_encode($normalizedDatasets, JSON_UNESCAPED_SLASHES);
    $encodedCanvasId = json_encode((string) $canvasId);
    if (!is_string($encodedLabels) || !is_string($encodedDatasets) || !is_string($encodedCanvasId)) {
        return;
    }

    $safeCanvasId = pmssCustomerHtmlAttr($canvasId);
    echo '<div class="traffic-chart"><canvas id="'.$safeCanvasId.'" width="600" height="250"></canvas></div>'."\n";
    echo "<script>\n";
    echo "document.addEventListener('DOMContentLoaded', () => {\n";
    echo "    if (typeof Chart === 'undefined') return;\n";
    echo "    const element = document.getElementById({$encodedCanvasId});\n";
    echo "    if (!element) return;\n";
    echo "    new Chart(element.getContext('2d'), {\n";
    echo "        type: 'line',\n";
    echo "        data: { labels: {$encodedLabels}, datasets: {$encodedDatasets} },\n";
    echo "        options: pmssStatsChartOptions()\n";
    echo "    });\n";
    echo "});\n";
    echo "</script>\n";
}

/** Return a nested array section or an empty fallback. */
function pmssStatsNestedArrayRead(array $source, string $section, string $key): array
{
    return isset($source[$section][$key]) && is_array($source[$section][$key])
        ? $source[$section][$key]
        : array();
}

/** Format a byte count for compact customer stats labels. */
function pmssFormatBytesShort($bytes): string
{
    $bytes = (float) $bytes;
    foreach (array(1099511627776 => 'TiB', 1073741824 => 'GiB', 1048576 => 'MiB') as $divisor => $unit) {
        if (($bytes / $divisor) > 1) {
            return round($bytes / $divisor, 2).$unit;
        }
    }

    return round($bytes / 1024, 2).'KiB';
}

/** Format short elapsed durations for resource summaries. */
function pmssFormatDurationSeconds($seconds): string
{
    $seconds = (float) $seconds;
    if ($seconds >= 3600) {
        return round($seconds / 3600, 2).'h';
    }
    if ($seconds >= 60) {
        return round($seconds / 60, 2).'m';
    }
    return round($seconds, 2).'s';
}

/** Format monthly CPU nanoseconds as CPU-hours. */
function pmssFormatCpuHours($nanoseconds): string
{
    $hours = ((float) $nanoseconds / 1000000000) / 3600;
    return round($hours, 2).' CPU-hours';
}

/** Format an I/O operation count for compact display. */
function pmssFormatIoOperationsShort($operations): string
{
    $operations = max(0.0, (float) $operations);
    foreach (array(1000000000.0 => 'billion', 1000000.0 => 'million', 1000.0 => 'thousand') as $divisor => $unit) {
        if ($operations >= $divisor) {
            $value = $operations / $divisor;
            $decimals = $value >= 100 ? 0 : ($value >= 10 ? 1 : 2);
            return number_format($value, $decimals).' '.$unit.' IO operations';
        }
    }

    return number_format($operations, 0).' IO operations';
}
