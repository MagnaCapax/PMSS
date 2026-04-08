#!/usr/bin/env php
<?php
/**
 * PMSS script: show Traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/runtime.php';
require_once __DIR__.'/lib/traffic.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssShowTrafficMain');

function pmssShowTrafficMain(array $argv): int
{
    $options = getopt('', ['json', 'show-missing', 'help', 'extended', 'sort:', 'color', 'no-color']);
    $helpExitCode = isset($options['help']) ? 0 : null;

    $asJson = isset($options['json']);
    $showMissing = isset($options['show-missing']);
    $extended = isset($options['extended']);

    $sort = 'name';
    if ($helpExitCode === null && array_key_exists('sort', $options)) {
        $sort = strtolower(trim((string) $options['sort']));
        if ($sort === '') {
            fwrite(STDERR, "Error: --sort expects a value.\n");
            return 2;
        }
    }
    $validSorts = ['name', 'month', 'pct', 'rate'];
    if ($helpExitCode === null && !in_array($sort, $validSorts, true)) {
        fwrite(STDERR, "Error: invalid --sort value: {$sort}\n");
        $helpExitCode = 2;
    }
    if ($helpExitCode !== null) {
        $self = basename(__FILE__);
        echo <<<TXT
Usage: {$self} [--json] [--show-missing] [--extended] [--sort=<mode>]

Options:
  --json          Emit JSON instead of human text output.
  --show-missing  Print missing stats usernames (text mode only).
  --extended      Show limit, percent, and rate units in text output.
  --sort=<mode>   Sort output by name, month, pct, or rate (default: name).
  --color         Force ANSI colors in extended text output.
  --no-color      Disable ANSI colors in extended text output.
  --help          Show this help.

TXT;
        return $helpExitCode;
    }

    $colorRequested = array_key_exists('color', $options);
    $noColorRequested = array_key_exists('no-color', $options);
    if ($colorRequested && $noColorRequested) {
        fwrite(STDERR, "Error: --color and --no-color are mutually exclusive.\n");
        return 2;
    }

    $useColor = false;
    if (!$asJson && $extended) {
        if ($colorRequested || $noColorRequested) {
            $useColor = $colorRequested;
        } else {
            $useColor = pmssStreamIsTty(STDOUT);
            $term = getenv('TERM');
            if ($term === false || $term === '' || $term === 'dumb') {
                $useColor = false;
            }
            $noColorEnv = getenv('NO_COLOR');
            if ($noColorEnv !== false && $noColorEnv !== '') {
                $useColor = false;
            }
        }
    }

    $trafficLimitLib = __DIR__.'/lib/user/trafficLimit.php';
    if (is_file($trafficLimitLib)) {
        require_once $trafficLimitLib;
    }

    $runtimeDir = pmssRuntimeDir();
    $statsDir = $runtimeDir.'/trafficStats';
    $listUsersResult = pmssListManagedUsersResult(__DIR__.'/listUsers.php');
    if (($users = pmssListManagedUsersFromResult($listUsersResult)) === null) {
        return 1;
    }

    $usersWithLocalnet = [];
    foreach ($users as $user) {
        $usersWithLocalnet[] = $user;
        if (is_file($statsDir.'/'.$user.'-localnet')) {
            $usersWithLocalnet[] = $user.'-localnet';
        }
    }
    $users = $usersWithLocalnet;
    if (empty($users)) {
        echo "No users in this system!\n";
        return 0;
    }
    sort($users, SORT_NATURAL | SORT_FLAG_CASE);

    $dataMonthTotal = 0.0;
    $dataMonthTotalLocal = 0.0;
    $missingStats = [];
    $rows = [];
    $limitCache = [];
    $baseUsers = [];
    $baseUsersWithStats = [];
    $overLimitCount = 0;
    $nearLimitCount = 0;

    if (!$asJson) {
        echo $extended
            ? "Legend:\n\t USER: Traffic: Data Month / Limit  %  Stat  Bar        IN: Month  Ratio  DATARATES: Week MiB/s / Day MiB/s / Hour MiB/s / 15min MiB/s\n"
            : "Legend:\n\t USER: Traffic: Data Month / Week / Day  IN: Month  Ratio  DATARATES: Rate Week / Rate Day / Rate Hour / Rate 15min\n";
    }

    foreach ($users as $thisUser) {
        $isLocalnet = pmssTrafficUserKeyIsLocalnet($thisUser);
        $baseUser = pmssTrafficUserKeyBaseUser($thisUser);
        $baseUsers[$baseUser] = true;
        $statsPath = pmssTrafficStatsPath($thisUser, $statsDir);
        if (!is_file($statsPath)) {
            $missingStats[] = $thisUser;
            continue;
        }

        $data = pmssTrafficReadSerializedArrayFile($statsPath);
        if ($data === null || empty($data['raw']['month'])) {
            continue;
        }

        $dataMonthTotal += (float) $data['raw']['month'];
        if ($isLocalnet) {
            $dataMonthTotalLocal += (float) $data['raw']['month'];
        }

        $dataDisplay = array_map('pmssTrafficFormatAmount', $data['raw']);

        $ingressPath = pmssTrafficDataPaths($baseUser)[pmssTrafficDataPathKey($isLocalnet, 'ingress')];
        $inboundMonth = null;
        $ingressData = pmssTrafficReadRootOwnedStatsPayload($ingressPath, $baseUser);
        $ingressData !== null && $inboundMonth = (float) $ingressData['raw']['month'];

        $inboundRatio = null;
        if ($inboundMonth !== null && (float) $data['raw']['month'] > 0) {
            $inboundRatio = round($inboundMonth / (float) $data['raw']['month'], 2);
        }

        $inboundDisplay = $inboundMonth !== null ? pmssTrafficFormatAmount($inboundMonth) : '-';
        $ratioDisplay = $inboundRatio !== null ? sprintf('%.2f', $inboundRatio) : 'n/a';

        $dataRates = [
            'week' => round(((float) $data['raw']['week'] / (7 * 24 * 60 * 60)), 2),
            'day' => round(((float) $data['raw']['day'] / (24 * 60 * 60)), 2),
            'hour' => round(((float) $data['raw']['hour'] / (60 * 60)), 2),
            '15min' => round(((float) $data['raw']['15min'] / (15 * 60)), 2),
        ];

        $displayUser = $isLocalnet ? "{$baseUser} (L)" : $baseUser;

        $limitGiB = null;
        if (array_key_exists($baseUser, $limitCache)) {
            $limitGiB = $limitCache[$baseUser];
        } else {
            $limitPath = pmssTrafficLimitPath($baseUser);
            $parsedLimit = pmssTrafficLimitReadGiBFile($limitPath);
            if ($parsedLimit > 0) {
                $limitGiB = $parsedLimit;
            }
            $limitCache[$baseUser] = $limitGiB;
        }

        $limitMiB = ($limitGiB !== null) ? ($limitGiB * 1024) : null;
        $pctUsed = null;
        $overLimit = false;
        $nearLimit = false;
        if ($limitMiB !== null && $limitMiB > 0) {
            $pctUsed = (((float) $data['raw']['month']) / $limitMiB) * 100;
            $overLimit = ($pctUsed >= 100);
            $nearLimit = (!$overLimit && $pctUsed >= 80);
        }
        if (!$isLocalnet) {
            $baseUsersWithStats[$baseUser] = true;
            if ($overLimit) {
                $overLimitCount++;
            } elseif ($nearLimit) {
                $nearLimitCount++;
            }
        }

        $rows[] = [
            'user' => $thisUser,
            'displayUser' => $displayUser,
            'isLocalnet' => $isLocalnet,
            'monthMiB' => (float) $data['raw']['month'],
            'display' => [
                'month' => $dataDisplay['month'] ?? null,
                'week'  => $dataDisplay['week'] ?? null,
                'day'   => $dataDisplay['day'] ?? null,
            ],
            'rates' => $dataRates,
            'inboundMonthMiB' => $inboundMonth,
            'inboundDisplay' => $inboundDisplay,
            'inboundRatio' => $inboundRatio,
            'ratioDisplay' => $ratioDisplay,
            'limitGiB' => $limitGiB,
            'limitMiB' => $limitMiB,
            'pctUsed' => $pctUsed,
            'overLimit' => $overLimit,
            'nearLimit' => $nearLimit,
            'rawMiB' => $data['raw'],
        ];
    }

    sort($missingStats, SORT_NATURAL | SORT_FLAG_CASE);

    if ($sort !== 'name') {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            switch ($sort) {
                case 'month':
                    $cmp = $b['monthMiB'] <=> $a['monthMiB'];
                    break;
                case 'pct':
                    $aPct = ($a['pctUsed'] === null) ? -1 : $a['pctUsed'];
                    $bPct = ($b['pctUsed'] === null) ? -1 : $b['pctUsed'];
                    $cmp = $bPct <=> $aPct;
                    break;
                case 'rate':
                    $cmp = $b['rates']['15min'] <=> $a['rates']['15min'];
                    break;
                default:
                    $cmp = 0;
            }
            return $cmp !== 0 ? $cmp : strnatcasecmp($a['user'], $b['user']);
        });
    }

    if ($asJson) {
        $payload = [
            'users' => array_map(static function (array $row): array {
                return [
                'user'    => $row['user'],
                'display' => [
                    'month' => $row['display']['month'],
                    'week'  => $row['display']['week'],
                    'day'   => $row['display']['day'],
                ],
                'rates'   => $row['rates'],
                'inboundMonthMiB' => $row['inboundMonthMiB'],
                'inboundOutboundRatio' => $row['inboundRatio'],
                'limitMiB' => $row['limitMiB'],
                'pctUsed' => ($row['pctUsed'] !== null) ? round($row['pctUsed'], 2) : null,
                'overLimit' => $row['overLimit'],
                'nearLimit' => $row['nearLimit'],
                'rawMiB'  => $row['rawMiB'],
                ];
            }, $rows),
            'totals' => [
                'monthMiB'      => round($dataMonthTotal, 2),
                'monthLocalMiB' => round($dataMonthTotalLocal, 2),
                'monthTiB'      => round(($dataMonthTotal / 1024 / 1024), 2),
                'monthLocalTiB' => round(($dataMonthTotalLocal / 1024 / 1024), 2),
            ],
            'summary' => [
                'totalUsers' => count($baseUsers),
                'usersWithStats' => count($baseUsersWithStats),
                'overLimit' => $overLimitCount,
                'nearLimit' => $nearLimitCount,
                'missingStats' => count($missingStats),
            ],
            'missingStatsUsers' => $missingStats,
        ];
        echo json_encode($payload);
        echo "\n";
        return 0;
    }

    foreach ($rows as $row) {
        if ($extended) {
            $limitDisplay = ($row['limitMiB'] !== null) ? pmssTrafficFormatAmount($row['limitMiB']) : '-';
            $pctDisplayRaw = 'n/a';
            $statusLabel = '';
            if ($row['pctUsed'] !== null) {
                $pctValue = min(999, (int) round($row['pctUsed']));
                $pctDisplayRaw = sprintf('%3d%%', $pctValue);
                if ($row['pctUsed'] >= 100) {
                    $statusLabel = '[OVER]';
                } elseif ($row['pctUsed'] >= 80) {
                    $statusLabel = '[WARN]';
                }
            }
            $pctDisplay = sprintf('%4s', $pctDisplayRaw);
            $statusDisplay = str_pad($statusLabel, 6, ' ', STR_PAD_RIGHT);
            if ($useColor && $statusLabel !== '') {
                $color = ($row['pctUsed'] !== null && $row['pctUsed'] >= 100) ? "\033[1;31m" : "\033[33m";
                $statusDisplay = $color.$statusDisplay."\033[0m";
            }
            if ($row['limitMiB'] !== null && $row['pctUsed'] !== null) {
                $filled = (int) floor((max(0.0, min(100.0, (float) $row['pctUsed'])) / 100) * 10);
                $barDisplay = '['.str_repeat('#', $filled).str_repeat('-', 10 - $filled).']';
            } else {
                $barDisplay = str_repeat(' ', 12);
            }

            printf(
                "%-14s %9s / %9s %4s %s %s IN: %9s R: %5s  Datarates: %10s / %10s / %10s / %10s\n",
                "{$row['displayUser']}:",
                (string) $row['display']['month'],
                $limitDisplay,
                $pctDisplay,
                $statusDisplay,
                $barDisplay,
                $row['inboundDisplay'],
                $row['ratioDisplay'],
                pmssShowTrafficFormatRateDisplay((float) $row['rates']['week']),
                pmssShowTrafficFormatRateDisplay((float) $row['rates']['day']),
                pmssShowTrafficFormatRateDisplay((float) $row['rates']['hour']),
                pmssShowTrafficFormatRateDisplay((float) $row['rates']['15min'])
            );
        } else {
            printf(
                "%-14s %9s / %9s / %9s  IN: %9s R: %5s  Datarates: %5s / %5s / %5s / %5s\n",
                "{$row['displayUser']}:",
                (string) ($row['display']['month'] ?? ''),
                (string) ($row['display']['week'] ?? ''),
                (string) ($row['display']['day'] ?? ''),
                $row['inboundDisplay'],
                $row['ratioDisplay'],
                sprintf('%.2f', $row['rates']['week']),
                sprintf('%.2f', $row['rates']['day']),
                sprintf('%.2f', $row['rates']['hour']),
                sprintf('%.2f', $row['rates']['15min'])
            );
        }
    }

    $monthTotalTiB = number_format(($dataMonthTotal / 1024 / 1024), 2);
    $monthTotalLocalTiB = number_format(($dataMonthTotalLocal / 1024 / 1024), 2);
    if ($extended) {
        $line = str_repeat('-', 72);
        echo $line."\n";
        echo " Total users: ".count($baseUsers)."  |  Over limit: {$overLimitCount}  |  Near limit (>=80%): {$nearLimitCount}\n";
        echo " Month egress: {$monthTotalTiB}TiB  |  Local: {$monthTotalLocalTiB}TiB\n";
        $missingLine = " Missing stats: ".count($missingStats)." users";
        if (!empty($missingStats) && !$showMissing) {
            $missingLine .= " (--show-missing to list)";
        }
        echo $missingLine."\n";
        if ($showMissing && !empty($missingStats)) {
            echo " Missing: ".implode(' ', $missingStats)."\n";
        }
        echo $line."\n";
    } else {
        echo "* Month Total: {$monthTotalTiB}TiB - Local Total: {$monthTotalLocalTiB}TiB\n";
        if (!empty($missingStats)) {
            echo "* Missing traffic stats for ".count($missingStats)." users (run trafficStats to rebuild).\n";
            if ($showMissing) {
                echo "* Missing: ".implode(' ', $missingStats)."\n";
            }
        }
    }

    return 0;
}

/**
 * Format a data rate in MiB/s, auto-scaling to GiB/s.
 */
function pmssShowTrafficFormatRateDisplay(float $rateMiB): string
{
    return sprintf(
        '%.2f%s',
        $rateMiB >= 1000 ? $rateMiB / 1024 : $rateMiB,
        $rateMiB >= 1000 ? 'GiB/s' : 'MiB/s'
    );
}
