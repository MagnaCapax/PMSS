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

 

<h6>Disk usage / Quota</h6>
<pre>
<?php
 if (file_exists('../.quota')) echo @file_get_contents('../.quota');
?>
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
