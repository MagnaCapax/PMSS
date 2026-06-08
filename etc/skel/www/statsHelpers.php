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
    if (function_exists('pmssReadSerializedArrayFile')) {
        $state['data'] = pmssReadSerializedArrayFile($path);
    } elseif (function_exists('pmssWelcomeSerializedArrayDecode')) {
        $state['data'] = pmssWelcomeSerializedArrayDecode(@file_get_contents($path), 1048576);
    }
    if ($state['data'] === null) {
        $state['error'] = $invalidMessage;
    }

    return $state;
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

    $snapshot['ioReadDisplay'] = pmssStatsNestedArrayRead($resourceData, 'io_read', 'display');
    $snapshot['ioWriteDisplay'] = pmssStatsNestedArrayRead($resourceData, 'io_write', 'display');
    $snapshot['cpuDisplay'] = pmssStatsNestedArrayRead($resourceData, 'cpu', 'display');
    $cpuRaw = pmssStatsNestedArrayRead($resourceData, 'cpu', 'raw');
    $ioReadOpsRaw = pmssStatsNestedArrayRead($resourceData, 'io_read_ops', 'raw');
    $ioWriteOpsRaw = pmssStatsNestedArrayRead($resourceData, 'io_write_ops', 'raw');
    $snapshot['ioOperationsMonth'] = (float)($ioReadOpsRaw['month'] ?? 0.0) + (float)($ioWriteOpsRaw['month'] ?? 0.0);
    if (isset($cpuRaw['month'])) {
        $snapshot['cpuDisplay']['month'] = pmssFormatCpuHours($cpuRaw['month']);
    }
    foreach (array('week', 'day', 'hour') as $period) {
        if (!isset($snapshot['cpuDisplay'][$period]) && isset($cpuRaw[$period])) {
            $snapshot['cpuDisplay'][$period] = pmssFormatDurationSeconds($cpuRaw[$period] / 1000000000);
        }
    }

    $snapshot['memoryDisplay'] = pmssStatsNestedArrayRead($resourceData, 'memory', 'display');
    $snapshot['ramHoursDisplay'] = pmssStatsNestedArrayRead($resourceData, 'ram_hours', 'display');
    foreach (array('current' => 'memoryCurrent', 'anon' => 'memoryAnon', 'file' => 'memoryFile') as $source => $target) {
        if (isset($resourceData['memory'][$source])) {
            $snapshot[$target] = pmssFormatBytesShort($resourceData['memory'][$source]);
        }
    }
    if (isset($resourceData['tasks']['current'])) {
        $snapshot['tasksCurrent'] = (string) round((float)$resourceData['tasks']['current'], 2);
    }

    if (isset($resourceData['daily']) && is_array($resourceData['daily'])) {
        foreach ($resourceData['daily'] as $day => $totals) {
            $readBytes = isset($totals['io_read']) ? (float)$totals['io_read'] : 0.0;
            $writeBytes = isset($totals['io_write']) ? (float)$totals['io_write'] : 0.0;
            $readOps = isset($totals['io_read_ops']) ? (float)$totals['io_read_ops'] : 0.0;
            $writeOps = isset($totals['io_write_ops']) ? (float)$totals['io_write_ops'] : 0.0;
            $cpuNanoseconds = isset($totals['cpu']) ? (float)$totals['cpu'] : 0.0;
            $snapshot['ioDailyLabels'][] = $day;
            $snapshot['ioDailyRead'][] = round($readBytes / 1024 / 1024, 2);
            $snapshot['ioDailyWrite'][] = round($writeBytes / 1024 / 1024, 2);
            $snapshot['ioDailyOperations'][] = round($readOps + $writeOps, 2);
            $snapshot['cpuDailyHours'][] = round(($cpuNanoseconds / 1000000000) / 3600, 4);
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
