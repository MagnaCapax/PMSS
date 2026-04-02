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

// Fail-soft: data collection must never abort page rendering. Show notes and
// keep going if commands or files are unavailable.
if (!function_exists('pmssInfoShellExec')) {
    function pmssInfoShellExec($command, $label)
    {
        if (!function_exists('shell_exec')) {
            return array('output' => null, 'error' => $label . ' unavailable: shell_exec disabled');
        }

        $output = shell_exec($command);
        if ($output === null) {
            return array('output' => '', 'error' => null);
        }

        return array('output' => $output, 'error' => null);
    }
}

if (!function_exists('pmssInfoResolveDockerEnabled')) {
    /**
     * Resolve the current dockerEnabled policy for a user.
     */
    function pmssInfoResolveDockerEnabled($username)
    {
        if (!is_string($username) || trim($username) === '') {
            return null;
        }

        $storePath = '/scripts/lib/user/userConfigStore.php';
        if (is_file($storePath)) {
            require_once $storePath;
            if (function_exists('pmssUserDockerEnabled')) {
                return (bool) pmssUserDockerEnabled($username);
            }
        }

        return null;
    }
}

if (file_exists('/scripts/lib/webDockerInactiveNote.php')) {
    require_once '/scripts/lib/webDockerInactiveNote.php';
}

if (file_exists('/scripts/lib/webCgroupMemoryStatus.php')) {
    require_once '/scripts/lib/webCgroupMemoryStatus.php';
}

if (file_exists('/scripts/lib/user/trafficLimit.php')) {
    require_once '/scripts/lib/user/trafficLimit.php';
}

if (!function_exists('pmssInfoSetDockerEnabled')) {
    /**
     * Persist dockerEnabled in the canonical per-user config store.
     */
    function pmssInfoSetDockerEnabled($username, $enabled)
    {
        $result = array(
            'ok' => false,
            'error' => '',
        );

        if (!is_string($username) || trim($username) === '') {
            $result['error'] = 'Unable to update Docker policy: username is unavailable.';
            return $result;
        }

        $storePath = '/scripts/lib/user/userConfigStore.php';
        if (!is_file($storePath)) {
            $result['error'] = 'Unable to update Docker policy: user config store is missing.';
            return $result;
        }

        require_once $storePath;
        if (!class_exists('UserConfigStore')) {
            $result['error'] = 'Unable to update Docker policy: config store class is unavailable.';
            return $result;
        }

        $store = new UserConfigStore();
        $payload = $store->get($username);
        if (!is_array($payload)) {
            $result['error'] = 'Unable to update Docker policy: user config was not found.';
            return $result;
        }

        $payload['dockerEnabled'] = (bool) $enabled;
        if (!$store->persist($username, $payload)) {
            $result['error'] = 'Unable to persist Docker policy. Contact support if this account should be allowed to manage Docker state.';
            return $result;
        }
        $result['ok'] = true;
        return $result;
    }
}

$pmssStatsUsername = '';
$pmssStatsUserResult = pmssInfoShellExec('whoami', 'Username');
if ($pmssStatsUserResult['error'] === null && is_string($pmssStatsUserResult['output'])) {
    $pmssStatsUsername = trim($pmssStatsUserResult['output']);
}

$pmssDockerToggleNotice = array(
    'level' => '',
    'text' => '',
    'output' => '',
);

$pmssDockerEnabledPolicy = pmssInfoResolveDockerEnabled($pmssStatsUsername);
$pmssMemoryPressure = function_exists('pmssWebCgroupMemoryStatusRead')
    ? pmssWebCgroupMemoryStatusRead()
    : array('available' => false, 'status' => 'UNAVAILABLE');

$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($requestMethod === 'POST' && isset($_POST['docker_toggle_state'])) {
    $requestedToggle = strtolower(trim((string) $_POST['docker_toggle_state']));
    if ($requestedToggle === 'enable' || $requestedToggle === 'disable') {
        $targetDockerEnabled = $requestedToggle === 'enable';
        $toggleVerb = $targetDockerEnabled ? 'start' : 'stop';

        $configResult = pmssInfoSetDockerEnabled($pmssStatsUsername, $targetDockerEnabled);
        $dockerRun = array('output' => '', 'error' => null);
        $dockerRunOutput = '';
        $dockerRunRc = null;

        if ($configResult['ok']) {
            $dockerCommand = sprintf(
                'php /scripts/userDocker.php %s %s 2>&1; printf "\\n__PMSS_DOCKER_RC=%%s" "$?"',
                escapeshellarg($pmssStatsUsername),
                escapeshellarg($toggleVerb)
            );
            $dockerRun = pmssInfoShellExec($dockerCommand, 'Docker toggle command');
            $dockerRunOutput = is_string($dockerRun['output']) ? trim($dockerRun['output']) : '';

            if (preg_match('/__PMSS_DOCKER_RC=(\d+)$/', $dockerRunOutput, $rcMatches) === 1) {
                $dockerRunRc = (int) $rcMatches[1];
                $dockerRunOutput = trim((string) preg_replace('/\n__PMSS_DOCKER_RC=\d+$/', '', $dockerRunOutput));
            }
        }

        if (!$configResult['ok']) {
            $pmssDockerToggleNotice['level'] = 'warn';
            $pmssDockerToggleNotice['text'] = $configResult['error'];
        } elseif ($dockerRun['error'] !== null) {
            $pmssDockerToggleNotice['level'] = 'warn';
            $pmssDockerToggleNotice['text'] = 'Docker policy updated, but the runtime command could not be executed.';
        } elseif ($dockerRunRc !== 0) {
            $pmssDockerToggleNotice['level'] = 'warn';
            $pmssDockerToggleNotice['text'] = 'Docker policy updated, but the runtime command reported a non-zero exit status.';
        } else {
            $pmssDockerToggleNotice['level'] = 'ok';
            $pmssDockerToggleNotice['text'] = $targetDockerEnabled
                ? 'Docker is enabled and start was requested.'
                : 'Docker is disabled and stop was requested.';
        }

        if ($dockerRunOutput !== '') {
            $pmssDockerToggleNotice['output'] = $dockerRunOutput;
        }
    } else {
        $pmssDockerToggleNotice['level'] = 'warn';
        $pmssDockerToggleNotice['text'] = 'Ignored unsupported Docker toggle request.';
    }

    $pmssDockerEnabledPolicy = pmssInfoResolveDockerEnabled($pmssStatsUsername);
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
.docker-toggle {
    margin-top: 14px;
    padding: 12px;
    background: #111;
    border-radius: 8px;
}
.docker-toggle-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.docker-toggle-form {
    margin-top: 10px;
    display: flex;
    gap: 10px;
}
.docker-toggle-button {
    border: 0;
    border-radius: 6px;
    padding: 6px 12px;
    color: #fff;
    cursor: pointer;
    font-weight: 600;
}
.docker-toggle-button.enable { background: #2e7d32; }
.docker-toggle-button.disable { background: #c62828; }
.docker-toggle-button[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}
.docker-toggle-message {
    margin-top: 10px;
    font-size: 0.9em;
}
.docker-toggle-message.ok { color: #81c784; }
.docker-toggle-message.warn { color: #ffb74d; }
.traffic-ratio {
    font-weight: 600;
}
.traffic-ratio.good { color: #81c784; }
.traffic-ratio.warn { color: #ffb74d; }
.traffic-ratio.bad { color: #ef5350; }
.traffic-ratio.na { color: #b0bec5; }
</style>

<div class="stats-container">

  <!-- LEFT: Base resources -->
  <div class="stats-block">
    <h6>Base Resources (current)</h6>
    <pre><?php
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
$ipContext = stream_context_create(array(
    'http' => array(
        'timeout'    => 5,
        'user_agent' => 'PMSS-GUI (+https://pulsedmedia.com)'
    )
));
$ip = @file_get_contents($ipUrl, false, $ipContext);
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
    $username = $pmssStatsUsername;

    $psResult = pmssInfoShellExec('ps aux | grep -E "(rtorrent|deluged|rclone)" | grep -v grep', 'App status');
    if ($psResult['error'] !== null) {
        $apps = [
            'rTorrent' => 'error',
            'Deluge'   => 'error',
            'rclone'   => 'error',
        ];
    } else {
        $psOutput = $psResult['output'];
        $apps = [
            'rTorrent' => (stripos($psOutput, 'rtorrent') !== false) ? 'active' : 'stopped',
            'Deluge'   => (stripos($psOutput, 'deluged') !== false) ? 'active' : 'stopped',
            'rclone'   => (stripos($psOutput, 'rclone') !== false) ? 'active' : 'stopped',
        ];
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
    $pmssDockerInactiveNote = function_exists('pmssWebDockerInactiveNote')
        ? pmssWebDockerInactiveNote($dockerStatus, $pmssDockerEnabledPolicy)
        : '';
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
            <div><span class="status <?php echo $status; ?>"><?php echo ucfirst($status); ?></span><?php if ($name === 'Docker' && $pmssDockerInactiveNote !== ''): ?><span class="docker-note"><?php echo htmlspecialchars($pmssDockerInactiveNote, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></div>
        <?php endforeach; ?>
    </div>

    <?php
    $dockerPolicyClass = 'error';
    $dockerPolicyLabel = 'Unknown';
    if ($pmssDockerEnabledPolicy === true) {
        $dockerPolicyClass = 'active';
        $dockerPolicyLabel = 'Enabled';
    } elseif ($pmssDockerEnabledPolicy === false) {
        $dockerPolicyClass = 'inactive';
        $dockerPolicyLabel = 'Disabled';
    }
    ?>
    <div class="docker-toggle">
        <div class="docker-toggle-row">
            <span class="label">Docker policy:</span>
            <span class="status <?php echo $dockerPolicyClass; ?>"><?php echo $dockerPolicyLabel; ?></span>
        </div>
        <form method="post" class="docker-toggle-form">
            <button type="submit" class="docker-toggle-button enable" name="docker_toggle_state" value="enable" <?php echo $username === '' ? 'disabled' : ''; ?>>Enable Docker</button>
            <button type="submit" class="docker-toggle-button disable" name="docker_toggle_state" value="disable" <?php echo $username === '' ? 'disabled' : ''; ?>>Disable Docker</button>
        </form>
        <?php if ($pmssDockerToggleNotice['text'] !== ''): ?>
            <div class="docker-toggle-message <?php echo htmlspecialchars($pmssDockerToggleNotice['level']); ?>"><?php echo htmlspecialchars($pmssDockerToggleNotice['text']); ?></div>
        <?php endif; ?>
        <?php if ($pmssDockerToggleNotice['output'] !== ''): ?>
            <pre style="margin-top:8px;"><?php echo htmlspecialchars($pmssDockerToggleNotice['output']); ?></pre>
        <?php endif; ?>
    </div>

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
$trafficData = null;
$trafficTime = null;
$trafficDataError = null;
$trafficIngressData = null;
$trafficIngressTime = null;
$trafficIngressError = null;

if (file_exists('../.trafficData')) {
    $trafficTime = @filemtime('../.trafficData');
    $trafficData = @unserialize(@file_get_contents('../.trafficData'));
    if (!is_array($trafficData)) {
        $trafficDataError = 'Invalid traffic data format.';
        $trafficData = null;
    }
}

if (file_exists('../.trafficDataIngress')) {
    $trafficIngressTime = @filemtime('../.trafficDataIngress');
    $trafficIngressData = @unserialize(@file_get_contents('../.trafficDataIngress'));
    if (!is_array($trafficIngressData)) {
        $trafficIngressError = 'Invalid inbound traffic data format.';
        $trafficIngressData = null;
    }
}
$trafficLimitState = function_exists('pmssTrafficLimitStateRead')
    ? pmssTrafficLimitStateRead('../.trafficLimit', '../.bonusTraffic')
    : array('limitGiB' => 0, 'bonusGiB' => 0, 'effectiveLimitGiB' => 0);
$trafficRatioDisplay = null;
$trafficRatioClass = '';
$trafficRatioGoodMin = 2.0;
$trafficRatioWarnMin = 1.0;
$trafficOutboundMonth = null;
$trafficInboundMonth = null;

if ($trafficData !== null && isset($trafficData['raw']['month']) && is_numeric($trafficData['raw']['month'])) {
    $trafficOutboundMonth = (float) $trafficData['raw']['month'];
}
if ($trafficIngressData !== null && isset($trafficIngressData['raw']['month']) && is_numeric($trafficIngressData['raw']['month'])) {
    $trafficInboundMonth = (float) $trafficIngressData['raw']['month'];
}
if ($trafficOutboundMonth !== null && $trafficInboundMonth !== null) {
    if ($trafficOutboundMonth > 0) {
        $trafficRatio = $trafficInboundMonth / $trafficOutboundMonth;
        $trafficRatioDisplay = number_format($trafficRatio, 2) . ':1';
        if ($trafficRatio >= $trafficRatioGoodMin) {
            $trafficRatioClass = 'traffic-ratio good';
        } elseif ($trafficRatio >= $trafficRatioWarnMin) {
            $trafficRatioClass = 'traffic-ratio warn';
        } else {
            $trafficRatioClass = 'traffic-ratio bad';
        }
    } else {
        $trafficRatioDisplay = 'N/A';
        $trafficRatioClass = 'traffic-ratio na';
    }
}

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
<?php if ($trafficRatioDisplay !== null): ?>
Inbound:Outbound ratio (month): <span class="<?php echo $trafficRatioClass; ?>"><?php echo $trafficRatioDisplay; ?></span>
<?php endif; ?>
        </pre>

        <?php if ($trafficData !== null && !empty($trafficData['daily']) && count($trafficData['daily']) >= 2): ?>
            <div class="traffic-chart">
                <canvas id="trafficChart" width="600" height="250"></canvas>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (typeof Chart === 'undefined') return;
                const ctx = document.getElementById('trafficChart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_keys($trafficData['daily'])); ?>,
                        datasets: [{
                            label: 'Daily Traffic (MiB)',
                            data: <?php
                                $values = array_values($trafficData['daily']);
                                foreach ($values as &$v) $v = round((float)$v, 2);
                                echo json_encode($values);
                            ?>,
                            fill: true,
                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                            borderColor: 'rgb(75, 192, 192)',
                            tension: 0.4,
                            pointRadius: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            });
            </script>
        <?php elseif ($trafficData !== null): ?>
            <div class="docker-note">Chart requires 2+ days of data.</div>
        <?php endif; ?>
    </div>
    <?php
}

if (!function_exists('pmssFormatBytesShort')) {
    function pmssFormatBytesShort($bytes)
    {
        $bytes = (float)$bytes;
        if (($bytes / 1024 / 1024 / 1024 / 1024) > 1) {
            return round($bytes / 1024 / 1024 / 1024 / 1024, 2).'TiB';
        }
        if (($bytes / 1024 / 1024 / 1024) > 1) {
            return round($bytes / 1024 / 1024 / 1024, 2).'GiB';
        }
        if (($bytes / 1024 / 1024) > 1) {
            return round($bytes / 1024 / 1024, 2).'MiB';
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

// === Resource Usage ===
$resourceData = null;
$resourceTime = null;
$resourceDataError = null;

if (file_exists('../.resourceData')) {
    $resourceTime = @filemtime('../.resourceData');
    $resourceData = @unserialize(@file_get_contents('../.resourceData'));
    if (!is_array($resourceData)) {
        $resourceDataError = 'Invalid resource data format.';
        $resourceData = null;
    }
}

if ($resourceData === null) {
    $message = $resourceDataError !== null ? $resourceDataError : 'Resource data not available.';
    echo '<div class="stats-block"><h6>Resource usage</h6><pre>'.$message.'</pre></div>';
} else {
    $ioReadDisplay = isset($resourceData['io_read']['display']) && is_array($resourceData['io_read']['display'])
        ? $resourceData['io_read']['display']
        : [];
    $ioWriteDisplay = isset($resourceData['io_write']['display']) && is_array($resourceData['io_write']['display'])
        ? $resourceData['io_write']['display']
        : [];
    $cpuDisplay = isset($resourceData['cpu']['display']) && is_array($resourceData['cpu']['display'])
        ? $resourceData['cpu']['display']
        : [];
    $cpuRaw = isset($resourceData['cpu']['raw']) && is_array($resourceData['cpu']['raw'])
        ? $resourceData['cpu']['raw']
        : [];
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
    $memoryDisplay = isset($resourceData['memory']['display']) && is_array($resourceData['memory']['display'])
        ? $resourceData['memory']['display']
        : [];
    $ramHoursDisplay = isset($resourceData['ram_hours']['display']) && is_array($resourceData['ram_hours']['display'])
        ? $resourceData['ram_hours']['display']
        : [];
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

    $ioDailyLabels = [];
    $ioDailyRead = [];
    $ioDailyWrite = [];
    $iopsDailyAverage = [];
    $cpuDailyHours = [];
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
            $iopsDailyAverage[] = round(($readOps + $writeOps) / 86400, 2);
            $cpuDailyHours[] = round(($cpuNanoseconds / 1000000000) / 3600, 4);
        }
    }
    ?>
    <div class="stats-block">
        <h6>Storage I/O</h6>
        <pre style="margin-bottom:12px;">
Resource usage at <?php echo date('Y-m-d H:i:s', (int)$resourceTime); ?>:
I/O Read (month/week/day/hour): <?php echo $ioReadDisplay['month'] ?? 'n/a'; ?> / <?php echo $ioReadDisplay['week'] ?? 'n/a'; ?> / <?php echo $ioReadDisplay['day'] ?? 'n/a'; ?> / <?php echo $ioReadDisplay['hour'] ?? 'n/a'; ?>
I/O Write (month/week/day/hour): <?php echo $ioWriteDisplay['month'] ?? 'n/a'; ?> / <?php echo $ioWriteDisplay['week'] ?? 'n/a'; ?> / <?php echo $ioWriteDisplay['day'] ?? 'n/a'; ?> / <?php echo $ioWriteDisplay['hour'] ?? 'n/a'; ?>
        </pre>

        <?php if (count($ioDailyLabels) >= 2): ?>
            <div class="traffic-chart">
                <canvas id="ioChart" width="600" height="250"></canvas>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (typeof Chart === 'undefined') return;
                const ctx = document.getElementById('ioChart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($ioDailyLabels); ?>,
                        datasets: [{
                            label: 'Daily I/O Read (MiB)',
                            data: <?php echo json_encode($ioDailyRead); ?>,
                            fill: true,
                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                            borderColor: 'rgb(75, 192, 192)',
                            tension: 0.4,
                            pointRadius: 3
                        }, {
                            label: 'Daily I/O Write (MiB)',
                            data: <?php echo json_encode($ioDailyWrite); ?>,
                            fill: true,
                            backgroundColor: 'rgba(244, 67, 54, 0.2)',
                            borderColor: 'rgb(244, 67, 54)',
                            tension: 0.4,
                            pointRadius: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            });
            </script>
            <div class="traffic-chart">
                <canvas id="iopsChart" width="600" height="250"></canvas>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (typeof Chart === 'undefined') return;
                const ctx = document.getElementById('iopsChart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($ioDailyLabels); ?>,
                        datasets: [{
                            label: 'Daily Average IOPS (ops/s)',
                            data: <?php echo json_encode($iopsDailyAverage); ?>,
                            fill: true,
                            backgroundColor: 'rgba(255, 193, 7, 0.2)',
                            borderColor: 'rgb(255, 193, 7)',
                            tension: 0.4,
                            pointRadius: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            });
            </script>
        <?php else: ?>
            <div class="docker-note">Chart requires 2+ days of data.</div>
        <?php endif; ?>
    </div>

    <div class="stats-block">
        <h6>CPU usage</h6>
        <pre>
CPU Time (month/week/day/hour): <?php echo $cpuDisplay['month'] ?? 'n/a'; ?> / <?php echo $cpuDisplay['week'] ?? 'n/a'; ?> / <?php echo $cpuDisplay['day'] ?? 'n/a'; ?> / <?php echo $cpuDisplay['hour'] ?? 'n/a'; ?>
        </pre>
        <?php if (count($cpuDailyHours) >= 2): ?>
            <div class="traffic-chart">
                <canvas id="cpuChart" width="600" height="250"></canvas>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (typeof Chart === 'undefined') return;
                const ctx = document.getElementById('cpuChart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($ioDailyLabels); ?>,
                        datasets: [{
                            label: 'Daily CPU (hours)',
                            data: <?php echo json_encode($cpuDailyHours); ?>,
                            fill: true,
                            backgroundColor: 'rgba(129, 199, 132, 0.2)',
                            borderColor: 'rgb(129, 199, 132)',
                            tension: 0.4,
                            pointRadius: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            });
            </script>
        <?php else: ?>
            <div class="docker-note">Chart requires 2+ days of data.</div>
        <?php endif; ?>
    </div>

    <div class="stats-block">
        <h6>Memory usage</h6>
        <pre>
Current Memory: <?php echo $memoryCurrent; ?>
Process Memory: <?php echo $memoryAnon; ?>
Page Cache: <?php echo $memoryFile; ?>
RAM-Hours (month/week/day): <?php echo $ramHoursDisplay['month'] ?? 'n/a'; ?> / <?php echo $ramHoursDisplay['week'] ?? 'n/a'; ?> / <?php echo $ramHoursDisplay['day'] ?? 'n/a'; ?>
Average Memory (month/week/day): <?php echo $memoryDisplay['month'] ?? 'n/a'; ?> / <?php echo $memoryDisplay['week'] ?? 'n/a'; ?> / <?php echo $memoryDisplay['day'] ?? 'n/a'; ?>
        </pre>
    </div>

    <div class="stats-block">
        <h6>Process count</h6>
        <pre>Current processes: <?php echo $tasksCurrent; ?></pre>
    </div>
    <?php
}
