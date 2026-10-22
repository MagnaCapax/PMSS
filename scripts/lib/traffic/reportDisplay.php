<?php
/** Traffic report JSON and terminal projections; no filesystem reads.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once dirname(__DIR__).'/traffic.php';

function pmssShowTrafficJsonPayload(array $report): array
{
    return [
        'users' => array_map(static function (array $row): array {
            return ['user' => $row['user'], 'display' => pmssShowTrafficDisplayAmounts($row['rawMiB']), 'rates' => $row['rates'], 'inboundMonthMiB' => $row['inboundMonthMiB'], 'inboundOutboundRatio' => $row['inboundRatio'], 'limitMiB' => $row['limitMiB'], 'pctUsed' => ($row['pctUsed'] !== null) ? round($row['pctUsed'], 2) : null, 'overLimit' => $row['overLimit'], 'nearLimit' => $row['nearLimit'], 'rawMiB' => $row['rawMiB']];
        }, $report['rows']),
        'totals' => array_map(static function (float $value): float { return round($value, 2); }, $report['totals']),
        'summary' => $report['summary'],
        'missingStatsUsers' => $report['missingStats'],
    ];
}

function pmssShowTrafficPrintRow(array $row, bool $extended, bool $useColor): void
{
    $isLocalnet = pmssTrafficUserKeyIsLocalnet((string) $row['user']);
    $baseUser = pmssTrafficUserKeyBaseUser((string) $row['user']);
    $label = ($isLocalnet ? "{$baseUser} (L)" : $baseUser).':';
    $display = pmssShowTrafficDisplayAmounts($row['rawMiB']);
    $inboundText = $row['inboundMonthMiB'] !== null ? pmssTrafficFormatAmount((float) $row['inboundMonthMiB']) : '-';
    $ratioText = $row['inboundRatio'] !== null ? sprintf('%.2f', (float) $row['inboundRatio']) : 'n/a';
    if ($extended) {
        [$limitDisplay, $pctDisplay, $statusDisplay, $barDisplay] = pmssShowTrafficLimitDisplays($row, $useColor);
        $format = "%-14s %9s / %9s %4s %s %s IN: %9s R: %5s  Datarates: %10s / %10s / %10s / %10s\n";
        $values = [$label, (string) $display['month'], $limitDisplay, $pctDisplay, $statusDisplay, $barDisplay];
    } else {
        $format = "%-14s %9s / %9s / %9s  IN: %9s R: %5s  Datarates: %5s / %5s / %5s / %5s\n";
        $values = [$label, (string) $display['month'], (string) $display['week'], (string) $display['day']];
    }
    printf($format, ...array_merge($values, [$inboundText, $ratioText], pmssShowTrafficRateColumns($row['rates'], $extended)));
}

function pmssShowTrafficDisplayAmounts(array $rawMiB): array { return array_map('pmssTrafficFormatAmount', array_intersect_key($rawMiB, ['month' => true, 'week' => true, 'day' => true])); }

function pmssShowTrafficRateColumns(array $rates, bool $withUnits): array
{
    return array_map(static function (string $key) use ($rates, $withUnits): string {
        $rate = (float) $rates[$key];
        if (!$withUnits) return sprintf('%.2f', $rate);
        // Extended columns auto-scale from MiB/s to GiB/s at the legacy threshold.
        return sprintf('%.2f%s', $rate >= 1000 ? $rate / 1024 : $rate, $rate >= 1000 ? 'GiB/s' : 'MiB/s');
    }, ['week', 'day', 'hour', '15min']);
}

function pmssShowTrafficLimitDisplays(array $row, bool $useColor): array
{
    $limitDisplay = ($row['limitMiB'] !== null) ? pmssTrafficFormatAmount($row['limitMiB']) : '-';
    $pctDisplayRaw = $row['pctUsed'] !== null ? sprintf('%3d%%', min(999, (int) round($row['pctUsed']))) : 'n/a';
    $statusLabel = $row['pctUsed'] !== null && $row['pctUsed'] >= 80 ? ($row['pctUsed'] >= 100 ? '[OVER]' : '[WARN]') : '';
    $statusDisplay = str_pad($statusLabel, 6, ' ', STR_PAD_RIGHT);
    if ($useColor && $statusLabel !== '') $statusDisplay = ($statusLabel === '[OVER]' ? "\033[1;31m" : "\033[33m").$statusDisplay."\033[0m";
    $filled = ($row['limitMiB'] !== null && $row['pctUsed'] !== null)
        ? (int) floor((max(0.0, min(100.0, (float) $row['pctUsed'])) / 100) * 10)
        : null;
    $barDisplay = $filled === null ? str_repeat(' ', 12) : '['.str_repeat('#', $filled).str_repeat('-', 10 - $filled).']';
    return [$limitDisplay, sprintf('%4s', $pctDisplayRaw), $statusDisplay, $barDisplay];
}

function pmssShowTrafficPrintSummary(array $report, bool $extended, bool $showMissing): void
{
    $missingStats = $report['missingStats'];
    $monthTotalTiB = number_format($report['totals']['monthTiB'], 2);
    $monthTotalLocalTiB = number_format($report['totals']['monthLocalTiB'], 2);
    $line = str_repeat('-', 72)."\n";
    if ($extended) {
        echo $line;
        echo " Total users: ".$report['summary']['totalUsers']."  |  Over limit: {$report['summary']['overLimit']}  |  Near limit (>=80%): {$report['summary']['nearLimit']}\n";
        echo " Month egress: {$monthTotalTiB}TiB  |  Local: {$monthTotalLocalTiB}TiB\n";
        echo " Missing stats: ".count($missingStats)." users".(!empty($missingStats) && !$showMissing ? " (--show-missing to list)" : '')."\n";
    } else {
        echo "* Month Total: {$monthTotalTiB}TiB - Local Total: {$monthTotalLocalTiB}TiB\n";
        if (!empty($missingStats)) {
            echo "* Missing traffic stats for ".count($missingStats)." users (run trafficStats to rebuild).\n";
        }
    }
    // Both layouts share missing-user selection and differ only in the line prefix.
    if ($showMissing && !empty($missingStats)) echo ($extended ? ' ' : '* ')."Missing: ".implode(' ', $missingStats)."\n";
    if ($extended) echo $line;
}

