<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/userMaintenance.php';

/** Characterize the single maintenance queue using deterministic, in-memory observations. */
class UserMaintenanceFlowCharacterizationTest extends TestCase
{
    public function testFacadeRetainsAllPublicHelpersAtTheirResponsibilityBoundaries(): void
    {
        foreach ([
            'refreshState.php' => ['pmssUserRefreshSignature', 'pmssUserRefreshMarkerPath',
                'pmssUserRefreshAlreadyDone', 'pmssUserRefreshMarkDone'],
            'legacyCpuQuota.php' => ['pmssUserMaintenanceLegacyCpuQuotaNeedsFix', 'pmssUserMaintenanceRepairLegacyCpuQuota'],
            'maintenanceAccount.php' => ['pmssUserMaintenanceAccountRefresh'],
        ] as $module => $functions) {
            foreach ($functions as $function) {
                $this->assertSame($this->pmssRepoPath('scripts/lib/update/users/'.$module),
                    (new \ReflectionFunction($function))->getFileName());
            }
        }
    }

    public function testSortedQueuePreservesStagesCommandArgumentsAndSummaryFieldOrder(): void
    {
        $result = $this->trace(['users' => ['fixtur10', 'fixtur02']]);
        $summary = ['total' => 2, 'processed' => 2, 'skipped' => 0, 'skip_reasons' => []];
        $this->pmssAssertArraySubsetSame(['summary' => $summary,
            'json' => ['event' => 'user_maintenance_summary', 'status' => 'ok'] + $summary], $result);
        $this->assertSame(['fixtur02', 'fixtur10'], array_column($this->events($result, 'resume'), 1));
        $accountEvents = array_values(array_filter($result['events'], static function ($row): bool {
            return isset($row[1]) && $row[1] === 'fixtur02';
        }));
        $this->assertSame(['resume', 'reset', 'heal', 'quota', 'environment', 'docker', 'post', 'post',
            'user_log', 'lighttpd', 'profile', 'marker'], array_column($accountEvents, 0));
        $this->assertSame([
            ['post', 'fixtur02', 'Synchronizing per-user htpasswd', "/scripts/util/checkUserHtpasswd.php 'fixtur02'", []],
            ['post', 'fixtur02', 'Checking lighttpd instance', "/scripts/cron/checkLighttpdInstances.php 'fixtur02'", [75]],
        ], array_slice($this->events($result, 'post'), 0, 2));
        $this->assertSame(['user_log', 'fixtur02', 'update-step2: user maintenance finished (1.00s)'], $accountEvents[8]);
        $this->assertSame(['profile', 'fixtur02', ['updateUser fixtur02', '', 'OK', 0, 1, false]], $accountEvents[10]);
        $this->assertSame('', $result['output']);
    }

    public function testInvalidEntriesRetainReasonsAndNeverReachAccountWork(): void
    {
        foreach (['' => 'empty username entry', " \t" => 'empty username entry', 'bad;user' => 'invalid username',
            '../fixtur01' => 'invalid username'] as $user => $reason) {
            $result = $this->trace(['users' => [$user]]);
            $label = trim($user) === '' ? '(empty)' : $user;
            $this->assertSame(['total' => 1, 'processed' => 0, 'skipped' => 1,
                'skip_reasons' => [$label.': '.$reason]], $result['summary']);
            $this->assertSame([], $this->events($result, 'resume'));
            $this->assertSame([], $this->events($result, 'reset'));
            $this->assertSame(['log', "[WARN] Account '{$label}' skipped during environment refresh: {$reason}"],
                $this->events($result, 'log')[1]);
        }
        $this->assertSame(['total' => 1, 'processed' => 1, 'skipped' => 0, 'skip_reasons' => []],
            $this->trace(['users' => [' fixtur01 ']])['summary']);
    }

    public function testResumeAndPackageChangeGatesKeepTheirShortCircuitOrder(): void
    {
        foreach ([
            [false, true, false, 1, 1, 1], [true, true, false, 1, 1, 0],
            [true, false, false, 0, 1, 0], [true, true, true, 0, 0, 0], [false, false, false, 0, 1, 0],
        ] as [$resumed, $heal, $changed, $processed, $healCalls, $markers]) {
            $result = $this->trace(['resumed' => $resumed ? ['fixtur01'] : [], 'heal' => $heal, 'docker_changed' => $changed]);
            $this->assertSame($processed, $result['summary']['processed']);
            $this->assertSame($healCalls, count($this->events($result, 'heal')));
            $this->assertSame($markers, count($this->events($result, 'marker')));
            if (!$processed) {
                $this->assertSame(['fixtur01: UserMaintenanceFlowFixture\\RuntimeException: cgroup slice memory policy refresh failed'],
                    $result['summary']['skip_reasons']);
                $this->assertSame([], $this->events($result, 'quota'));
            }
        }
    }

    public function testOptionalCheckAvailabilityAndBusyExitPolicyRetainTheirOwnLabels(): void
    {
        $paths = ['/scripts/util/checkUserHtpasswd.php', '/scripts/cron/checkLighttpdInstances.php'];
        $labels = ['Legacy htpasswd sync', 'Lighttpd instance check'];
        foreach ([[], [$paths[0]], [$paths[1]], $paths] as $helpers) {
            $result = $this->trace(['helpers' => $helpers]);
            $this->assertSame($helpers, array_map(static function ($row): string {
                return explode(' ', $row[3], 2)[0];
            }, $this->events($result, 'post')));
            $phases = ['Environment (HTTP/ruTorrent/permissions + linger/systemd/rootless Docker)'];
            foreach ($paths as $index => $path) if (in_array($path, $helpers, true)) $phases[] = $labels[$index];
            $this->assertSame(['log', 'Updating user fixtur01 phases: '.implode(', ', $phases)], $this->events($result, 'log')[1]);
        }
        foreach ([0 => 0, 1 => 2, 75 => 1, 127 => 2] as $rc => $warnings) {
            $result = $this->trace(['post_rc' => $rc]);
            $messages = array_column($this->events($result, 'user_log'), 2);
            $this->assertSame($warnings, count(array_filter($messages, static function ($message): bool {
                return strpos($message, '[WARN]') === 0;
            })));
            $this->assertSame(1, count($this->events($result, 'marker')));
        }
    }

    public function testIncompleteLighttpdRemainsProcessedAndKeepsTheRunWarning(): void
    {
        $result = $this->trace(['users' => ['fixtur01', 'fixtur02'], 'incomplete' => ['fixtur01']]);
        $this->assertSame(['total' => 2, 'processed' => 2, 'skipped' => 0, 'skip_reasons' => []], $result['summary']);
        $this->assertSame('warn', $result['json']['status']);
        $this->assertSame([['marker', 'fixtur02']], $this->events($result, 'marker'));
        $this->assertSame(['profile', 'fixtur01', ['updateUser fixtur01', '', 'ERR', 1, 1, false]], $this->events($result, 'profile')[0]);
        $this->assertSame(['log', '[WARN] Processed 2 of 2 users'], end($result['events']));
    }

    public function testThrowablesSkipOnlyTheAffectedAccountButResetRemainsOutsideTheCatch(): void
    {
        foreach (['environment', 'docker', 'post', 'user_log', 'lighttpd', 'profile', 'marker'] as $fault) {
            $result = $this->trace(['users' => ['fixtur01', 'fixtur02'], 'fault' => $fault]);
            $this->assertSame(['total' => 2, 'processed' => 1, 'skipped' => 1,
                'skip_reasons' => ['fixtur01: Error: fixture fault']], $result['summary'], $fault);
            $this->assertSame('warn', $result['json']['status']);
            $this->assertTrue(in_array(['marker', 'fixtur02'], $result['events'], true));
        }
        $result = $this->trace(['fault' => 'reset']);
        $this->pmssAssertArraySubsetSame(['thrown' => ['Error', 'fixture fault'], 'summary' => null, 'json' => null], $result);
    }

    public function testEnvironmentFailureReasonsRetainOriginalBytesAndStopBeforeDocker(): void
    {
        foreach ([null, '', '  ', 'home-missing', ' home-missing '] as $reason) {
            $result = $this->trace(['environment_ok' => false, 'reason' => $reason]);
            $message = 'user environment convergence failed'.(is_string($reason) && trim($reason) !== '' ? ': '.$reason : '');
            $this->assertSame(['fixtur01: UserMaintenanceFlowFixture\\RuntimeException: '.$message], $result['summary']['skip_reasons']);
            $this->assertSame([], $this->events($result, 'docker'));
            $this->assertSame([], $this->events($result, 'marker'));
        }
    }

    public function testPruneGatesEmptyRunsAndTtyPresentationKeepTheirContracts(): void
    {
        foreach ([[false, false, 1], [true, false, 0], [false, true, 0]] as [$dry, $testing, $prunes]) {
            $result = $this->trace(['users' => [], 'dry' => $dry, 'testing' => $testing]);
            $this->assertSame(['total' => 0, 'processed' => 0, 'skipped' => 0, 'skip_reasons' => []], $result['summary']);
            $this->assertSame('ok', $result['json']['status']);
            $this->assertSame($prunes, count($this->events($result, 'prune')));
        }
        $result = $this->trace(['tty' => true]);
        $this->assertSame("\n<35>Updating user fixtur01</35>\n"
            ."  <33>* Environment (HTTP/ruTorrent/permissions + linger/systemd/rootless Docker)</33>\n"
            ."  <33>* Legacy htpasswd sync</33>\n  <33>* Lighttpd instance check</33>\n", $result['output']);
    }

    private function events(array $result, string $event): array
    {
        return array_values(array_filter($result['events'], static function ($row) use ($event): bool { return $row[0] === $event; }));
    }

    /** Load the real queue and account sequence; every external action below records only data. */
    private function trace(array $config): array
    {
        $script = <<<'PHP'
namespace UserMaintenanceFlowFixture;
\putenv('PMSS_TEST_MODE=1');
\putenv('PMSS_DRY_RUN=0');
const PMSS_CRON_LOCK_BUSY_EXIT = 75;
class RuntimeException extends \RuntimeException {}
class UserConfigStore {}
class users { public static function listHomeUsers() { return $GLOBALS['config']['users'] ?? ['fixtur01']; } }
function effect($stage, $user) {
    $GLOBALS['events'][] = [$stage, $user];
    if (($GLOBALS['config']['fault'] ?? '') === $stage && $user === 'fixtur01') throw new \Error('fixture fault');
}
function pmssEnvFlagEnabled($key) { return $key === 'PMSS_DRY_RUN' ? ($GLOBALS['config']['dry'] ?? false) : ($GLOBALS['config']['testing'] ?? true); }
function getenv($key) { return !empty($GLOBALS['config']['docker_changed']) ? '1' : '0'; }
function pmssUserMaintenancePruneStaleLinger() { effect('prune', ''); return 3; }
function pmssUserRefreshSignature($sha) { effect('signature', $sha); return 'signature-'.$sha; }
function pmssValidateUsername($user) { return preg_match('/^fixtur[0-9]{2}$/', $user) === 1; }
function pmssUserRefreshAlreadyDone($user, $signature) { effect('resume', $user); return in_array($user, $GLOBALS['config']['resumed'] ?? [], true); }
function pmssUserCgroupSliceSelfHeal($user, $store) { effect('heal', $user); return $GLOBALS['config']['heal'] ?? true; }
function pmssUserMaintenanceRepairLegacyCpuQuota($user) { effect('quota', $user); }
function pmssUserLighttpdRefreshFailed($user, $value = null) {
    effect($value === null ? 'lighttpd' : 'reset', $user);
    return $value === null && in_array($user, $GLOBALS['config']['incomplete'] ?? [], true);
}
function pmssUpdateUserEnvironment($user, $sha, &$reason) {
    effect('environment', $user); $reason = $GLOBALS['config']['reason'] ?? null;
    return $GLOBALS['config']['environment_ok'] ?? true;
}
function pmssEnsureLingerAndDocker($user) { effect('docker', $user); }
function pmssBuildCommand($path, $args) { return $path.' '.escapeshellarg($args[0]); }
function runUserStep($user, $label, $command, $skip) {
    effect('post', $user); $GLOBALS['events'][count($GLOBALS['events']) - 1] = ['post', $user, $label, $command, $skip];
    return $GLOBALS['config']['post_rc'] ?? 0;
}
function pmssUserLog($user, $message) {
    // Abort logging fails once: the catch's own warning must remain observable.
    $fault = ($GLOBALS['config']['fault'] ?? '') === 'user_log' && $user === 'fixtur01' && empty($GLOBALS['logFaulted']);
    $GLOBALS['events'][] = ['user_log', $user, $message];
    if ($fault) { $GLOBALS['logFaulted'] = true; throw new \Error('fixture fault'); }
}
function logMessage($message) { $GLOBALS['events'][] = ['log', $message]; }
function pmssLogJson($payload) { $GLOBALS['json'] = $payload; }
function pmssCommandProfileRecord(...$args) {
    $user = substr($args[0], strlen('updateUser '));
    // A failed completed-pass profile still permits the catch's error profile.
    if (count($args) === 6) effect('profile', $user);
    else $GLOBALS['events'][] = ['profile', $user];
    $GLOBALS['events'][count($GLOBALS['events']) - 1] = ['profile', $user, $args];
}
function pmssUserRefreshMarkDone($user, $signature) { effect('marker', $user); }
function pmssStreamIsTty($stream) { return $GLOBALS['config']['tty'] ?? false; }
function pmssCliAnsiStyle($text, $color) { return '<'.$color.'>'.$text.'</'.$color.'>'; }
function pmssLogWhitespaceCollapse($text) { return preg_replace('/\s+/', ' ', $text); }
function microtime($asFloat) { return (float) ++$GLOBALS['clock']; }
function is_file($path) { return in_array($path, $GLOBALS['config']['helpers'] ?? ['/scripts/util/checkUserHtpasswd.php', '/scripts/cron/checkLighttpdInstances.php'], true); }
PHP;
        $script .= '$GLOBALS["config"] = '.var_export($config, true).';'
            .'$GLOBALS["events"] = []; $GLOBALS["clock"] = 0; $GLOBALS["json"] = null;';
        foreach (['userMaintenance.php', 'users/maintenanceAccount.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/'.$module, 'UserMaintenanceFlowFixture');
        }
        $script .= <<<'PHP'
$summary = $thrown = null;
\ob_start();
try { $summary = pmssUpdateAllUsers('index-sha'); }
catch (\Throwable $error) { $thrown = [get_class($error), $error->getMessage()]; }
$output = \ob_get_clean();
echo json_encode(['summary' => $summary, 'events' => $GLOBALS['events'], 'json' => $GLOBALS['json'], 'output' => $output, 'thrown' => $thrown]);
PHP;
        return $this->pmssRunInlinePhpJson($script);
    }
}
