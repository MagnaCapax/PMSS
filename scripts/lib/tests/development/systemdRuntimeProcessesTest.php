<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdRuntimeProcessesTest extends TestCase
{
    public function testSystemdActionSkipPreservesLogsAndProfileOptOut(): void
    {
        $forwarding = $GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE'];
        $GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE'] = true;
        try {
            foreach ([true, false] as $profile) foreach (['', '0', 'test/dry-run', 'systemd unavailable', 'invalid unit name', 'unit demo.service missing'] as $reason) {
                $this->pmssResetRuntimeProfile();
                [$skipped, $output] = $this->pmssCaptureStdout(static function () use ($reason, $profile): bool { return \pmssSystemdActionSkip($reason, 'Starting demo service', $profile); });
                $description = 'Starting demo service ('.$reason.')';
                $this->assertSame($reason !== '', $skipped);
                $this->assertSame($skipped ? ($profile ? '[SKIP 0.000s rc=0] ' : '[SKIP] ').$description.PHP_EOL : '', $output);
                $this->assertSame($skipped && $profile ? [$description] : [], array_column($GLOBALS['PMSS_PROFILE'] ?? [], 'description'));
            }
        } finally { $GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE'] = $forwarding; }
    }

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
