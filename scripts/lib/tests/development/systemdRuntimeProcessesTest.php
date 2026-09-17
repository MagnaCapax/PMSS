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
        // Which global logmsg() won the load race decides whether the plain (non-profile)
        // [SKIP] line is visible to ob_start(). scripts/lib/log.php forwards to logMessage(),
        // which echoes -> buffered and capturable. The update.php rescue logger writes with
        // fwrite(STDOUT, ...) on purpose (unbuffered survives a fatal) -> invisible to
        // ob_get_clean(). The Runner loads the update bootstrap shim first, so the rescue
        // logger normally wins here while an isolated run gets log.php. Assert the contract
        // that holds either way instead of pinning one load order.
        $logger = new \ReflectionFunction('logmsg');
        $plainLineIsBuffered = realpath((string) $logger->getFileName()) === realpath(dirname(__DIR__, 2).'/log.php');
        try {
            foreach ([true, false] as $profile) foreach (['', '0', 'test/dry-run', 'systemd unavailable', 'invalid unit name', 'unit demo.service missing'] as $reason) {
                $this->pmssResetRuntimeProfile();
                [$skipped, $output] = $this->pmssCaptureStdout(static function () use ($reason, $profile): bool { return \pmssSystemdActionSkip($reason, 'Starting demo service', $profile); });
                $description = 'Starting demo service ('.$reason.')';
                $this->assertSame($reason !== '', $skipped);
                $expectedOutput = '';
                if ($skipped && $profile) {
                    $expectedOutput = '[SKIP 0.000s rc=0] '.$description.PHP_EOL;
                } elseif ($skipped && $plainLineIsBuffered) {
                    $expectedOutput = '[SKIP] '.$description.PHP_EOL;
                }
                $this->assertSame($expectedOutput, $output);
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

    public function testSystemdValidatorsRejectNulBeforeTrimming(): void
    {
        foreach (["\0%s", "%s\0", " \0%s\t", "\n%s\0 ", "%s\0suffix", "\0"] as $format) {
            $unit = sprintf($format, 'demo.service');
            $action = sprintf($format, 'restart');
            $this->assertFalse(\pmssSystemdUnitNameIsSafe($unit));
            $this->assertFalse(\pmssSystemdUnitActionNameIsSafe($action));
            $this->assertSame(null, \pmssSystemdUnitState('is-active', $unit));
            $this->assertSame(null, \pmssSystemdUnitQuietStatus('is-enabled', $unit));
            $this->assertFalse(\pmssSystemdUnitExists($unit));
        }
    }

    public function testSystemdMalformedArgumentsKeepSkipPathsInDryRun(): void
    {
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            foreach (["\0%s", "%s\0", " \0%s\t", "\n%s\0 ", "%s\0suffix"] as $format) {
                $unit = sprintf($format, 'demo.service');
                $this->pmssResetRuntimeProfile();
                $this->assertSame('invalid unit name', \pmssSystemdActionSkipReason($unit));
                // Dry-run profiles expose command construction without invoking systemctl.
                \pmssSystemdUnitActionIfPresent($unit, 'Starting demo', 'start');
                \pmssStopDisableMaskSystemdUnit($unit, 'Demo', true);
                \pmssSystemdUnitActionIfPresent('demo.service', 'Restarting demo', sprintf($format, 'restart'));
                $this->assertSame([], $this->pmssProfileCommands());
            }
        });
    }

    public function testSystemdValidActionsPreserveWhitespaceAndCommands(): void
    {
        $actions = ['disable', 'enable', 'mask', 'reload', 'restart', 'start', 'stop', 'try-reload-or-restart', 'try-restart', 'unmask'];
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use ($actions): void {
            foreach ($actions as $action) {
                foreach (['%s', " \t%s\r\n"] as $format) {
                    $this->assertTrue(\pmssSystemdUnitNameIsSafe(sprintf($format, 'demo.service')));
                    $this->assertTrue(\pmssSystemdUnitActionNameIsSafe(sprintf($format, $action)));
                    $this->pmssResetRuntimeProfile();
                    \pmssSystemdUnitActionIfPresent('demo', 'Updating demo', sprintf($format, $action));
                    $target = $action === 'enable' ? 'demo.service' : 'demo';
                    $this->assertSame(["systemctl ".$action." '".$target."'"], $this->pmssProfileCommands());
                }
            }
        });
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
