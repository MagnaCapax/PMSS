<?php
/**
 * Shared traffic report helpers for the showTraffic CLI.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once dirname(__DIR__).'/userLifecycle.php';
require_once dirname(__DIR__).'/runtime.php';
require_once dirname(__DIR__).'/traffic.php';
require_once dirname(__DIR__).'/cli/optionParser.php';
require_once dirname(__DIR__).'/user/trafficLimit.php';

function pmssShowTrafficMain(array $argv): int
{
    $parsed = pmssParseCliTokens($argv);
    $helpExitCode = pmssCliOptionPresent($parsed, 'help') ? 0 : null;
    $asJson = pmssCliOptionPresent($parsed, 'json');
    $showMissing = pmssCliOptionPresent($parsed, 'show-missing');
    $extended = pmssCliOptionPresent($parsed, 'extended');
    $sort = pmssShowTrafficSortOption($parsed, $helpExitCode);
    if ($sort === null) {
        return 2;
    }
    if ($helpExitCode !== null) {
        echo pmssCliHelpUsageOptions('showTraffic.php [--json] [--show-missing] [--extended] [--sort=<mode>]', [
            ['--json', 'Emit JSON instead of human text output.'], ['--show-missing', 'Print missing stats usernames (text mode only).'],
            ['--extended', 'Show limit, percent, and rate units in text output.'], ['--sort=<mode>', 'Sort output by name, month, pct, or rate (default: name).'],
            ['--color', 'Force ANSI colors in extended text output.'], ['--no-color', 'Disable ANSI colors in extended text output.'],
        ]);
        return $helpExitCode;
    }

    if (pmssCliRejectMutuallyExclusiveOptions($parsed, ['color', 'no-color'], "Error: --color and --no-color are mutually exclusive.\n")) return 2;
    $useColor = (!$asJson && $extended) ? pmssShowTrafficUseColor($parsed) : false;

    $statsDir = pmssRuntimeDir().'/trafficStats';
    $users = pmssShowTrafficUsersLoad(dirname(__DIR__, 2).'/listUsers.php', $statsDir);
    if ($users === null) {
        return 1;
    }
    if (empty($users)) {
        echo "No users in this system!\n";
        return 0;
    }

    if (!$asJson) {
        pmssShowTrafficPrintLegend($extended);
    }

    $report = pmssShowTrafficReportBuild($users, $statsDir);
    pmssShowTrafficRowsSort($report['rows'], $sort);
    if ($asJson) {
        return pmssJsonEmitPayload(pmssShowTrafficJsonPayload($report['rows'], $report['dataMonthTotal'], $report['dataMonthTotalLocal'], $report['baseUsers'], $report['baseUsersWithStats'], $report['overLimitCount'], $report['nearLimitCount'], $report['missingStats']), 'Failed to encode traffic report JSON.');
    }

    foreach ($report['rows'] as $row) { pmssShowTrafficPrintRow($row, $extended, $useColor); }
    pmssShowTrafficPrintSummary($extended, $showMissing, $report['missingStats'], count($report['baseUsers']), $report['overLimitCount'], $report['nearLimitCount'], $report['dataMonthTotal'], $report['dataMonthTotalLocal']);
    return 0;
}

function pmssShowTrafficSortOption(array $parsed, ?int &$helpExitCode): ?string
{
    $sortOption = pmssCliOption($parsed, 'sort', null, null);
    if ($helpExitCode === null && $sortOption !== null) {
        if (!is_string($sortOption) || trim($sortOption) === '') {
            fwrite(STDERR, "Error: --sort expects a value.\n");
            return null;
        }
    }
    $sort = is_string($sortOption) ? strtolower(trim($sortOption)) : 'name';
    if ($helpExitCode === null && !in_array($sort, ['name', 'month', 'pct', 'rate'], true)) {
        fwrite(STDERR, "Error: invalid --sort value: {$sort}\n");
        $helpExitCode = 2;
    }
    return $sort;
}

function pmssShowTrafficUseColor(array $parsed): bool
{
    if (pmssCliOptionPresent($parsed, 'color') || pmssCliOptionPresent($parsed, 'no-color')) {
        return pmssCliOptionPresent($parsed, 'color');
    }
    $term = getenv('TERM'); $noColorEnv = getenv('NO_COLOR');
    return pmssStreamIsTty(STDOUT) && !in_array($term, [false, '', 'dumb'], true) && ($noColorEnv === false || $noColorEnv === '');
}

function pmssShowTrafficPrintLegend(bool $extended): void
{
    echo $extended ? "Legend:\n\t USER: Traffic: Data Month / Limit  %  Stat  Bar        IN: Month  Ratio  DATARATES: Week MiB/s / Day MiB/s / Hour MiB/s / 15min MiB/s\n" : "Legend:\n\t USER: Traffic: Data Month / Week / Day  IN: Month  Ratio  DATARATES: Rate Week / Rate Day / Rate Hour / Rate 15min\n";
}

/** @return array<int,string>|null */
function pmssShowTrafficUsersLoad(string $listUsersScript, string $statsDir): ?array
{
    $listUsersResult = pmssListManagedUsersResult($listUsersScript);
    if (($users = pmssListManagedUsersFromResult($listUsersResult)) === null) return null;

    $expanded = [];
    foreach ($users as $user) {
        $expanded[] = $user; is_file($statsDir.'/'.$user.'-localnet') && $expanded[] = $user.'-localnet';
    }
    sort($expanded, SORT_NATURAL | SORT_FLAG_CASE);
    return $expanded;
}

/** @return array<string,mixed> */
function pmssShowTrafficReportBuild(array $users, string $statsDir): array
{
    $report = ['rows' => [], 'dataMonthTotal' => 0.0, 'dataMonthTotalLocal' => 0.0, 'missingStats' => [], 'baseUsers' => [], 'baseUsersWithStats' => [], 'overLimitCount' => 0, 'nearLimitCount' => 0];
    $limitCache = [];
    foreach ($users as $thisUser) {
        $isLocalnet = pmssTrafficUserKeyIsLocalnet($thisUser);
        $baseUser = pmssTrafficUserKeyBaseUser($thisUser);
        $report['baseUsers'][$baseUser] = true;
        $statsPath = pmssTrafficStatsPath($thisUser, $statsDir);
        if (!is_file($statsPath)) {
            $report['missingStats'][] = $thisUser;
            continue;
        }

        $data = pmssReadSerializedArrayFile($statsPath);
        $rawCounters = $data === null ? null : pmssShowTrafficRawCounters($data);
        if ($rawCounters === null) {
            continue;
        }

        $report['dataMonthTotal'] += $rawCounters['month'];
        $isLocalnet && $report['dataMonthTotalLocal'] += $rawCounters['month'];

        $ingressPath = pmssTrafficDataPaths($baseUser)[pmssTrafficDataPathKey($isLocalnet, 'ingress')];
        $inboundMonth = null;
        $ingressData = pmssTrafficReadRootOwnedStatsPayload($ingressPath, $baseUser);
        $ingressData !== null && $inboundMonth = (float) $ingressData['raw']['month'];

        $inboundRatio = ($inboundMonth !== null && $rawCounters['month'] > 0) ? round($inboundMonth / $rawCounters['month'], 2) : null;
        $dataRates = ['week' => round(($rawCounters['week'] / (7 * 24 * 60 * 60)), 2), 'day' => round(($rawCounters['day'] / (24 * 60 * 60)), 2), 'hour' => round(($rawCounters['hour'] / (60 * 60)), 2), '15min' => round(($rawCounters['15min'] / (15 * 60)), 2)];

        if (!array_key_exists($baseUser, $limitCache)) {
            $limitPath = pmssTrafficLimitPath($baseUser);
            $parsedLimit = pmssTrafficLimitReadGiBFile($limitPath);
            $limitCache[$baseUser] = $parsedLimit > 0 ? $parsedLimit : null;
        }

        $limitMiB = ($limitCache[$baseUser] !== null) ? ($limitCache[$baseUser] * 1024) : null;
        $pctUsed = null;
        $overLimit = false;
        $nearLimit = false;
        if ($limitMiB !== null && $limitMiB > 0) {
            $pctUsed = ($rawCounters['month'] / $limitMiB) * 100;
            $overLimit = ($pctUsed >= 100);
            $nearLimit = (!$overLimit && $pctUsed >= 80);
        }
        if (!$isLocalnet) {
            $report['baseUsersWithStats'][$baseUser] = true;
            if ($overLimit) $report['overLimitCount']++; elseif ($nearLimit) $report['nearLimitCount']++;
        }

        $report['rows'][] = ['user' => $thisUser, 'rates' => $dataRates, 'inboundMonthMiB' => $inboundMonth, 'inboundRatio' => $inboundRatio, 'limitMiB' => $limitMiB, 'pctUsed' => $pctUsed, 'overLimit' => $overLimit, 'nearLimit' => $nearLimit, 'rawMiB' => $data['raw']];
    }

    sort($report['missingStats'], SORT_NATURAL | SORT_FLAG_CASE);
    return $report;
}

function pmssShowTrafficRowsSort(array &$rows, string $sort): void
{
    if ($sort === 'name') return;
    usort($rows, static function (array $a, array $b) use ($sort): int {
        if ($sort === 'month') $cmp = $b['rawMiB']['month'] <=> $a['rawMiB']['month'];
        elseif ($sort === 'pct') $cmp = (($b['pctUsed'] === null) ? -1 : $b['pctUsed']) <=> (($a['pctUsed'] === null) ? -1 : $a['pctUsed']);
        else $cmp = $b['rates']['15min'] <=> $a['rates']['15min'];
        return $cmp !== 0 ? $cmp : strnatcasecmp($a['user'], $b['user']);
    });
}

function pmssShowTrafficJsonPayload(array $rows, float $dataMonthTotal, float $dataMonthTotalLocal, array $baseUsers, array $baseUsersWithStats, int $overLimitCount, int $nearLimitCount, array $missingStats): array
{
    return [
        'users' => array_map('pmssShowTrafficJsonRow', $rows),
        'totals' => ['monthMiB' => round($dataMonthTotal, 2), 'monthLocalMiB' => round($dataMonthTotalLocal, 2), 'monthTiB' => round(($dataMonthTotal / 1024 / 1024), 2), 'monthLocalTiB' => round(($dataMonthTotalLocal / 1024 / 1024), 2)],
        'summary' => ['totalUsers' => count($baseUsers), 'usersWithStats' => count($baseUsersWithStats), 'overLimit' => $overLimitCount, 'nearLimit' => $nearLimitCount, 'missingStats' => count($missingStats)],
        'missingStatsUsers' => $missingStats,
    ];
}

function pmssShowTrafficJsonRow(array $row): array
{
    return ['user' => $row['user'], 'display' => pmssShowTrafficDisplayAmounts($row['rawMiB']), 'rates' => $row['rates'], 'inboundMonthMiB' => $row['inboundMonthMiB'], 'inboundOutboundRatio' => $row['inboundRatio'], 'limitMiB' => $row['limitMiB'], 'pctUsed' => ($row['pctUsed'] !== null) ? round($row['pctUsed'], 2) : null, 'overLimit' => $row['overLimit'], 'nearLimit' => $row['nearLimit'], 'rawMiB' => $row['rawMiB']];
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
        $values = array_merge([$label, (string) $display['month'], $limitDisplay, $pctDisplay, $statusDisplay, $barDisplay, $inboundText, $ratioText], pmssShowTrafficRateColumns($row['rates'], true));
    } else {
        $format = "%-14s %9s / %9s / %9s  IN: %9s R: %5s  Datarates: %5s / %5s / %5s / %5s\n";
        $values = array_merge([$label, (string) $display['month'], (string) $display['week'], (string) $display['day'], $inboundText, $ratioText], pmssShowTrafficRateColumns($row['rates'], false));
    }
    printf($format, ...$values);
}

function pmssShowTrafficDisplayAmounts(array $rawMiB): array { return array_map('pmssTrafficFormatAmount', array_intersect_key($rawMiB, ['month' => true, 'week' => true, 'day' => true])); }

function pmssShowTrafficRateColumns(array $rates, bool $withUnits): array
{
    return array_map(static function (string $key) use ($rates, $withUnits): string { $rate = (float) $rates[$key]; return $withUnits ? pmssShowTrafficFormatRateDisplay($rate) : sprintf('%.2f', $rate); }, ['week', 'day', 'hour', '15min']);
}

function pmssShowTrafficLimitDisplays(array $row, bool $useColor): array
{
    $limitDisplay = ($row['limitMiB'] !== null) ? pmssTrafficFormatAmount($row['limitMiB']) : '-';
    $pctDisplayRaw = 'n/a';
    $statusLabel = '';
    if ($row['pctUsed'] !== null) {
        $pctDisplayRaw = sprintf('%3d%%', min(999, (int) round($row['pctUsed'])));
        $statusLabel = $row['pctUsed'] >= 100 ? '[OVER]' : ($row['pctUsed'] >= 80 ? '[WARN]' : '');
    }
    $statusDisplay = str_pad($statusLabel, 6, ' ', STR_PAD_RIGHT);
    if ($useColor && $statusLabel !== '') $statusDisplay = (($row['pctUsed'] !== null && $row['pctUsed'] >= 100) ? "\033[1;31m" : "\033[33m").$statusDisplay."\033[0m";
    $filled = ($row['limitMiB'] !== null && $row['pctUsed'] !== null)
        ? (int) floor((max(0.0, min(100.0, (float) $row['pctUsed'])) / 100) * 10)
        : null;
    $barDisplay = $filled === null ? str_repeat(' ', 12) : '['.str_repeat('#', $filled).str_repeat('-', 10 - $filled).']';
    return [$limitDisplay, sprintf('%4s', $pctDisplayRaw), $statusDisplay, $barDisplay];
}

function pmssShowTrafficPrintSummary(bool $extended, bool $showMissing, array $missingStats, int $baseUserCount, int $overLimitCount, int $nearLimitCount, float $dataMonthTotal, float $dataMonthTotalLocal): void
{
    $monthTotalTiB = number_format(($dataMonthTotal / 1024 / 1024), 2);
    $monthTotalLocalTiB = number_format(($dataMonthTotalLocal / 1024 / 1024), 2);
    if (!$extended) {
        echo "* Month Total: {$monthTotalTiB}TiB - Local Total: {$monthTotalLocalTiB}TiB\n";
        if (!empty($missingStats)) {
            echo "* Missing traffic stats for ".count($missingStats)." users (run trafficStats to rebuild).\n";
            if ($showMissing) echo "* Missing: ".implode(' ', $missingStats)."\n";
        }
        return;
    }

    $line = str_repeat('-', 72);
    $missingLine = " Missing stats: ".count($missingStats)." users";
    if (!empty($missingStats) && !$showMissing) $missingLine .= " (--show-missing to list)";
    echo $line."\n";
    echo " Total users: {$baseUserCount}  |  Over limit: {$overLimitCount}  |  Near limit (>=80%): {$nearLimitCount}\n";
    echo " Month egress: {$monthTotalTiB}TiB  |  Local: {$monthTotalLocalTiB}TiB\n";
    echo $missingLine."\n";
    if ($showMissing && !empty($missingStats)) echo " Missing: ".implode(' ', $missingStats)."\n";
    echo $line."\n";
}

/** @return array{month:float,week:float,day:float,hour:float,15min:float}|null */
function pmssShowTrafficRawCounters(array $payload): ?array
{
    if (!isset($payload['raw']) || !is_array($payload['raw']) || empty($payload['raw']['month'])) {
        return null;
    }

    $counters = [];
    foreach (['month', 'week', 'day', 'hour', '15min'] as $key) {
        if (!array_key_exists($key, $payload['raw']) || !is_numeric($payload['raw'][$key])) return null;
        $counters[$key] = (float) $payload['raw'][$key];
    }

    return $counters;
}

/** Format a data rate in MiB/s, auto-scaling to GiB/s. */
function pmssShowTrafficFormatRateDisplay(float $rateMiB): string { return sprintf('%.2f%s', $rateMiB >= 1000 ? $rateMiB / 1024 : $rateMiB, $rateMiB >= 1000 ? 'GiB/s' : 'MiB/s'); }
