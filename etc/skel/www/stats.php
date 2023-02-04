<div class="portfolioimg">



<h6>Disk usage / Quota</h6>
<pre>
<?php
 if (file_exists('../.quota')) echo @file_get_contents('../.quota');
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

	if (isset($trafficData['daily']) and count($trafficData['daily'] > 3)) {
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
	new Chart(
		document.getElementById("trafficChart"),
		{
			"type":"line",
			"data":{"labels":[{$displayDataDays}],
			"datasets":[{
				"label":"Traffic usage (MiB) by day","data":[{$displayDataDayConsumption}],
				"fill":true,
				"borderColor":"rgb(75, 192, 192)",
				"lineTension":0.4,
			        "backgroundColor":"rgb(75, 192, 192, 0.6)"
			}]
		},
		"options":{}}
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

echo "Memory Total:     ".formatKB($info['MemTotal']) . "MB\n";
//echo "Memory Free:      ".formatKB($info['MemFree']) . "MB\n";
echo "Memory Available: ".formatKB($info['MemAvailable']) . "MB\n";
echo "Swap Total:       ".formatKB($info['SwapTotal'])."MB\n";
echo "Swap Free:        ".formatKB($info['SwapFree'])."MB\n";

?>
</pre>


</div>
