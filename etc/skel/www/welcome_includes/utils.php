<?php

function bonusQuotaDisplay($bonusQuota)
{
    if ($bonusQuota != 0)
        return '<b>BONUS QUOTA:</b> ' . number_format($bonusQuota) . 'GB<br />';
}

function trafficCreateSection($trafficData, $trafficLimit)
{
    if (count($trafficData) == 0)
        return;

    $trafficUsed = round($trafficData['raw']['month']);
    $percent = round((($trafficUsed / 1024) / $trafficLimit) * 100);
    //echo $trafficUsed . '-' . $trafficLimit . '-' . round( $trafficUsed / 1024 );

    $trafficUsed = round($trafficUsed / 1024) . "GiB";

    if ($percent > 100)
        $warning = '<br /><b style="color: red;">OVER TRAFFIC LIMIT WARNING</b><br />You are beyond your traffic limit. Torrent speed reduced.';
    else
        $warning = '';

    $titleText = "{$trafficUsed} / {$trafficLimit}GiB";
    $gauge = createGauge($titleText, $titleText, $percent);

    echo <<<EOF
    <h6>Traffic Info</h6>
    {$gauge}
    {$warning}
    This is rolling past 30 days, <a href="http://blog.pulsedmedia.com/2016/06/traffic-limits-why-and-what-is-rolling-30-days-limit/" target="_blank">read more</a>.
<hr />
EOF;

}

function createGauge($titleText, $footerText, $percent, $percentMax = 0)
{
    if ($percentMax == 0)
        $percentMax = $percent;


    $gaugeBackgroundColor = gaugeColor($percent);

    $template = <<<EOF
    <table style="margin: 0; padding: 0;">
<tr>
    <td id="meter-disk-td" title="{$titleText}">
        <div id="meter-disk-holder">
            <span id="meter-disk-text" style="overflow-x: visible; overflow-y: visible;">{$percent}%</span>
            <div id="meter-disk-value" style="float: left; width: {$percentMax}%; background-color: #{$gaugeBackgroundColor}; visibility: visible; ">&nbsp;</div>

        </div>

    </td>

</tr>
</table>
<span style="font-size: 1.05em; float: right; text-align: right; line-height: 13px;">{$footerText}</span>
EOF;

    return $template;

}

function gaugeColor($percent)
{
    $startColor = array(hexdec('99'), hexdec('E6'), hexdec('99'));
    $endColor = array(hexdec('EE'), hexdec('99'), hexdec('99'));
    $differenceColor = array(
        ($startColor[0] - $endColor[0]),
        ($startColor[1] - $endColor[1]),
        ($startColor[2] - $endColor[2])
    );

    if ($percent > 100)
        $gaugeBackgroundColor = 'FF4040';
    else {
        $offsetColor = array(
            round(($differenceColor[0] * ($percent / 100))),
            round(($differenceColor[1] * ($percent / 100))),
            round(($differenceColor[2] * ($percent / 100)))
        );

        $chosenColor = array(
            $startColor[0] - $offsetColor[0],
            $startColor[1] - $offsetColor[1],
            $startColor[2] - $offsetColor[2]
        );
        /*print_r($chosenColor);  echo "<br />";
        print_r($offsetColor);  echo "<br />";
        print_r($differenceColor);  echo "<br />";
        print_r($startColor);  echo "<br />";
        print_r($endColor);  echo "<br />";*/

        $gaugeBackgroundColor = dechex($chosenColor[0]) . dechex($chosenColor[1]) . dechex($chosenColor[2]);
    }

    return $gaugeBackgroundColor;
}


function quotaCreateSection($quotaInfo, $bonusQuota = 0)
{
    if (count($quotaInfo) == 0)
        return '';

    $freeSpace = $quotaInfo['freeSpace'];
    $hardLimit = $quotaInfo['hardLimit'];
    $totalSpace = $quotaInfo['totalSpace'];
    $usedBytes = $quotaInfo['usedBytes'];

    if (
        $freeSpace == 0 or
        $hardLimit == 0 or
        $totalSpace == 0 or
        $usedBytes == 0
    )
        return '<b>Warning:</b> </i>Quota info is missing, if this persists for more than an hour, contact support.</i>';

    $percent = round(($usedBytes / $totalSpace) * 100, 1);    // Used for text & Color
    $percentFromBurst = round(($usedBytes / $hardLimit) * 100); // Used for drawing it
    if ($percent < 100)
        $percentFromBurst = round(($usedBytes / $totalSpace) * 100, 1);    // Draw to normal space when not bursting

    $readableUsed = filesize2HumanReadable($usedBytes);
    $readableQuota = filesize2HumanReadable($totalSpace);
    $readableBurst = filesize2HumanReadable($hardLimit);

    if ($bonusQuota != 0) {
        $bonusQuotaDisplay = '<br />Bonus disk space: ' . number_format($bonusQuota) . 'GiB';
    } else
        $bonusQuotaDisplay = '';

    if ($percent > 100)
        $warning = <<<EOF
        <br /><b style="color: red;">OVER QUOTA WARNING</b><br />
        If you go over your burst limit rTorrent will not operate and will shutdown automaticly, and will not restart until you are withing quota limit. You are allowed to burst upto 7 days.
EOF;
    else
        $warning = '';

    $titleText = "{$readableUsed}/{$readableQuota}";
    if ($percent > 100)
        $titleText .= " Burst limit: {$readableBurst}";
    $gauge = createGauge($titleText, $titleText . $bonusQuotaDisplay, $percent, $percentFromBurst);


    $html = <<<EOF
<h6>Quota Info</h6>
{$gauge}
{$warning}
<hr />
EOF;
    return $html;
}

function filesize2HumanReadable($bytes, $precision = 2)
{
    $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB');

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

