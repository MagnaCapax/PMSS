<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdRuntimeProcessesTest extends TestCase
{
    public function testSystemdUnitNameIsSafeMatrix(): void
    {
        foreach ([
            'lighttpd' => true,
            'docker.service' => true,
            'rpcbind.socket' => true,
            'user@1000.service' => true,
            'pmss-test_1.slice' => true,
            '' => false,
            '  ' => false,
            '-demo.service' => false,
            'demo service' => false,
            "demo\nservice" => false,
            'demo;service' => false,
        ] as $unit => $expected) {
            $this->assertSame($expected, \pmssSystemdUnitNameIsSafe($unit), var_export($unit, true).' unit safety classification');
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
