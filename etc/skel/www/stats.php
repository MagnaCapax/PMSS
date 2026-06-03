<?php
/**
 * PMSS: Frontend Statistics Section on Info Page
 *
 * Live `systemctl` status for WG/OpenVPN, accurate Docker detection,
 * traffic chart (2+ days), taller CGroup block, responsive layout.
 *
 * Original concept and implementation: Aleksi Ursin, circa 2010–2015.
 *
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 *
 * @author  Pulsed Media Dev Team
 * @package PMSS
 *
 * #TODO: Hover on status → show systemctl logs
 * #TODO: Click status → attempt restart (if allowed)
 */
require_once __DIR__.'/scriptsInc.php';

// Fail-soft: data collection must never abort page rendering. Show notes and
// keep going if commands or files are unavailable.
if (!function_exists('pmssInfoShellExec')) {
    function pmssInfoShellExec($command, $label)
    {
        if (!pmssFrontendShellExecAvailable()) {
            return array('output' => null, 'error' => $label . ' unavailable: shell_exec disabled');
        }

        $output = pmssFrontendShellExec($command);
        if ($output === null) {
            return array('output' => '', 'error' => null);
        }

        return array('output' => $output, 'error' => null);
    }
}

if (!function_exists('pmssStatsDockerInactiveNote')) {
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
}

if (!function_exists('pmssStatsAppToggleDefinitions')) {
    /**
     * Return customer-managed app toggle endpoints for the info-page app rows.
     */
    function pmssStatsAppToggleDefinitions(): array
    {
        return array(
            'qBittorrent' => array('enable' => '../.qbittorrentEnable', 'endpoint' => 'qbittorrent.php'),
            'Deluge'      => array('enable' => '../.delugeEnable',      'endpoint' => 'deluge.php'),
            'rclone'      => array('enable' => '../.rcloneEnable',      'endpoint' => 'rclone.php'),
        );
    }
}

if (!function_exists('pmssStatsAppToggleButtonHtmlBuild')) {
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
}

if (defined('PMSS_STATS_HELPERS_ONLY') && PMSS_STATS_HELPERS_ONLY) {
    return;
}

$pmssWebCgroupMemoryStatusLib = __DIR__.'/webCgroupMemoryStatus.php';
if (file_exists($pmssWebCgroupMemoryStatusLib)) {
    require_once $pmssWebCgroupMemoryStatusLib;
}

// Customer-side traffic-limit reader: see userTrafficLimit.php for rationale.
$pmssUserTrafficLimitLib = __DIR__.'/userTrafficLimit.php';
if (file_exists($pmssUserTrafficLimitLib)) {
    require_once $pmssUserTrafficLimitLib;
}
// pmssTrafficLimitStateRead lives in userTrafficLimit.php (see ADR 0016).

if (!function_exists('pmssStatsRenderLineChart')) {
    /**
     * Render a Chart.js line chart with the shared PMSS stats defaults.
     */
    function pmssStatsRenderLineChart($canvasId, array $labels, array $datasets)
    {
        $normalizedDatasets = array();
        foreach ($datasets as $dataset) {
            if (!is_array($dataset) || !isset($dataset['label'], $dataset['data'], $dataset['backgroundColor'], $dataset['borderColor'])) {
                continue;
            }

            $normalizedDatasets[] = array(
                'label' => (string)$dataset['label'],
                'data' => array_values((array)$dataset['data']),
                'fill' => array_key_exists('fill', $dataset) ? (bool)$dataset['fill'] : true,
                'backgroundColor' => (string)$dataset['backgroundColor'],
                'borderColor' => (string)$dataset['borderColor'],
                'tension' => array_key_exists('tension', $dataset) ? (float)$dataset['tension'] : 0.4,
                'pointRadius' => array_key_exists('pointRadius', $dataset) ? (int)$dataset['pointRadius'] : 3,
            );
        }

        if ($normalizedDatasets === array()) {
            return;
        }

        $encodedLabels = json_encode(array_values($labels));
        $encodedDatasets = json_encode($normalizedDatasets, JSON_UNESCAPED_SLASHES);
        $encodedCanvasId = json_encode((string)$canvasId);
        if (!is_string($encodedLabels) || !is_string($encodedDatasets) || !is_string($encodedCanvasId)) {
            return;
        }

        $safeCanvasId = htmlspecialchars((string)$canvasId, ENT_QUOTES, 'UTF-8');
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
}

if (!function_exists('pmssStatsSerializedStateRead')) {
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
        $state['data'] = function_exists('pmssReadSerializedArrayFile')
            ? pmssReadSerializedArrayFile($path)
            : null;
        if ($state['data'] === null) {
            $state['error'] = $invalidMessage;
        }

        return $state;
    }
}

if (!function_exists('pmssStatsNestedArrayRead')) {
    /**
     * Return a nested array section or an empty fallback.
     */
    function pmssStatsNestedArrayRead(array $source, string $section, string $key): array
    {
        return isset($source[$section][$key]) && is_array($source[$section][$key])
            ? $source[$section][$key]
            : array();
    }
}

if (!function_exists('pmssFormatBytesShort')) {
    function pmssFormatBytesShort($bytes)
    {
        $bytes = (float)$bytes;
        foreach (array(1099511627776 => 'TiB', 1073741824 => 'GiB', 1048576 => 'MiB') as $divisor => $unit) {
            if (($bytes / $divisor) > 1) {
                return round($bytes / $divisor, 2).$unit;
            }
        }

        return round($bytes / 1024, 2).'KiB';
    }
}

if (!function_exists('pmssFormatDurationSeconds')) {
    function pmssFormatDurationSeconds($seconds)
    {
        $seconds = (float)$seconds;
        if ($seconds >= 3600) {
            return round($seconds / 3600, 2).'h';
        }
        if ($seconds >= 60) {
            return round($seconds / 60, 2).'m';
        }
        return round($seconds, 2).'s';
    }
}

if (!function_exists('pmssFormatCpuHours')) {
    function pmssFormatCpuHours($nanoseconds)
    {
        $hours = ((float)$nanoseconds / 1000000000) / 3600;
        return round($hours, 2).' CPU-hours';
    }
}

if (!function_exists('pmssFormatIoOperationsShort')) {
    function pmssFormatIoOperationsShort($operations)
    {
        $operations = max(0.0, (float)$operations);
        foreach (array(1000000000.0 => 'billion', 1000000.0 => 'million', 1000.0 => 'thousand') as $divisor => $unit) {
            if ($operations >= $divisor) {
                $value = $operations / $divisor;
                $decimals = $value >= 100 ? 0 : ($value >= 10 ? 1 : 2);
                return number_format($value, $decimals).' '.$unit.' IO operations';
            }
        }

        return number_format($operations, 0).' IO operations';
    }
}

$pmssDockerEnabledPolicy = null;
$pmssMemoryPressure = function_exists('pmssWebCgroupMemoryStatusRead')
    ? pmssWebCgroupMemoryStatusRead()
    : array('available' => false, 'status' => 'UNAVAILABLE');

// === Resource Usage ===
$resourceState = pmssStatsSerializedStateRead('../.resourceData', 'Invalid resource data format.');
$resourceData = $resourceState['data'];
$resourceTime = $resourceState['time'];
$resourceDataError = $resourceState['error'];
$resourceAvailable = is_array($resourceData);
$resourceMessage = $resourceDataError !== null ? $resourceDataError : 'Resource data not available.';
$ioReadDisplay = array();
$ioWriteDisplay = array();
$cpuDisplay = array();
$memoryDisplay = array();
$ramHoursDisplay = array();
$memoryCurrent = 'n/a';
$memoryAnon = 'n/a';
$memoryFile = 'n/a';
$tasksCurrent = 'n/a';
$ioOperationsMonth = 0.0;
$ioDailyLabels = array();
$ioDailyRead = array();
$ioDailyWrite = array();
$ioDailyOperations = array();
$cpuDailyHours = array();

if ($resourceAvailable) {
    $ioReadDisplay = pmssStatsNestedArrayRead($resourceData, 'io_read', 'display');
    $ioWriteDisplay = pmssStatsNestedArrayRead($resourceData, 'io_write', 'display');
    $cpuDisplay = pmssStatsNestedArrayRead($resourceData, 'cpu', 'display');
    $cpuRaw = pmssStatsNestedArrayRead($resourceData, 'cpu', 'raw');
    $ioReadOpsRaw = pmssStatsNestedArrayRead($resourceData, 'io_read_ops', 'raw');
    $ioWriteOpsRaw = pmssStatsNestedArrayRead($resourceData, 'io_write_ops', 'raw');
    $ioOperationsMonth = (float)($ioReadOpsRaw['month'] ?? 0.0) + (float)($ioWriteOpsRaw['month'] ?? 0.0);
    if (isset($cpuRaw['month'])) {
        $cpuDisplay['month'] = pmssFormatCpuHours($cpuRaw['month']);
    }
    if (!isset($cpuDisplay['week']) && isset($cpuRaw['week'])) {
        $cpuDisplay['week'] = pmssFormatDurationSeconds($cpuRaw['week'] / 1000000000);
    }
    if (!isset($cpuDisplay['day']) && isset($cpuRaw['day'])) {
        $cpuDisplay['day'] = pmssFormatDurationSeconds($cpuRaw['day'] / 1000000000);
    }
    if (!isset($cpuDisplay['hour']) && isset($cpuRaw['hour'])) {
        $cpuDisplay['hour'] = pmssFormatDurationSeconds($cpuRaw['hour'] / 1000000000);
    }
    $memoryDisplay = pmssStatsNestedArrayRead($resourceData, 'memory', 'display');
    $ramHoursDisplay = pmssStatsNestedArrayRead($resourceData, 'ram_hours', 'display');
    $memoryCurrent = isset($resourceData['memory']['current'])
        ? pmssFormatBytesShort($resourceData['memory']['current'])
        : 'n/a';
    $memoryAnon = isset($resourceData['memory']['anon'])
        ? pmssFormatBytesShort($resourceData['memory']['anon'])
        : 'n/a';
    $memoryFile = isset($resourceData['memory']['file'])
        ? pmssFormatBytesShort($resourceData['memory']['file'])
        : 'n/a';
    $tasksCurrent = isset($resourceData['tasks']['current'])
        ? (string)round((float)$resourceData['tasks']['current'], 2)
        : 'n/a';

    if (isset($resourceData['daily']) && is_array($resourceData['daily'])) {
        foreach ($resourceData['daily'] as $day => $totals) {
            $ioDailyLabels[] = $day;
            $readBytes = isset($totals['io_read']) ? (float)$totals['io_read'] : 0.0;
            $writeBytes = isset($totals['io_write']) ? (float)$totals['io_write'] : 0.0;
            $readOps = isset($totals['io_read_ops']) ? (float)$totals['io_read_ops'] : 0.0;
            $writeOps = isset($totals['io_write_ops']) ? (float)$totals['io_write_ops'] : 0.0;
            $cpuNanoseconds = isset($totals['cpu']) ? (float)$totals['cpu'] : 0.0;
            $ioDailyRead[] = round($readBytes / 1024 / 1024, 2);
            $ioDailyWrite[] = round($writeBytes / 1024 / 1024, 2);
            $ioDailyOperations[] = round($readOps + $writeOps, 2);
            $cpuDailyHours[] = round(($cpuNanoseconds / 1000000000) / 3600, 4);
        }
    }
}
?>

<style>
.stats-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin: 20px 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
@media (max-width: 1200px) {
    .stats-container { grid-template-columns: 1fr; }
}

.stats-block {
    background: #1e1e1e;
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    color: #e0e0e0;
}
.stats-block h6 {
    margin: 0 0 12px;
    color: #4fc3f7;
    font-size: 1.1em;
    font-weight: 600;
    border-bottom: 1px solid #333;
    padding-bottom: 6px;
}

.info-line {
    display: flex;
    justify-content: space-between;
    margin: 6px 0;
    font-size: 0.95em;
}
.info-line .label { color: #81c784; font-weight: 500; }
.info-line .value { color: #fff; }

.status-grid {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 8px 12px;
    margin-top: 10px;
    font-size: 0.95em;
}
.status-grid .label { color: #81c784; }
.status-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.status {
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 500;
    text-align: center;
    min-width: 70px;
}
.status.active   { background: #2e7d32; color: #fff; }
.status.inactive { background: #c62828; color: #fff; }
.status.stopped  { background: #c62828; color: #fff; }
.status.error    { background: #d32f2f; color: #fff; }
.status.low { background: #2e7d32; color: #fff; }
.status.medium { background: #ef6c00; color: #fff; }
.status.high { background: #c62828; color: #fff; }
.status.throttled { background: #8e0000; color: #fff; }
.status.unavailable { background: #546e7a; color: #fff; }
.pmss-app-toggle {
    border: 0;
    border-radius: 6px;
    padding: 3px 8px;
    color: #fff;
    cursor: pointer;
    font-size: 0.85em;
    line-height: 1.3;
}
.pmss-app-toggle-enable { background: #0277bd; }
.pmss-app-toggle-disable { background: #6d4c41; }
.pmss-app-toggle:disabled {
    cursor: wait;
    opacity: 0.7;
}
.pmss-app-toggle-feedback {
    min-width: 48px;
    color: #b0bec5;
    font-size: 0.85em;
}
.memory-pressure-note {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    background: #111;
    color: #e0e0e0;
    font-size: 0.9em;
}

pre {
    margin: 12px 0 0;
    padding: 10px;
    background: #111;
    border-radius: 6px;
    font-size: 0.85em;
    line-height: 1.4;
    max-height: 360px; /* +20% from 300px */
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.stats-block-base-resources .stats-base-resources-pre {
    max-height: none;
    overflow-y: visible;
}

.resource-summary-block {
    padding: 14px 16px;
}
.resource-summary-strip {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px 18px;
}
.resource-summary-item {
    min-width: 0;
    border-left: 3px solid #4fc3f7;
    padding-left: 10px;
}
.resource-summary-label {
    display: block;
    color: #81c784;
    font-size: 0.82em;
    font-weight: 600;
    text-transform: uppercase;
}
.resource-summary-value {
    display: block;
    color: #fff;
    font-size: 1.02em;
    line-height: 1.35;
    margin-top: 3px;
    overflow-wrap: anywhere;
}
.resource-summary-detail {
    display: block;
    color: #b0bec5;
    font-size: 0.86em;
    line-height: 1.35;
    margin-top: 3px;
}
.resource-summary-chart {
    margin-top: 14px;
}
.resource-summary-chart .traffic-chart {
    margin-top: 0;
}
@media (max-width: 900px) {
    .resource-summary-strip { grid-template-columns: 1fr; }
}

.traffic-chart {
    margin-top: 16px;
    background: #111;
    padding: 12px;
    border-radius: 8px;
    height: 250px;
}

.docker-note {
    font-size: 0.85em;
    color: #ff9800;
    margin-top: 4px;
    font-style: italic;
}
.traffic-ratio {
    font-weight: 600;
}
.traffic-ratio.good { color: #81c784; }
.traffic-ratio.warn { color: #ffb74d; }
.traffic-ratio.bad { color: #ef5350; }
.traffic-ratio.na { color: #b0bec5; }
</style>

<script>
function pmssStatsChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            y: { beginAtZero: true }
        }
    };
}

function pmssStatsToggleApp(button) {
    if (!button) return false;
    var endpoint = button.getAttribute('data-endpoint');
    var action = button.getAttribute('data-action');
    var feedback = button.parentNode ? button.parentNode.querySelector('.pmss-app-toggle-feedback') : null;
    if (!endpoint || !action) return false;

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    if (feedback) feedback.textContent = action === 'start' ? 'Starting...' : 'Disabling...';

    var request = new XMLHttpRequest();
    request.onreadystatechange = function() {
        if (request.readyState !== 4) return;
        if (feedback) feedback.textContent = request.status >= 200 && request.status < 400 ? 'Updated' : 'Retry';
        window.setTimeout(function() {
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.location.reload();
                    return;
                }
            } catch (e) {}
            window.location.reload();
        }, 600);
    };
    request.onerror = function() {
        if (feedback) feedback.textContent = 'Retry';
        button.disabled = false;
        button.removeAttribute('aria-busy');
    };
    request.open('POST', endpoint + '?action=' + encodeURIComponent(action), true);
    request.send('');
    return false;
}
</script>

<div class="stats-container">

  <!-- LEFT: Base resources -->
  <div class="stats-block stats-block-base-resources">
    <h6>Base Resources (current)</h6>
    <pre class="stats-base-resources-pre"><?php
    $uid = null;
    $uidResult = pmssInfoShellExec('/usr/bin/id -u', 'User ID');
    if ($uidResult['error'] !== null) {
        echo $uidResult['error'] . "\n";
    } else {
        $uid = trim($uidResult['output']);
        if ($uid === '' || !is_numeric($uid)) {
            echo "Error: Could not determine user ID.\n";
            $uid = null;
        } else {
            $sliceUnit = 'user-' . $uid . '.slice';
            $statusResult = pmssInfoShellExec('systemctl status ' . escapeshellarg($sliceUnit) . ' 2>&1', 'User slice status');
            if ($statusResult['error'] !== null) {
                echo $statusResult['error'] . "\n";
            } else {
                $output = $statusResult['output'];
                if (!$output) {
                    echo "Failed to retrieve slice status.\n";
                } else {
                    $lines = explode("\n", $output);
                    $cgroupSection = [];
                    $mainSection = [];
                    $inCgroup = false;

                    foreach ($lines as $line) {
                        if (strpos($line, 'CGroup:') === 0) {
                            $inCgroup = true;
                            $cgroupSection[] = $line;
                            continue;
                        }
                        if ($inCgroup && trim($line) && $line[0] === ' ') {
                            $cgroupSection[] = $line;
                        } else {
                            $mainSection[] = $line;
                        }
                    }

                    echo implode("\n", $mainSection);
                    if (!empty($cgroupSection)) {
                        echo "\n" . implode("\n", $cgroupSection);
                    }
                }
            }
        }
    }
    ?></pre>
  </div>

  <!-- RIGHT: Server info -->
  <div class="stats-block">
    <h6><?php echo htmlspecialchars(isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'server'); ?> info</h6>

    <div class="info-line">
        <span class="label">IP:</span>
        <span class="value">
<?php
$ipUrl = 'https://pulsedmedia.com/remote/myip.php';
if (file_exists(__DIR__.'/welcomeMessage.php')) require_once __DIR__.'/welcomeMessage.php';
$ip = function_exists('pmssWelcomeHttpContextCreate')
    ? @file_get_contents($ipUrl, false, pmssWelcomeHttpContextCreate())
    : false;
echo htmlspecialchars($ip !== false ? trim($ip) : 'unknown');
?>
        </span>
    </div>

    <?php
    // === Live systemctl status for WG & OpenVPN ===
    $wgStatus = 'inactive';
    $ovpnStatus = 'inactive';

    $wgResult = pmssInfoShellExec('systemctl is-active wg-quick@wg0 2>/dev/null', 'WireGuard status');
    if ($wgResult['error'] !== null) {
        $wgStatus = 'error';
    } elseif (trim($wgResult['output']) === 'active') {
        $wgStatus = 'active';
    }

    $ovpnResult = pmssInfoShellExec('systemctl is-active openvpn 2>/dev/null', 'OpenVPN status');
    if ($ovpnResult['error'] !== null) {
        $ovpnStatus = 'error';
    } elseif (trim($ovpnResult['output']) === 'active') {
        $ovpnStatus = 'active';
    }

    // === App Status via ps aux | grep (hidepid safe) ===
    $psResult = pmssInfoShellExec('ps aux | grep -E "(rtorrent|qbittorrent-nox|deluged|rclone)" | grep -v grep', 'App status');
    $pmssStatsAppToggles = pmssStatsAppToggleDefinitions();
    if ($psResult['error'] !== null) {
        $apps = array(
            'rTorrent'    => 'error',
            'qBittorrent' => 'error',
            'Deluge'      => 'error',
            'rclone'      => 'error',
        );
    } else {
        $psOutput = $psResult['output'];
        $apps = array(
            'rTorrent'    => (stripos($psOutput, 'rtorrent') !== false) ? 'active' : 'stopped',
            'qBittorrent' => (stripos($psOutput, 'qbittorrent-nox') !== false) ? 'active' : 'stopped',
            'Deluge'      => (stripos($psOutput, 'deluged') !== false) ? 'active' : 'stopped',
            'rclone'      => (stripos($psOutput, 'rclone') !== false) ? 'active' : 'stopped',
        );
    }

    // === Docker Rootless Status ===
    $dockerStatus = 'inactive';
    if ($uid === null) {
        $dockerStatus = 'error';
    } else {
        $dockerSock = "/run/user/$uid/docker.sock";
        if (file_exists($dockerSock)) {
            $dockerStatus = 'active';
        } else {
            $dockerResult = pmssInfoShellExec('docker ps --no-trunc 2>&1', 'Docker status');
            if ($dockerResult['error'] !== null) {
                $dockerStatus = 'error';
            } else {
                $dockerOutput = $dockerResult['output'];
                if (strpos($dockerOutput, 'docker ps') === false && trim($dockerOutput) === '') {
                    $dockerStatus = 'active';
                }
            }
        }
    }
    $apps['Docker'] = $dockerStatus;
    $pmssDockerInactiveNote = pmssStatsDockerInactiveNote($dockerStatus, $pmssDockerEnabledPolicy);
    ?>

    <div class="status-grid">
        <div class="label">Services:</div>
        <div></div>

        <div>WireGuard</div>
        <div><span class="status <?php echo $wgStatus; ?>"><?php echo ucfirst($wgStatus); ?></span></div>

        <div>
            <a href="<?php echo file_exists('openvpn-config.tgz') ? 'openvpn-config.tgz' : '#'; ?>"
               style="color:inherit; text-decoration:none;">
                OpenVPN
            </a>
        </div>
        <div><span class="status <?php echo $ovpnStatus; ?>"><?php echo ucfirst($ovpnStatus); ?></span></div>
    </div>

    <div class="status-grid" style="margin-top:12px;">
        <div class="label">Apps:</div>
        <div></div>
        <?php foreach ($apps as $name => $status): ?>
            <div><?php echo $name; ?></div>
            <div class="status-actions"><span class="status <?php echo $status; ?>"><?php echo ucfirst($status); ?></span><?php echo pmssStatsAppToggleButtonHtmlBuild($name, $pmssStatsAppToggles); ?><?php if ($name === 'Docker' && $pmssDockerInactiveNote !== ''): ?><span class="docker-note"><?php echo htmlspecialchars($pmssDockerInactiveNote, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></div>
        <?php endforeach; ?>
    </div>

    <div class="info-line" style="margin-top:14px;">
        <span class="label">Docker policy:</span>
        <span class="value"><span class="status unavailable">Platform managed</span></span>
    </div>
    <div class="memory-pressure-note">Docker policy changes are handled by platform tooling.</div>

    <pre style="margin-top:16px; font-size:0.9em;">
<?php
$uptimeResult = pmssInfoShellExec('uptime', 'Uptime');
if ($uptimeResult['error'] !== null) {
    echo $uptimeResult['error'] . "\n\n";
} else {
    echo trim($uptimeResult['output']) . "\n\n";
}
echo "Memory usage:\n";

$meminfo = @file_get_contents('/proc/meminfo');
if ($meminfo && preg_match_all('/(\w+):\s+(\d+)/', $meminfo, $m)) {
    $info = array_combine($m[1], $m[2]);
    $fmt = function ($k) use ($info) {
        return isset($info[$k]) ? $info[$k] : 0;
    };
    echo sprintf("Memory total:     %6s MiB\n", round($fmt('MemTotal') / 1024, 0));
    echo sprintf("Memory available: %6s MiB\n", round($fmt('MemAvailable') / 1024, 0));
    echo sprintf("Swap total:       %6s MiB\n", round($fmt('SwapTotal') / 1024, 0));
    echo sprintf("Swap free:        %6s MiB\n", round($fmt('SwapFree') / 1024, 0));

    $psi = @file_get_contents('/proc/pressure/memory');
    if ($psi && preg_match('/some avg10=([0-9.]+) avg60=([0-9.]+) avg300=([0-9.]+)/', $psi, $m)) {
        echo sprintf("Memory pressure (some):  %s / %s / %s\n", $m[1], $m[2], $m[3]);
        if (preg_match('/full avg10=([0-9.]+) avg60=([0-9.]+) avg300=([0-9.]+)/', $psi, $f)) {
            echo sprintf("Memory pressure (full):  %s / %s / %s\n", $f[1], $f[2], $f[3]);
        }
    } else {
        echo "Memory pressure: unavailable\n";
    }
} else {
    echo "Failed to read /proc/meminfo\n";
}
?>
    </pre>
  </div>

</div>

<?php if (!$resourceAvailable): ?>
<div class="stats-block resource-summary-block">
    <h6>Resource snapshot</h6>
    <pre><?php echo htmlspecialchars($resourceMessage, ENT_QUOTES, 'UTF-8'); ?></pre>
</div>
<?php else: ?>
<div class="stats-block resource-summary-block">
    <h6>Resource snapshot</h6>
    <div class="resource-summary-strip">
        <div class="resource-summary-item">
            <span class="resource-summary-label">CPU</span>
            <span class="resource-summary-value"><?php echo htmlspecialchars((string)($cpuDisplay['month'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="resource-summary-detail">Week/day/hour: <?php echo htmlspecialchars((string)($cpuDisplay['week'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($cpuDisplay['day'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($cpuDisplay['hour'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="resource-summary-item">
            <span class="resource-summary-label">Memory</span>
            <span class="resource-summary-value"><?php echo htmlspecialchars($memoryCurrent, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="resource-summary-detail">Process: <?php echo htmlspecialchars($memoryAnon, ENT_QUOTES, 'UTF-8'); ?> / Cache: <?php echo htmlspecialchars($memoryFile, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="resource-summary-detail">RAM-hours: <?php echo htmlspecialchars((string)($ramHoursDisplay['month'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($ramHoursDisplay['week'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($ramHoursDisplay['day'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="resource-summary-detail">Average: <?php echo htmlspecialchars((string)($memoryDisplay['month'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($memoryDisplay['week'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($memoryDisplay['day'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="resource-summary-item">
            <span class="resource-summary-label">Processes</span>
            <span class="resource-summary-value"><?php echo htmlspecialchars($tasksCurrent, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="resource-summary-detail">Current account process count</span>
        </div>
    </div>
    <?php if (count($cpuDailyHours) >= 2): ?>
    <div class="resource-summary-chart">
        <?php
        pmssStatsRenderLineChart('cpuChart', $ioDailyLabels, array(array(
            'label' => 'Daily CPU (hours)',
            'data' => $cpuDailyHours,
            'backgroundColor' => 'rgba(129, 199, 132, 0.2)',
            'borderColor' => 'rgb(129, 199, 132)',
        )));
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
I/O Read (month/week/day/hour): <?php echo htmlspecialchars((string)($ioReadDisplay['month'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($ioReadDisplay['week'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($ioReadDisplay['day'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($ioReadDisplay['hour'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?>
I/O Write (month/week/day/hour): <?php echo htmlspecialchars((string)($ioWriteDisplay['month'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($ioWriteDisplay['week'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($ioWriteDisplay['day'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($ioWriteDisplay['hour'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?>
Past 30 days total I/O operations: <?php echo htmlspecialchars(pmssFormatIoOperationsShort($ioOperationsMonth), ENT_QUOTES, 'UTF-8'); ?>
    </pre>

    <?php if (count($ioDailyLabels) >= 2): ?>
        <?php
        pmssStatsRenderLineChart('ioChart', $ioDailyLabels, array(
            array(
                'label' => 'Daily I/O Read (MiB)',
                'data' => $ioDailyRead,
                'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                'borderColor' => 'rgb(75, 192, 192)',
            ),
            array(
                'label' => 'Daily I/O Write (MiB)',
                'data' => $ioDailyWrite,
                'backgroundColor' => 'rgba(244, 67, 54, 0.2)',
                'borderColor' => 'rgb(244, 67, 54)',
            ),
        ));
        pmssStatsRenderLineChart('iopsChart', $ioDailyLabels, array(array(
            'label' => 'Daily I/O Operations',
            'data' => $ioDailyOperations,
            'backgroundColor' => 'rgba(255, 193, 7, 0.2)',
            'borderColor' => 'rgb(255, 193, 7)',
        )));
        ?>
    <?php else: ?>
        <div class="docker-note">Chart requires 2+ days of data.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="stats-block">
    <h6>Memory pressure</h6>
<?php if (!$pmssMemoryPressure['available']): ?>
    <pre>Memory pressure data is unavailable for this account.</pre>
<?php else: ?>
    <div class="info-line">
        <span class="label">Memory usage:</span>
        <span class="value"><?php echo htmlspecialchars($pmssMemoryPressure['usage_text']); ?></span>
    </div>
    <div class="info-line">
        <span class="label">Memory pressure:</span>
        <span class="value"><span class="status <?php echo strtolower($pmssMemoryPressure['status']); ?>"><?php echo htmlspecialchars($pmssMemoryPressure['status']); ?></span></span>
    </div>
    <div class="info-line">
        <span class="label">Throttle events:</span>
        <span class="value"><?php echo number_format((int) $pmssMemoryPressure['throttle_events']); ?></span>
    </div>
    <div class="info-line">
        <span class="label">Pressure avg10:</span>
        <span class="value"><?php
        echo 'some '.($pmssMemoryPressure['pressure_some_avg10'] !== null ? number_format((float) $pmssMemoryPressure['pressure_some_avg10'], 2, '.', '') : 'n/a')
            .' / full '.($pmssMemoryPressure['pressure_full_avg10'] !== null ? number_format((float) $pmssMemoryPressure['pressure_full_avg10'], 2, '.', '') : 'n/a');
        ?></span>
    </div>
    <?php if ($pmssMemoryPressure['memory_high'] !== null): ?>
    <div class="info-line">
        <span class="label">Throttle threshold:</span>
        <span class="value"><?php echo htmlspecialchars(pmssWebCgroupMemoryStatusFormatBytes($pmssMemoryPressure['memory_high'])); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($pmssMemoryPressure['message'] !== ''): ?>
    <div class="memory-pressure-note"><?php echo htmlspecialchars($pmssMemoryPressure['message']); ?></div>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- Disk Quota -->
<div class="stats-block">
    <h6>Disk usage / Quota</h6>
    <pre><?php
    if (file_exists('../.quota')) {
        $content = file_get_contents('../.quota');
        echo $content;
        $mtime = filemtime('../.quota');
        echo "\nUpdated: " . date('Y-m-d H:i:s', $mtime) . "\n";

        $lines = preg_split('/\r?\n/', trim($content));
        if (count($lines) >= 3 && preg_match('/^\s*\/dev\/\S+\s+(\S+)\s+(\S+)\s+(\S+)/', trim($lines[2]), $m)) {
            echo "\nSummary: Used: {$m[1]}, Soft Limit: {$m[2]}, Hard Limit: {$m[3]}\n";
        }
    } else {
        echo "Quota file not found: ../.quota\n";
    }
    ?></pre>
</div>

<?php
// === Traffic Usage ===
$trafficState = pmssStatsSerializedStateRead('../.trafficData', 'Invalid traffic data format.');
$trafficData = $trafficState['data'];
$trafficTime = $trafficState['time'];
$trafficDataError = $trafficState['error'];
$trafficIngressState = pmssStatsSerializedStateRead('../.trafficDataIngress', 'Invalid inbound traffic data format.');
$trafficIngressData = $trafficIngressState['data'];
$trafficIngressTime = $trafficIngressState['time'];
$trafficIngressError = $trafficIngressState['error'];
$trafficLimitState = function_exists('pmssTrafficLimitStateRead') ? pmssTrafficLimitStateRead('../.trafficLimit', '../.bonusTraffic') : array('limitGiB' => 0, 'bonusGiB' => 0, 'effectiveLimitGiB' => 0);
$trafficOutboundMonth = null;
$trafficInboundMonth = null;

if ($trafficData !== null && isset($trafficData['raw']['month']) && is_numeric($trafficData['raw']['month'])) {
    $trafficOutboundMonth = (float) $trafficData['raw']['month'];
}
if ($trafficIngressData !== null && isset($trafficIngressData['raw']['month']) && is_numeric($trafficIngressData['raw']['month'])) {
    $trafficInboundMonth = (float) $trafficIngressData['raw']['month'];
}
$trafficRatioState = function_exists('pmssTrafficRatioStateBuild') ? pmssTrafficRatioStateBuild($trafficOutboundMonth, $trafficInboundMonth) : array('available' => false);

if ($trafficData === null && $trafficIngressData === null) {
    echo '<div class="stats-block"><h6>Traffic usage</h6><pre>Traffic data not available.</pre></div>';
} else {
    ?>
    <div class="stats-block">
        <h6>Traffic usage</h6>
        <pre style="margin-bottom:12px;">
<?php if ($trafficData !== null): ?>
Traffic consumption at <?php echo date('Y-m-d H:i:s', (int)$trafficTime); ?>:
Week: <?php echo $trafficData['display']['week']; ?>, Day: <?php echo $trafficData['display']['day']; ?>
Past 30 days upload traffic: <?php echo $trafficData['display']['month']; ?>
<?php if (file_exists('../.trafficLimit')): ?>
<?php
$limit = (int) $trafficLimitState['limitGiB'];
if ($limit > 0) {
    $effectiveLimit = (int) $trafficLimitState['effectiveLimitGiB'];
    echo "Traffic limit: " . number_format($effectiveLimit) . " GiB\n";
    if ($trafficLimitState['bonusGiB'] > 0) {
        echo "Bonus traffic: " . number_format($trafficLimitState['bonusGiB']) . " GiB\n";
    }
}
?>
<?php endif; ?>
<?php elseif ($trafficDataError !== null): ?>
<?php echo $trafficDataError . "\n"; ?>
<?php endif; ?>

<?php if ($trafficIngressData !== null): ?>
Inbound traffic at <?php echo date('Y-m-d H:i:s', (int)$trafficIngressTime); ?>:
Past 30 days inbound traffic: <?php echo $trafficIngressData['display']['month']; ?>
<?php elseif ($trafficIngressError !== null): ?>
<?php echo $trafficIngressError . "\n"; ?>
<?php endif; ?>
<?php if (!empty($trafficRatioState['available'])): ?>
Inbound:Outbound ratio (month): <span class="traffic-ratio <?php echo $trafficRatioState['class']; ?>"><?php echo $trafficRatioState['display']; ?></span>
<?php endif; ?>
        </pre>

        <?php if ($trafficData !== null && !empty($trafficData['daily']) && count($trafficData['daily']) >= 2): ?>
            <?php
            $trafficValues = array_map(function ($value) { return round((float)$value, 2); }, array_values($trafficData['daily']));
            pmssStatsRenderLineChart('trafficChart', array_keys($trafficData['daily']), array(array(
                'label' => 'Daily Traffic (MiB)',
                'data' => $trafficValues,
                'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                'borderColor' => 'rgb(75, 192, 192)',
            )));
            ?>
        <?php elseif ($trafficData !== null): ?>
            <div class="docker-note">Chart requires 2+ days of data.</div>
        <?php endif; ?>
    </div>
    <?php
}
