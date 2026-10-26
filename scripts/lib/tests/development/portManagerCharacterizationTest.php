<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/portManager.php';

/** Freeze allocator channels, audit events, lock ordering, and failure precedence. */
final class PortManagerCharacterizationTest extends TestCase
{
    private function runScenario(string $action, string $scenario, bool $present = true): array
    {
        $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/tests/fixtures/portManagerCharacterization.php'), true).';';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/portManager.php', 'PortManagerCharacterization',
            ['portManager/selection.php', 'portManager/assignment.php', 'portManager/cli.php']);
        $script .= '$GLOBALS["scenario"] = '.var_export($scenario, true).'; $GLOBALS["present"] = '.var_export($present, true).';';
        $script .= <<<'PHP'
\ob_start();
try { $rc = \PortManagerCharacterization\pmssPortManagerMain(['portManager.php', ACTION, 'alice', 'rclone']); }
catch (\Throwable $error) { $rc = $error->getMessage(); }
$stdout = \ob_get_clean();
echo json_encode([$rc, $stdout, $GLOBALS['stderr'], $GLOBALS['events']]);
PHP;
        return $this->pmssRunInlinePhpJson(str_replace('ACTION', var_export($action, true), $script));
    }

    private function audit(string $action, ?int $port, string $status, string $message): array
    {
        return ['port', $action, 'alice', ['status' => $status, 'service' => 'rclone', 'port' => $port, 'message' => $message]];
    }

    public function testViewAndReleaseKeepTheirDistinctStoredValuePolicies(): void
    {
        $this->assertSame([0, '24567', '', ['context', 'read']], $this->runScenario('view', 'normal'));
        $this->assertSame([1, '', "Error: invalid stored port assignment\n", ['context', 'read']], $this->runScenario('view', 'malformed'));
        $this->assertSame([0, 'No port assigned', '', ['context']], $this->runScenario('view', 'normal', false));
        $release = ['context', 'lock', 'context', 'safe', 'unlink', $this->audit('release', null, 'OK', 'released'), 'unlock'];
        foreach (['normal', 'malformed', 'appeared'] as $scenario) {
            $this->assertSame([0, 'Port released', '', $release], $this->runScenario('release', $scenario, $scenario !== 'appeared'));
        }
        foreach (['normal', 'disappeared'] as $scenario) {
            $this->assertSame([0, 'No port assigned', '', ['context', 'lock', 'context', 'unlock']], $this->runScenario('release', $scenario, $scenario === 'disappeared'));
        }
    }

    public function testPreflightAndLockFailureChannelsAndAudits(): void
    {
        foreach (['assign', 'view', 'release'] as $action) {
            $this->assertSame([1, '', "Error: unable to initialize port directory\n", ['context']], $this->runScenario($action, 'directory'));
            $this->assertSame([1, '', "Error: invalid stored port assignment\n", ['context', $this->audit($action, null, 'ERR', 'unsafe_assignment_path')]], $this->runScenario($action, 'unsafe'));
        }
        foreach (['assign', 'release'] as $action) {
            $this->assertSame([1, '', "Error: unable to initialize port directory\n", ['context', 'lock', 'context', 'unlock']], $this->runScenario($action, 'directory_after_lock'));
            $this->assertSame([1, '', "Error: unable to acquire port lock\n", ['context', 'lock', $this->audit($action, null, 'ERR', 'lock_failed')]], $this->runScenario($action, 'lock'));
            // Assignment reports after unlocking; release historically reports inside the lock.
            $audit = $this->audit($action, null, 'ERR', 'unsafe_assignment_path');
            $events = ['context', 'lock', 'context'];
            array_push($events, ...($action === 'assign' ? ['unlock', $audit] : [$audit, 'unlock']));
            $this->assertSame([1, '', "Error: invalid stored port assignment\n", $events], $this->runScenario($action, 'unsafe_after_lock'));
        }
    }

    public function testAssignmentFailuresAndSuccessfulAudits(): void
    {
        foreach (['normal', 'appeared'] as $scenario) {
            $this->assertSame([0, '24567', '', ['context', 'lock', 'context', 'read', 'unlock', $this->audit('assign', 24567, 'SKIP', 'already_assigned')]], $this->runScenario('assign', $scenario, $scenario !== 'appeared'));
        }
        $this->assertSame([0, '24567', '', ['context', 'lock', 'context', 'scan', 'tcp://127.0.0.1:24567', 'tcp://0.0.0.0:24567', 'write', 'unlock', $this->audit('assign', 24567, 'OK', 'assigned')]], $this->runScenario('assign', 'normal', false));
        foreach ([
            ['malformed', true, ['read'], 'invalid stored port assignment', 'invalid_existing_assignment'],
            ['scan', false, ['scan'], 'invalid stored port assignment', 'unsafe_assignment_path'],
            ['exhausted', false, ['scan'], 'no free port available', 'port_range_exhausted'],
            ['write', false, ['scan', 'tcp://127.0.0.1:24567', 'tcp://0.0.0.0:24567', 'write'], 'failed to persist port assignment', 'write_failed'],
        ] as [$scenario, $present, $work, $error, $status]) {
            $events = array_merge(['context', 'lock', 'context'], $work, ['unlock', $this->audit('assign', null, 'ERR', $status)]);
            $this->assertSame([1, '', 'Error: '.$error."\n", $events], $this->runScenario('assign', $scenario, $present));
        }
        $this->assertSame(['fixture read failed', '', '', ['context', 'lock', 'context', 'read', 'unlock']], $this->runScenario('assign', 'throw'));
    }

    public function testReleaseFailuresReportBeforeUnlocking(): void
    {
        $this->assertSame(['fixture unlink failed', '', '', ['context', 'lock', 'context', 'safe', 'unlink', 'unlock']], $this->runScenario('release', 'unlink_throw'));
        foreach ([['unsafe_release', ['safe'], 'invalid stored port assignment', 'unsafe_assignment_path'], ['unlink', ['safe', 'unlink'], 'failed to release port', 'release_failed']] as [$scenario, $work, $error, $status]) {
            $events = array_merge(['context', 'lock', 'context'], $work, [$this->audit('release', null, 'ERR', $status), 'unlock']);
            $this->assertSame([1, '', 'Error: '.$error."\n", $events], $this->runScenario('release', $scenario));
        }
    }

    public function testReservationSetsKeepLegacyFirstOrderAndPartialResults(): void
    {
        $root = $this->pmssMakeTempDir('pmss-port-scan-characterization-');
        mkdir($root.'/legacy');
        mkdir($root.'/legacy/scgi');
        mkdir($root.'/managed');
        foreach (['26000', '28000', 'invalid'] as $name) file_put_contents($root.'/legacy/scgi/'.$name, '');
        foreach (['lighttpd-alice' => "24567\n", 'rclone-alice' => "26000\n", 'invalid' => 'bad'] as $name => $payload) file_put_contents($root.'/managed/'.$name, $payload);
        $complete = null;
        $this->assertSame([26000 => true, 28000 => true, 24567 => true], \pmssPortManagerUsedPorts($root.'/managed', $root.'/legacy', $complete));
        $this->assertSame(true, $complete);
        $this->assertSame([24567 => true, 26000 => true], \pmssPortManagerUsedPorts($root.'/managed', $root.'/missing', $complete));
        $this->assertSame(true, $complete);
        $this->assertSame([26000 => true, 28000 => true], \pmssPortManagerUsedPorts($root.'/missing', $root.'/legacy', $complete));
        $this->assertSame(false, $complete);
    }
}
