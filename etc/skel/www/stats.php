<?php
/**
 * PMSS: Frontend Statistics Section on Info Page
 *
 * Live `systemctl` status for WG/OpenVPN, accurate Docker detection,
 * traffic chart (2+ days), taller CGroup block, responsive layout.
 *
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 *
 * @author  Pulsed Media Dev Team
 * @package PMSS
 *
 * #TODO: Hover on status → show systemctl logs
 * #TODO: Click status → attempt restart (if allowed)
 */
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
</style>

<div class="stats-container">

  <!-- LEFT: Base Resources -->
  <div class="stats-block">
    <h6>Base Resources (current)</h6>
    <pre><?php
    $uid = trim(shell_exec('/usr/bin/id -u'));
    if (!$uid || !is_numeric($uid)) {
        echo "Error: Could not determine user ID.\n";
    } else {
        $output = shell_exec('systemctl status user-' . escapeshellarg($uid) . '.slice 2>&1');
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
    ?></pre>
  </div>

  <!-- RIGHT: Server Info -->
  <div class="stats-block">
    <h6><?php echo htmlspecialchars($_SERVER['SERVER_NAME']); ?> info</h6>

    <div class="info-line">
        <span class="label">IP:</span>
        <span class="value"><?php echo htmlspecialchars(file_get_contents('https://pulsedmedia.com/remote/myip.php')); ?></span>
    </div>

    <?php
    // === Live systemctl status for WG & OpenVPN ===
    $wgStatus = 'inactive';
    $ovpnStatus = 'inactive';

    $wgOut = shell_exec('systemctl is-active wg-quick@wg0 2>/dev/null');
    if (trim($wgOut) === 'active') {
        $wgStatus = 'active';
    }

    $ovpnOut = shell_exec('systemctl is-active openvpn 2>/dev/null');
    if (trim($ovpnOut) === 'active') {
        $ovpnStatus = 'active';
    }

    // === App Status via ps aux | grep (hidepid safe) ===
    $username = trim(shell_exec('whoami'));
    $psOutput = shell_exec('ps aux | grep -E "(rtorrent|deluged|rclone)" | grep -v grep');
    $apps = [
        'rTorrent' => (stripos($psOutput, 'rtorrent') !== false) ? 'active' : 'stopped',
        'Deluge'   => (stripos($psOutput, 'deluged') !== false) ? 'active' : 'stopped',
        'rclone'   => (stripos($psOutput, 'rclone') !== false) ? 'active' : 'stopped',
    ];

    // === Docker Rootless Status ===
    $dockerStatus = 'inactive';
    $dockerSock = "/run/user/$uid/docker.sock";
    if (file_exists($dockerSock)) {
        $dockerStatus = 'active';
    } else {
        $dockerOutput = shell_exec('docker ps --no-trunc 2>&1');
        if (strpos($dockerOutput, 'docker ps') === false && trim($dockerOutput) === '') {
            $dockerStatus = 'active';
        }
    }
    $apps['Docker'] = $dockerStatus;
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
            <div><span class="status <?php echo $status; ?>"><?php echo ucfirst($status); ?></span><?php if ($name === 'Docker' && $status === 'inactive'): ?><span class="docker-note"> (Debian 11: User bus restricted. Requires `systemd.unified_cgroup_hierarchy=0` in GRUB. Contact support.)</span><?php endif; ?></div>
        <?php endforeach; ?>
    </div>

    <pre style="margin-top:16px; font-size:0.9em;">
<?php
echo trim(shell_exec('uptime')) . "\n\n";
echo "Memory usage:\n";

$meminfo = @file_get_contents('/proc/meminfo');
if ($meminfo && preg_match_all('/(\w+):\s+(\d+)/', $meminfo, $m)) {
    $info = array_combine($m[1], $m[2]);
    $fmt = fn($k) => $info[$k] ?? 0;
    echo sprintf("Memory Total:     %6s MiB\n", round($fmt('MemTotal') / 1024, 0));
    echo sprintf("Memory Available: %6s MiB\n", round($fmt('MemAvailable') / 1024, 0));
    echo sprintf("Swap Total:       %6s MiB\n", round($fmt('SwapTotal') / 1024, 0));
    echo sprintf("Swap Free:        %6s MiB\n", round($fmt('SwapFree') / 1024, 0));
} else {
    echo "Failed to read /proc/meminfo\n";
}
?>
    </pre>
  </div>

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
if (!file_exists('../.trafficData')) {
    echo '<div class="stats-block"><h6>Traffic usage</h6><pre>Traffic data not available.</pre></div>';
} else {
    $trafficTime = filemtime('../.trafficData');
    $trafficData = unserialize(file_get_contents('../.trafficData'));

    if (!is_array($trafficData)) {
        echo '<div class="stats-block"><h6>Traffic usage</h6><pre>Invalid traffic data format.</pre></div>';
    } else {
        ?>
        <div class="stats-block">
            <h6>Traffic usage</h6>
            <pre style="margin-bottom:12px;">
Traffic consumption at <?php echo date('Y-m-d H:i:s', $trafficTime); ?>:
Week: <?php echo $trafficData['display']['week']; ?>, Day: <?php echo $trafficData['display']['day']; ?>
Past 30 days upload traffic: <?php echo $trafficData['display']['month']; ?>

<?php
if (file_exists('../.trafficLimit')) {
    $limit = (int)trim(file_get_contents('../.trafficLimit'));
    if ($limit > 0) {
        echo "Traffic limit: " . number_format($limit) . " GiB\n";
    }
}
?>
            </pre>

            <?php if (!empty($trafficData['daily']) && count($trafficData['daily']) >= 2): ?>
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
            <?php else: ?>
                <div class="docker-note">Chart requires 2+ days of data.</div>
            <?php endif; ?>
        </div>
        <?php
    }
}
