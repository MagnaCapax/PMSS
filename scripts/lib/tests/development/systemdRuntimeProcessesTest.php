<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdRuntimeProcessesTest extends TestCase
{
    public function testSystemdUnitNameIsSafeAcceptsCommonUnitNames(): void
    {
        $this->assertTrue(\pmssSystemdUnitNameIsSafe('lighttpd'));
        $this->assertTrue(\pmssSystemdUnitNameIsSafe('docker.service'));
        $this->assertTrue(\pmssSystemdUnitNameIsSafe('rpcbind.socket'));
        $this->assertTrue(\pmssSystemdUnitNameIsSafe('user@1000.service'));
        $this->assertTrue(\pmssSystemdUnitNameIsSafe('pmss-test_1.slice'));
    }

    public function testSystemdUnitNameIsSafeRejectsUnsafeUnitNames(): void
    {
        $this->assertFalse(\pmssSystemdUnitNameIsSafe(''));
        $this->assertFalse(\pmssSystemdUnitNameIsSafe('  '));
        $this->assertFalse(\pmssSystemdUnitNameIsSafe('-demo.service'));
        $this->assertFalse(\pmssSystemdUnitNameIsSafe('demo service'));
        $this->assertFalse(\pmssSystemdUnitNameIsSafe("demo\nservice"));
        $this->assertFalse(\pmssSystemdUnitNameIsSafe('demo;service'));
    }

    public function testSystemdUnitStateRejectsUnsafeRequestsBeforeShelling(): void
    {
        $this->assertSame(null, \pmssSystemdUnitState('status', 'cron.service'));
        $this->assertSame(null, \pmssSystemdUnitState('is-active', '-cron.service'));
    }

    public function testStopDisableMaskSystemdUnitSkipsInvalidUnitNameDuringDryRun(): void
    {
        $this->pmssResetRuntimeProfile();
        putenv('PMSS_DRY_RUN=1');

        try {
            \pmssStopDisableMaskSystemdUnit('-demo.service', 'Demo', true);
        } finally {
            putenv('PMSS_DRY_RUN');
        }

        $this->assertSame([], $this->pmssProfileCommands());
    }

    public function testSystemdActionSkipReasonRejectsInvalidUnitNameDuringDryRun(): void
    {
        putenv('PMSS_DRY_RUN=1');

        try {
            $this->assertSame('invalid unit name', \pmssSystemdActionSkipReason('-demo.service'));
        } finally {
            putenv('PMSS_DRY_RUN');
        }
    }
}
