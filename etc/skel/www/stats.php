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
require_once __DIR__.'/statsHelpers.php';

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

$pmssDockerEnabledPolicy = null;
$pmssMemoryPressure = function_exists('pmssWebCgroupMemoryStatusRead')
    ? pmssWebCgroupMemoryStatusRead()
    : array('available' => false, 'status' => 'UNAVAILABLE');
$resourceState = pmssStatsSerializedStateRead('../.resourceData', 'Invalid resource data format.');
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
    <h6><?php echo pmssCustomerHtmlAttr(isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'server'); ?> info</h6>

    <div class="info-line">
        <span class="label">IP:</span>
        <span class="value">
<?php
$ipUrl = 'https://pulsedmedia.com/remote/myip.php';
if (file_exists(__DIR__.'/welcomeMessage.php')) require_once __DIR__.'/welcomeMessage.php';
$ip = function_exists('pmssWelcomeHttpContextCreate')
    ? @file_get_contents($ipUrl, false, pmssWelcomeHttpContextCreate())
    : false;
echo pmssCustomerHtmlAttr($ip !== false ? trim($ip) : 'unknown');
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
    $pmssStatsAppToggles = pmssCustomerManagedAppDefinitions();
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
            <div class="status-actions"><span class="status <?php echo $status; ?>"><?php echo ucfirst($status); ?></span><?php echo pmssStatsAppToggleButtonHtmlBuild($name, $pmssStatsAppToggles); ?><?php if ($name === 'Docker' && $pmssDockerInactiveNote !== ''): ?><span class="docker-note"><?php echo pmssCustomerHtmlAttr($pmssDockerInactiveNote); ?></span><?php endif; ?></div>
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

<?php pmssStatsRenderResourceBlocks($resourceState); ?>

<div class="stats-block">
    <h6>Memory pressure</h6>
<?php if (!$pmssMemoryPressure['available']): ?>
    <pre>Memory pressure data is unavailable for this account.</pre>
<?php else: ?>
    <div class="info-line">
        <span class="label">Memory usage:</span>
        <span class="value"><?php echo pmssCustomerHtmlAttr($pmssMemoryPressure['usage_text']); ?></span>
    </div>
    <div class="info-line">
        <span class="label">Memory pressure:</span>
        <span class="value"><span class="status <?php echo strtolower($pmssMemoryPressure['status']); ?>"><?php echo pmssCustomerHtmlAttr($pmssMemoryPressure['status']); ?></span></span>
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
        <span class="value"><?php echo pmssCustomerHtmlAttr(pmssWebCgroupMemoryStatusFormatBytes($pmssMemoryPressure['memory_high'])); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($pmssMemoryPressure['message'] !== ''): ?>
    <div class="memory-pressure-note"><?php echo pmssCustomerHtmlAttr($pmssMemoryPressure['message']); ?></div>
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
            pmssStatsRenderLineChart('trafficChart', array_keys($trafficData['daily']), array(
                pmssStatsChartDataset('Daily Traffic (MiB)', $trafficValues, 'rgba(75, 192, 192, 0.2)', 'rgb(75, 192, 192)'),
            ));
            ?>
        <?php elseif ($trafficData !== null): ?>
            <div class="docker-note">Chart requires 2+ days of data.</div>
        <?php endif; ?>
    </div>
    <?php
}
