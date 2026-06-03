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
 * Return customer-managed app toggle endpoints for the info-page app rows.
 *
 * @return array<string,array{enable:string,endpoint:string}>
 */
function pmssStatsAppToggleDefinitions(): array
{
    return array(
        'qBittorrent' => array('enable' => '../.qbittorrentEnable', 'endpoint' => 'qbittorrent.php'),
        'Deluge'      => array('enable' => '../.delugeEnable',      'endpoint' => 'deluge.php'),
        'rclone'      => array('enable' => '../.rcloneEnable',      'endpoint' => 'rclone.php'),
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
        .' data-app="'.htmlspecialchars($appName, ENT_QUOTES, 'UTF-8').'"'
        .' data-endpoint="'.htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8').'"'
        .' data-action="'.htmlspecialchars($action, ENT_QUOTES, 'UTF-8').'"'
        .' aria-label="'.htmlspecialchars($label.' '.$appName, ENT_QUOTES, 'UTF-8').'"'
        .' onclick="return pmssStatsToggleApp(this);">'.$label.'</button>'
        .'<span class="pmss-app-toggle-feedback" aria-live="polite"></span>';
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

    $safeCanvasId = htmlspecialchars((string) $canvasId, ENT_QUOTES, 'UTF-8');
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
