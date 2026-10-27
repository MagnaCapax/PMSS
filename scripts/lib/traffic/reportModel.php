<?php
/** Traffic report acquisition, row selection, and summaries.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once dirname(__DIR__).'/userLifecycle.php';
require_once dirname(__DIR__).'/traffic.php';
require_once dirname(__DIR__).'/user/trafficLimit.php';

/** @return array<int,string>|null */
function pmssShowTrafficUsersLoad(string $listUsersScript, string $statsDir): ?array
{
    if (($users = pmssListManagedUsersFromResult(pmssListManagedUsersResult($listUsersScript))) === null) return null;

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
    $report = ['rows' => [], 'missingStats' => []];
    $limitCache = [];
    foreach ($users as $thisUser) {
        $isLocalnet = pmssTrafficUserKeyIsLocalnet($thisUser);
        $baseUser = pmssTrafficUserKeyBaseUser($thisUser);
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

        $ingressPath = pmssTrafficDataPaths($baseUser)[pmssTrafficDataPathKey($isLocalnet, 'ingress')];
        $ingressData = pmssTrafficReadRootOwnedStatsPayload($ingressPath, $baseUser);
        $inboundMonth = $ingressData !== null ? pmssTrafficRawMonthMiB($ingressData) : null;

        if (!array_key_exists($baseUser, $limitCache)) {
            $parsedLimit = pmssTrafficLimitReadGiBFile(pmssTrafficLimitPath($baseUser));
            $limitCache[$baseUser] = $parsedLimit > 0 ? $parsedLimit : null;
        }

        $limitMiB = ($limitCache[$baseUser] !== null) ? ($limitCache[$baseUser] * 1024) : null;
        $pctUsed = $limitMiB !== null ? ($rawCounters['month'] / $limitMiB) * 100 : null;
        $report['rows'][] = ['user' => $thisUser,
            'rates' => ['week' => round(($rawCounters['week'] / (7 * 24 * 60 * 60)), 2), 'day' => round(($rawCounters['day'] / (24 * 60 * 60)), 2), 'hour' => round(($rawCounters['hour'] / (60 * 60)), 2), '15min' => round(($rawCounters['15min'] / (15 * 60)), 2)],
            'inboundMonthMiB' => $inboundMonth, 'inboundRatio' => ($inboundMonth !== null && $rawCounters['month'] > 0) ? round($inboundMonth / $rawCounters['month'], 2) : null,
            'limitMiB' => $limitMiB, 'pctUsed' => $pctUsed, 'overLimit' => $pctUsed !== null && $pctUsed >= 100, 'nearLimit' => $pctUsed !== null && $pctUsed >= 80 && $pctUsed < 100, 'rawMiB' => $data['raw']];
    }

    sort($report['missingStats'], SORT_NATURAL | SORT_FLAG_CASE);
    // Derive summaries before sorting: floating-point addition keeps the input order.
    $external = array_filter($report['rows'], static function (array $row): bool { return !pmssTrafficUserKeyIsLocalnet($row['user']); });
    $months = array_map(static function (array $row): float { return (float) $row['rawMiB']['month']; }, $report['rows']);
    $monthTotal = (float) array_sum($months);
    $monthLocal = (float) array_sum(array_diff_key($months, $external));
    $report['totals'] = ['monthMiB' => $monthTotal, 'monthLocalMiB' => $monthLocal, 'monthTiB' => $monthTotal / 1024 / 1024, 'monthLocalTiB' => $monthLocal / 1024 / 1024];
    // Count distinct accounts with external stats, but retain per-row limit counts for duplicates.
    $report['summary'] = ['totalUsers' => count(array_unique(array_map('pmssTrafficUserKeyBaseUser', $users))),
        'usersWithStats' => count(array_unique(array_column($external, 'user'))), 'overLimit' => (int) array_sum(array_column($external, 'overLimit')),
        'nearLimit' => (int) array_sum(array_column($external, 'nearLimit')), 'missingStats' => count($report['missingStats'])];
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

/** @return array{month:float,week:float,day:float,hour:float,'15min':float}|null */
function pmssShowTrafficRawCounters(array $payload): ?array
{
    if (!isset($payload['raw']) || !is_array($payload['raw']) || empty($payload['raw']['month'])) {
        return null;
    }

    $counters = [];
    foreach (['month', 'week', 'day', 'hour', '15min'] as $key) {
        // Serialized numeric values can be non-finite, including after string conversion.
        if (($counters[$key] = pmssFiniteFloat($payload['raw'][$key] ?? null)) === null) return null;
    }

    return $counters;
}
