<?php
/** Customer Info statistics: statsTraffic. Loaded by statsHelpers.php. */

/** Format traffic counters stored as MiB without requiring operator-side code. */
function pmssStatsTrafficAmountFormat($valueMiB): string
{
    $valueMiB = max(0.0, (float) $valueMiB);
    foreach (array(1048576 => 'TiB', 1024 => 'GiB') as $divisor => $unit) {
        if ($valueMiB > $divisor) {
            return round($valueMiB / $divisor, 2).$unit;
        }
    }

    return round($valueMiB, 2).'MiB';
}

/** Return a display value from persisted traffic state, deriving it from raw MiB when absent. */
function pmssStatsTrafficDisplayValue(array $trafficData, string $period): string
{
    if (is_array($trafficData['display'] ?? null)
        && isset($trafficData['display'][$period]) && trim((string) $trafficData['display'][$period]) !== '') {
        return (string) $trafficData['display'][$period];
    }

    if (is_array($trafficData['raw'] ?? null) && is_numeric($trafficData['raw'][$period] ?? null)) {
        return pmssStatsTrafficAmountFormat($trafficData['raw'][$period]);
    }

    return 'n/a';
}

/** Render the outbound/inbound traffic block from customer-readable snapshots. */
function pmssStatsRenderTrafficUsageBlock(): void
{
    $trafficState = pmssStatsSerializedStateRead('../.trafficData', 'Invalid traffic data format.');
    $trafficIngressState = pmssStatsSerializedStateRead('../.trafficDataIngress', 'Invalid inbound traffic data format.');
    $trafficData = $trafficState['data'];
    $trafficIngressData = $trafficIngressState['data'];
    $trafficLimitState = function_exists('pmssTrafficLimitStateRead')
        ? pmssTrafficLimitStateRead('../.trafficLimit', '../.bonusTraffic')
        : array('limitGiB' => 0, 'bonusGiB' => 0, 'effectiveLimitGiB' => 0);
    $trafficOutboundMonth = is_numeric($trafficData['raw']['month'] ?? null) ? (float) $trafficData['raw']['month'] : null;
    $trafficInboundMonth = is_numeric($trafficIngressData['raw']['month'] ?? null) ? (float) $trafficIngressData['raw']['month'] : null;
    $trafficRatioState = function_exists('pmssTrafficRatioStateBuild') ? pmssTrafficRatioStateBuild($trafficOutboundMonth, $trafficInboundMonth) : array('available' => false);

    if ($trafficData === null && $trafficIngressData === null) {
        echo '<div class="stats-block"><h6>Traffic usage</h6><pre>Traffic data not available.</pre></div>';
        return;
    }

    echo '<div class="stats-block"><h6>Traffic usage</h6><pre style="margin-bottom:12px;">'."\n";
    if ($trafficData !== null) {
        echo 'Traffic consumption at '.date('Y-m-d H:i:s', (int) $trafficState['time']).":\n";
        echo 'Week: '.pmssStatsTrafficDisplayValue($trafficData, 'week').', Day: '.pmssStatsTrafficDisplayValue($trafficData, 'day')."\n";
        echo 'Past 30 days upload traffic: '.pmssStatsTrafficDisplayValue($trafficData, 'month')."\n";
        if (file_exists('../.trafficLimit') && (int) $trafficLimitState['limitGiB'] > 0) {
            echo 'Traffic limit: '.number_format((int) $trafficLimitState['effectiveLimitGiB'])." GiB\n";
            if ($trafficLimitState['bonusGiB'] > 0) {
                echo 'Bonus traffic: '.number_format((int) $trafficLimitState['bonusGiB'])." GiB\n";
            }
        }
    } elseif ($trafficState['error'] !== null) {
        echo $trafficState['error']."\n";
    }

    echo "\n";
    if ($trafficIngressData !== null) {
        echo 'Inbound traffic at '.date('Y-m-d H:i:s', (int) $trafficIngressState['time']).":\n";
        echo 'Past 30 days inbound traffic: '.pmssStatsTrafficDisplayValue($trafficIngressData, 'month')."\n";
    } elseif ($trafficIngressState['error'] !== null) {
        echo $trafficIngressState['error']."\n";
    }
    if (!empty($trafficRatioState['available'])) {
        echo 'Inbound:Outbound ratio (month): <span class="traffic-ratio '.$trafficRatioState['class'].'">'.$trafficRatioState['display']."</span>\n";
    }
    echo "</pre>\n";

    if ($trafficData !== null && !empty($trafficData['daily']) && count($trafficData['daily']) >= 2) {
        $trafficValues = array_map(static function ($value) { return round((float) $value, 2); }, array_values($trafficData['daily']));
        pmssStatsRenderLineChart('trafficChart', array_keys($trafficData['daily']), array(
            pmssStatsChartDataset('Daily Traffic (MiB)', $trafficValues, 'rgba(75, 192, 192, 0.2)', 'rgb(75, 192, 192)'),
        ));
    } elseif ($trafficData !== null) {
        echo '<div class="docker-note">Chart requires 2+ days of data.</div>';
    }
    echo "</div>\n";
}
