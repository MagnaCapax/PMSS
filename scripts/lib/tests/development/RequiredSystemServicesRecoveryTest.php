<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Hermetic service observations and starts; only private fixture locks are real. */
class RequiredSystemServicesRecoveryTest extends TestCase
{
    public function testGuardCallsRecoveryAfterDisablement(): void
    {
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/systemdServicesGuard.php', ['/services/systemd/recovery.php']);
        $this->assertTrue(strpos($source, 'pmssStopDisableMaskSystemdUnit(')
            < strpos($source, 'pmssRecoverRequiredSystemdServices();'));
    }

    public function testOnlyRequiredUnitsStartAndNginxKeepsItsValidation(): void
    {
        foreach (['inactive', 'failed'] as $active) {
            foreach (['enabled', 'enabled-runtime'] as $enabled) {
                $row = $this->observe(['active' => $active, 'enabled' => $enabled]);
                $this->assertSame(['nginx', 'ssh', 'cron', 'proftpd'], array_column($row['steps'], 0));
                $this->assertStringContainsAllStrings(['nginx -t && systemctl start nginx',
                    'systemctl is-active --quiet nginx', '--property=MainPID', '"$pmssNginxMainPid" -gt 0'], $row['steps'][0][1]);
                foreach (array_slice($row['steps'], 1) as [$unit, $command]) {
                    $this->assertSame("systemctl start '".$unit."' && systemctl is-active --quiet '".$unit."'", $command);
                }
                $this->assertTrue($row['released']);
            }
        }
    }

    public function testDisabledMaskedMissingAndUnknownEnablementNeverStarts(): void
    {
        foreach (['disabled', 'masked', 'masked-runtime', 'static', 'indirect', 'not-found', '', null] as $enabled) {
            $this->assertSame([], $this->observe(['enabled' => $enabled])['steps']);
        }
    }

    public function testActiveTransitionalAndUnknownActivityNeverStarts(): void
    {
        foreach (['active', 'activating', 'deactivating', 'reloading', 'maintenance', 'unknown', '', null] as $active) {
            $this->assertSame([], $this->observe(['active' => $active])['steps']);
        }
    }

    public function testBootShutdownAndUnavailableManagerNeverStart(): void
    {
        foreach (['initializing', 'starting', 'stopping', 'offline', 'maintenance', 'unknown', ''] as $manager) {
            $row = $this->observe(['manager' => [$manager]]);
            $this->assertSame([], $row['steps']);
            $this->assertTrue($row['released']);
        }
        $this->assertSame([], $this->observe(['runtime' => false])['steps']);
        $this->assertSame(4, count($this->observe(['manager' => ['degraded']])['steps']));
        $this->assertSame(1, count($this->observe(['manager' => ['running', 'stopping']])['steps']));
    }

    public function testBusyOrUnavailableUpdateLockSkipsRecovery(): void
    {
        foreach (['busy' => 'update lock busy', 'unavailable' => 'update lock unavailable'] as $lock => $message) {
            $row = $this->observe(['lock' => $lock]);
            $this->assertSame([], $row['steps']);
            $this->assertStringContainsString($message, implode('\n', $row['logs']));
        }
    }

    public function testLockExcludesUpdatesThroughoutActionsAndRestoresDescriptorEnvironment(): void
    {
        $row = $this->observe([]);
        $this->assertSame([true, true, true, true], array_column($row['steps'], 2));
        $this->assertSame([true, true, true, true], array_column($row['steps'], 3));
        $this->assertSame('fixture-prior', $row['fds']);
        foreach (['export', 'step'] as $fault) {
            $row = $this->observe(['fault' => $fault]);
            $this->assertSame('fixture fault', $row['error']);
            $this->assertTrue($row['released']);
            $this->assertSame('fixture-prior', $row['fds']);
        }
    }

    public function testFailedStartDoesNotPreventOtherRequiredServices(): void
    {
        $this->assertSame(4, count($this->observe(['rc' => 1])['steps']));
    }

    /** Run real recovery code with namespaced observations, keeping all commands inert. */
    private function observe(array $options): array
    {
        $root = $this->pmssMakeTempDir('pmss-required-services-');
        $script = <<<'PHP'
namespace RequiredServicesFixture;
function pmssSystemdRuntimeAvailable() { return $GLOBALS['options']['runtime']; }
function shell_exec($command) {
    if ($command !== 'systemctl is-system-running 2>/dev/null') throw new \RuntimeException('unexpected probe');
    $states = &$GLOBALS['options']['manager'];
    return count($states) > 1 ? array_shift($states) : $states[0];
}
function pmssSystemdUnitState($action, $unit) {
    return $GLOBALS['options'][$action === 'is-enabled' ? 'enabled' : 'active'];
}
function logMessage($message) { $GLOBALS['logs'][] = $message; }
function pmssLockHandleExportChildCloseFds($handle) {
    \pmssLockHandleExportChildCloseFds($handle);
    if ($GLOBALS['options']['fault'] === 'export') throw new \RuntimeException('fixture fault');
}
function runStep($description, $command) {
    $other = \pmssLockFileAcquire($GLOBALS['path'], true, 'c', false, true, $busy);
    \pmssLockHandleRelease($other);
    $GLOBALS['steps'][] = [substr($description, strlen('Recovering required system service ')), $command,
        $other === false && $busy, \pmssLockChildClosePrefix() !== ''];
    if ($GLOBALS['options']['fault'] === 'step') throw new \RuntimeException('fixture fault');
    return $GLOBALS['options']['rc'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/services/systemd/recovery.php', 'RequiredServicesFixture');
        $script .= '$GLOBALS["options"] = '.var_export($options + ['active' => 'inactive', 'enabled' => 'enabled',
            'runtime' => true, 'manager' => ['running'], 'lock' => '', 'fault' => '', 'rc' => 0], true).';';
        $script .= '$GLOBALS["path"] = '.var_export($root.'/update.lock', true).';';
        $script .= <<<'PHP'
$GLOBALS['steps'] = $GLOBALS['logs'] = []; $error = null; $held = false;
if ($GLOBALS['options']['lock'] === 'busy') $held = \pmssLockFileAcquire($GLOBALS['path'], true);
if ($GLOBALS['options']['lock'] === 'unavailable') $GLOBALS['path'] = dirname($GLOBALS['path']);
putenv(PMSS_UPDATE_LOCK_FDS_ENV.'=fixture-prior');
try { pmssRecoverRequiredSystemdServices($GLOBALS['path']); }
catch (\Throwable $failure) { $error = $failure->getMessage(); }
\pmssLockHandleRelease($held);
$after = \pmssLockFileAcquire($GLOBALS['path'], true);
$released = $after !== false;
\pmssLockHandleRelease($after);
echo json_encode(['steps' => $GLOBALS['steps'], 'logs' => $GLOBALS['logs'], 'released' => $released,
    'fds' => getenv(PMSS_UPDATE_LOCK_FDS_ENV), 'error' => $error]);
PHP;
        return $this->pmssRunInlinePhpJson($script);
    }
}
