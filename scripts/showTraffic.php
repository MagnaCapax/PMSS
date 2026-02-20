#!/usr/bin/env php
<?php
/**
 * PMSS script: show Traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(pmssShowTrafficMain($argv));
}

function pmssShowTrafficMain(array $argv): int
{
    $options = getopt('', ['json', 'show-missing', 'help']);
    if (isset($options['help'])) {
        pmssShowTrafficPrintHelp();
        return 0;
    }

    $asJson = isset($options['json']);
    $showMissing = isset($options['show-missing']);

    $runtimeDir = rtrim(getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
    $homeDir = rtrim(getenv('PMSS_HOME_DIR') ?: '/home', '/');
    $statsDir = $runtimeDir.'/trafficStats';
    $users = loadTrafficUsers($statsDir);
    if (count($users) === 0) {
        die("No users in this system!\n");
    }
    sort($users, SORT_NATURAL | SORT_FLAG_CASE);

    $dataMonthTotal = 0.0;
    $dataMonthTotalLocal = 0.0;
    $missingStats = [];
    $jsonRows = [];

    if (!$asJson) {
        echo "Legend:\n\t USER: Traffic: Data Month / Week / Day  IN: Month  Ratio  DATARATES: Rate Week / Rate Day / Rate Hour / Rate 15min\n";
    }

    foreach($users AS $thisUser) {
        //if (!file_exists("/home/{$thisUser}/.trafficData")) continue;
        $statsPath = "{$statsDir}/{$thisUser}";
        if (!is_file($statsPath)) {
            $missingStats[] = $thisUser;
            continue;
        }

        $rawStats = @file_get_contents($statsPath);
        if (!is_string($rawStats) || $rawStats === '') {
            continue;
        }

        $data = @unserialize($rawStats);
        if (!is_array($data)) {
            continue;
        }

        if (empty($data['raw']['month']) or
            $data['raw']['month'] == 0) continue;

        $dataMonthTotal += (float) $data['raw']['month'];
        if (strpos($thisUser, '-localnet') !== false) {
            $dataMonthTotalLocal += (float) $data['raw']['month'];
        }

        $dataDisplay = $data['raw'];
        foreach($dataDisplay AS $thisKey => $thisData) {
            $dataDisplay[$thisKey] = formatTrafficAmount($thisData);
        }

        $ingressData = pmssShowTrafficReadIngressData($thisUser, $homeDir);
        $inboundMonth = null;
        if ($ingressData !== null && isset($ingressData['raw']['month']) && is_numeric($ingressData['raw']['month'])) {
            $inboundMonth = (float) $ingressData['raw']['month'];
        }

        $inboundRatio = null;
        if ($inboundMonth !== null && (float) $data['raw']['month'] > 0) {
            $inboundRatio = round($inboundMonth / (float) $data['raw']['month'], 2);
        }

        $inboundDisplay = $inboundMonth !== null ? formatTrafficAmount($inboundMonth) : '-';
        $ratioDisplay = $inboundRatio !== null ? sprintf('%.2f', $inboundRatio) : 'n/a';

        $dataRates = array(
            'week' => round( ( (float) $data['raw']['week'] / (7 * 24 * 60 * 60) ), 2),
            'day' => round( ( (float) $data['raw']['day'] / (24 * 60 * 60) ), 2),
            'hour' => round( ((float) $data['raw']['hour'] / (60 * 60) ), 2),
            '15min' => round( ((float) $data['raw']['15min'] / (15 * 60) ), 2)
        );

        $displayUser = str_replace('-localnet', ' (L)', $thisUser);
        if ($asJson) {
            $jsonRows[] = [
                'user'    => $thisUser,
                'display' => [
                    'month' => $dataDisplay['month'] ?? null,
                    'week'  => $dataDisplay['week'] ?? null,
                    'day'   => $dataDisplay['day'] ?? null,
                ],
                'rates'   => $dataRates,
                'inboundMonthMiB' => $inboundMonth,
                'inboundOutboundRatio' => $inboundRatio,
                'rawMiB'  => $data['raw'],
            ];
        } else {
            printf(
                "%-14s %9s / %9s / %9s  IN: %9s R: %5s  Datarates: %5s / %5s / %5s / %5s\n",
                "{$displayUser}:",
                (string) ($dataDisplay['month'] ?? ''),
                (string) ($dataDisplay['week'] ?? ''),
                (string) ($dataDisplay['day'] ?? ''),
                $inboundDisplay,
                $ratioDisplay,
                sprintf('%.2f', $dataRates['week']),
                sprintf('%.2f', $dataRates['day']),
                sprintf('%.2f', $dataRates['hour']),
                sprintf('%.2f', $dataRates['15min'])
            );
        }
        //echo "User: {$thisUser} \t Traffic: {$dataDisplay['week']}, day: {$dataDisplay['day']}, hour: {$dataDisplay['hour']}, 15min: {$dataDisplay['15min']}\n";
        //echo "\tData rates:\t Week: {$dataRates['week']}M/s   Day: {$dataRates['day']}M/s    Hour: {$dataRates['hour']}M/s    15min: {$dataRates['15min']}M/s\n\n";

    }

    if ($asJson) {
        $payload = [
            'users' => $jsonRows,
            'totals' => [
                'monthMiB'      => round($dataMonthTotal, 2),
                'monthLocalMiB' => round($dataMonthTotalLocal, 2),
                'monthTiB'      => round(($dataMonthTotal / 1024 / 1024), 2),
                'monthLocalTiB' => round(($dataMonthTotalLocal / 1024 / 1024), 2),
            ],
            'missingStatsUsers' => $missingStats,
        ];
        echo json_encode($payload);
        echo "\n";
        return 0;
    }

    $monthTotalTiB = number_format(($dataMonthTotal / 1024 / 1024), 2);
    $monthTotalLocalTiB = number_format(($dataMonthTotalLocal / 1024 / 1024), 2);
    echo "* Month Total: {$monthTotalTiB}TiB - Local Total: {$monthTotalLocalTiB}TiB\n";
    if (!empty($missingStats)) {
        echo "* Missing traffic stats for ".count($missingStats)." users (run trafficStats to rebuild).\n";
        if ($showMissing) {
            echo "* Missing: ".implode(' ', $missingStats)."\n";
        }
    }

    return 0;
}

function pmssShowTrafficPrintHelp(): void
{
    $self = basename(__FILE__);
    echo "Usage: {$self} [--json] [--show-missing]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --json          Emit JSON instead of human text output.\n";
    echo "  --show-missing  Print missing stats usernames (text mode only).\n";
    echo "  --help          Show this help.\n";
    echo "\n";
}

function loadTrafficUsers(string $statsDir): array {
    $lines = [];
    $rc = 0;
    exec(escapeshellarg(__DIR__.'/listUsers.php'), $lines, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "Error: listUsers.php failed; aborting.\n");
        exit(1);
    }
    $users = array_filter(array_map('trim', $lines), 'strlen');
    if (empty($users)) {
        return [];
    }
    $withLocalnet = [];
    foreach ($users as $user) {
        $withLocalnet[] = $user;
        if (is_file($statsDir.'/'.$user.'-localnet')) {
            $withLocalnet[] = $user.'-localnet';
        }
    }
    return $withLocalnet;
}

function formatTrafficAmount($value): string {
    if ( ($value / 1024 / 999) > 1 ) return round( ($value / 1024 / 1024), 2) . 'TiB';
    if ( ($value / 999) > 1 )        return round( ($value / 1024), 2) . 'GiB';
    return round($value, 2) . 'MiB';
}

/**
 * Read ingress traffic data for a user label (supports -localnet suffix).
 */
function pmssShowTrafficReadIngressData(string $user, string $homeDir): ?array
{
    $suffix = '-localnet';
    $baseUser = $user;
    $fileSuffix = '';

    if (substr($user, -strlen($suffix)) === $suffix) {
        $baseUser = substr($user, 0, -strlen($suffix));
        $fileSuffix = 'Local';
    }

    $path = $homeDir.'/'.$baseUser.'/.trafficDataIngress'.$fileSuffix;
    if (!is_file($path) || is_link($path)) {
        return null;
    }

    $stats = @stat($path);
    if ($stats === false || (int) $stats['uid'] !== 0) {
        return null;
    }

    $mode = $stats['mode'] & 0777;
    if (($mode & 0022) !== 0) {
        return null;
    }

    $group = @posix_getgrgid($stats['gid']);
    if ($group !== false) {
        $groupName = $group['name'];
        if ($groupName !== $baseUser && $groupName !== 'root') {
            return null;
        }
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $data = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($data) || !isset($data['raw']) || !is_array($data['raw'])) {
        return null;
    }

    return $data;
}
