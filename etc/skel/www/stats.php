<?php
/**
* PMSS: Frontend Statistics Section on Info page
* 
* Show statistics on info page, quota, IP, traffic consumption etc.
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
*
**/
?>

<div class="portfolioimg">

<pre>
Base resources (current):
<?php
// Show a brief slice status line similar to production snippet
@passthru('systemctl status user-$("/usr/bin/id" -u).slice | grep -A3 -m1 "IP: "');
?>
</pre>

<h6>Disk usage / Quota</h6>
<pre>
<?php
 // Show raw quota output and a visual gauge based on parsed values
 if (file_exists('../.quota')) {
     echo @file_get_contents('../.quota');
     $quotaMtime = @filemtime('../.quota');
     if ($quotaMtime) {
         echo "\nUpdated: ".date('Y-m-d H:i:s', $quotaMtime)."\n";
     }
     // Parse the human output to compute used/soft/hard and render a gauge
     @require_once __DIR__.'/_lib/gauge.php';
     $quotaRaw = @file_get_contents('../.quota');
     $lines = @preg_split('/\r?\n/', (string)$quotaRaw);
     if (is_array($lines) && count($lines) >= 3) {
         $line = trim($lines[2]);
         // Some distros add a by-uuid/mapper line; adjust if detected
         if (strpos($line, 'disk/by-uuid') !== false || strpos($line, '/mapper/') !== false) {
             $line = trim($lines[3] ?? '');
         }
         if ($line !== '') {
             $line = preg_replace('/([\s]--)/', '', $line);
             $parts = preg_split('/\s+/', $line);
             if (is_array($parts) && count($parts) >= 4) {
                 $toBytes = function (string $s): int {
                     $s = str_replace('*', '', $s);
                     $u = strtoupper(substr($s, -1));
                     $n = (int)$s;
                     if ($u === 'K') $n *= 1024;
                     elseif ($u === 'M') $n *= 1024*1024;
                     elseif ($u === 'G') $n *= 1024*1024*1024;
                     elseif ($u === 'T') $n *= 1024*1024*1024*1024;
                     return $n;
                 };
                 // Columns: filesystem, space, quota (soft), limit (hard), ...
                 $usedBytes  = $toBytes($parts[1]);
                 $softBytes  = $toBytes($parts[2]);
                 $hardBytes  = $toBytes($parts[3]);
                 $percent    = $softBytes > 0 ? round(($usedBytes / $softBytes) * 100, 1) : 0;
                 $percentMax = $hardBytes > 0 ? round(($usedBytes / $hardBytes) * 100, 1) : $percent;
                 if ($percent < 100) $percentMax = $percent; // draw against soft until bursting
                 // Render gauge via shared helper
                 $readable = function (int $bytes): string {
                     $units=['B','KiB','MiB','GiB','TiB']; $i=0; $v=max($bytes,0);
                     while ($v>=1024 && $i<count($units)-1) { $v/=1024; $i++; }
                     return round($v,2).' '.$units[$i];
                 };
                 $titleText = $readable($usedBytes).' / '.$readable($softBytes);
                 if ($percent > 100) $titleText .= ' Burst: '.$readable($hardBytes);
                 echo "\n";
                 echo createGauge($titleText, $titleText, $percent, $percentMax);
                 // Explicit burst indicator when above soft limit
                 if ($percent > 100) {
                     echo '<br /><span style="float:right; color:#dc3545; font-size:0.95em;">Bursting — limit: '.htmlspecialchars($readable($hardBytes), ENT_QUOTES, 'UTF-8').'</span>';
                 }
                 echo "\n\n";
             }
         }
     }
 }
// echo `cd ..; du -hcd1 .; cd -`;
?>
 </pre>
 
 <?php
 if (file_exists('../.trafficData')) {
    $trafficTime = filemtime('../.trafficData');
    $trafficData = @unserialize( @file_get_contents('../.trafficData') );
    
    
    if (is_array($trafficData)) {
        echo "<h6>Traffic usage</h6><pre>\n\nTraffic consumption at " . date('Y-m-d H:i:s', $trafficTime) . ":\nWeek {$trafficData['display']['week']}, Day: {$trafficData['display']['day']}\n";
        echo "Past 30 days upload traffic: {$trafficData['display']['month']}\n\n";
    }
    
    if ( file_exists('../.trafficLimit') ) {
        $trafficLimit = (int) trim( file_get_contents('../.trafficLimit') );
        if ($trafficLimit > 0) echo "Traffic limit: " . number_format($trafficLimit) . " GiB\n";
    }

	if (isset($trafficData['daily']) && is_array($trafficData['daily']) && count($trafficData['daily']) > 3) {
		echo '<canvas id="trafficChart" width="500px" height="200px"></canvas>';
		$displayDataDays = array();
		$displayDataDayConsumption = array();
		foreach( $trafficData['daily'] AS $thisDay => $thisDayData ) {
			$displayDataDays[] = $thisDay;
			$displayDataDayConsumption[] = round($thisDayData, 2);
		}
		$displayDataDays = '"' . implode('", "', $displayDataDays) . '"';
		$displayDataDayConsumption = implode(',', $displayDataDayConsumption);


echo <<<EOF
<script>
window.onload = function () {
  if (typeof Chart === 'undefined') return;
  new Chart(
    document.getElementById('trafficChart'),
    {
      type: 'line',
      data: {
        labels: [{$displayDataDays}],
        datasets: [{
          label: 'Traffic usage (MiB) by day',
          data: [{$displayDataDayConsumption}],
          fill: true,
          borderColor: 'rgb(75, 192, 192)',
          tension: 0.4,
          backgroundColor: 'rgba(75, 192, 192, 0.6)'
        }]
      },
      options: {}
    }
  );
};
</script>
EOF;

	}
 
 }
?>
</pre>

</div>
<div class="portfoliodesc">

<h6> <?=$_SERVER['SERVER_NAME'];?> info </h6>
<b>IP:</b> <?= @file_get_contents('https://pulsedmedia.com/remote/myip.php'); ?>
<?php
// Service statuses (WireGuard/OpenVPN only)
$svcStatus = function ($service, $configPath = null) {
    if ($configPath !== null && !file_exists($configPath)) return 'not configured';
    @exec('systemctl is-active --quiet '.escapeshellarg($service), $o, $rc);
    if ($rc === 0) return 'active';
    @exec('systemctl is-enabled --quiet '.escapeshellarg($service), $o, $en);
    return ($en !== 0) ? 'disabled' : 'inactive';
};
$wg   = $svcStatus('wg-quick@wg0','/etc/wireguard/wg0.conf');
$ovpn = $svcStatus('openvpn@openvpn','/etc/openvpn/openvpn.conf');
echo '<br /><b>Services:</b> ';
echo 'WireGuard: '.htmlspecialchars($wg,ENT_QUOTES,'UTF-8').', ';
$ovpnLabel = 'OpenVPN: '.htmlspecialchars($ovpn,ENT_QUOTES,'UTF-8');
if (file_exists('openvpn-config.tgz')) { $ovpnLabel = '<a href="openvpn-config.tgz">OpenVPN: '.htmlspecialchars($ovpn,ENT_QUOTES,'UTF-8').'</a>'; }
echo $ovpnLabel;

// Per-user processes: derive username from web root path if available (e.g., /home/<user>/www)
$uname = '';
if (function_exists('posix_geteuid')) {
    $who = @posix_getpwuid(@posix_geteuid());
    $uname = is_array($who) ? ($who['name'] ?? '') : '';
}
if ($uname === '') {
    $webRoot = realpath(__DIR__);
    if ($webRoot !== false) {
        $home = dirname($webRoot);
        $candidate = basename($home);
        if ($candidate && $candidate !== 'www') $uname = $candidate;
    }
}
$isRunning = function ($proc, $user) {
    if ($user === '') return false;
    $cmd = 'pgrep -x '.escapeshellarg($proc).' -u '.escapeshellarg($user).' >/dev/null 2>&1';
    @exec($cmd, $o, $rc);
    return $rc === 0;
};
$rt = $isRunning('rtorrent', $uname) ? 'running' : 'stopped';
$dg = $isRunning('deluged', $uname) ? 'running' : 'stopped';
$rc = $isRunning('rclone', $uname) ? 'running' : 'stopped';
echo '<br /><b>Apps:</b> rTorrent: <span style="color:#'.($rt==='running'?'28a745':'dc3545').';">'.$rt.'</span>, ';
echo 'Deluge: <span style="color:#'.($dg==='running'?'28a745':'dc3545').';">'.$dg.'</span>, ';
echo 'rclone: <span style="color:#'.($rc==='running'?'28a745':'dc3545').';">'.$rc.'</span>';
?>
<pre>
<?=passthru('uptime');?>

Memory usage:
<?php
function formatKB($size, $precision = 2) {
    return round($size/1024, 0);
}
$contents = file_get_contents('/proc/meminfo');
preg_match_all('/(\w+):\s+(\d+)\s/', $contents, $matches);
$info = array_combine($matches[1], $matches[2]);

echo "Memory Total:     ".formatKB($info['MemTotal']) . "MiB\n";
//echo "Memory Free:      ".formatKB($info['MemFree']) . "MiB\n";
echo "Memory Available: ".formatKB($info['MemAvailable']) . "MiB\n";
echo "Swap Total:       ".formatKB($info['SwapTotal'])."MiB\n";
echo "Swap Free:        ".formatKB($info['SwapFree'])."MiB\n";

?>
</pre>


</div>
