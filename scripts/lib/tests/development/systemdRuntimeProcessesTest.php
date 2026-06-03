<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdRuntimeProcessesTest extends TestCase
{
    public function testSystemdUnitNameIsSafeAcceptsCommonUnitNames(): void
    {
        foreach (['lighttpd', 'docker.service', 'rpcbind.socket', 'user@1000.service', 'pmss-test_1.slice'] as $unit) {
            $this->assertTrue(\pmssSystemdUnitNameIsSafe($unit), $unit.' should be accepted');
        }
    }

    public function testSystemdUnitNameIsSafeRejectsUnsafeUnitNames(): void
    {
        foreach (['', '  ', '-demo.service', 'demo service', "demo\nservice", 'demo;service'] as $unit) {
            $this->assertFalse(\pmssSystemdUnitNameIsSafe($unit), var_export($unit, true).' should be rejected');
        }
    }

    public function testSystemdUnitStateRejectsUnsafeRequestsBeforeShelling(): void
    {
        $this->assertSame(null, \pmssSystemdUnitState('status', 'cron.service'));
        $this->assertSame(null, \pmssSystemdUnitState('is-active', '-cron.service'));
    }

    public function testStopDisableMaskSystemdUnitSkipsInvalidUnitNameDuringDryRun(): void
    {
        $this->pmssResetRuntimeProfile();
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            \pmssStopDisableMaskSystemdUnit('-demo.service', 'Demo', true);
        });

        $this->assertSame([], $this->pmssProfileCommands());
    }

    public function testSystemdActionSkipReasonRejectsInvalidUnitNameDuringDryRun(): void
    {
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            $this->assertSame('invalid unit name', \pmssSystemdActionSkipReason('-demo.service'));
        });
    }
}
