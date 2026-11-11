<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

/** Locks scripts/lib/update/systemPrep/bootTuning.php through its historical facade.
 * Also covers delivery of scripts/lib/update/systemPrep/tempMount.php and
 * scripts/lib/update/systemPrep/shellDefaults.php; their runtime scenarios remain
 * in TempDiskBackedMountTest and RootShellDefaultsSafetyTest.
 */
class BootTuningStagesCharacterizationTest extends TestCase
{
    private const SCRIPT = '/fixture/config/template.pmss-boot-tuning.sh';
    private const SERVICE = '/fixture/config/template.systemd.pmss-boot-tuning.service';
    private const SCRIPT_TARGET = '/usr/local/sbin/pmss-boot-tuning.sh';
    private const SERVICE_TARGET = '/etc/systemd/system/pmss-boot-tuning.service';

    public function testHistoricalFacadeLoadsEveryBaselineHelper(): void
    {
        foreach (['pmssConfigureTempDiskBackedMount', 'pmssEnsureBootTuning', 'pmssLocaleGenWriteComplete',
            'pmssEnsureLocaleBaseline', 'pmssConfigureRootShellDefaults'] as $helper) {
            $this->assertTrue(function_exists($helper), $helper);
        }
    }

    /** Export only the controller; every host operation records an in-memory event. */
    private function observe(array $scenario = []): array
    {
        $stubs = <<<'PHP'
namespace PMSS\BootTuningStagesFixture;
function record($event) {
    $GLOBALS['events'][] = $event;
    if (($GLOBALS['scenario']['throwAt'] ?? -1) === count($GLOBALS['events'])) {
        $type = $GLOBALS['scenario']['throwType'];
        throw new $type('fixture failure');
    }
}
function pmssResolvePathFromEnv($key, $default) { return '/fixture/config'; }
function is_file($path) { record(['exists', $path]); return ($GLOBALS['scenario']['missing'] ?? '') !== $path; }
function file_get_contents($path) {
    record(['read', $path]);
    return ($GLOBALS['scenario']['unreadable'] ?? '') === $path ? false : 'raw %%PMSS_BOOT_TUNING_SCRIPT%%';
}
function pmssInstallManagedPathFile($path, $content, $label, $log, $options) {
    record(['write', $path, $content, $label, $options]); return $GLOBALS['scenario']['write'] ?? true;
}
function pmssSystemdActionSkipReason($unit, $test, $dry) { record(['reason', $unit, $test, $dry]); return 'fixture'; }
function pmssSystemdActionSkip($reason, $label) { record(['skip', $reason, $label]); return $GLOBALS['scenario']['skip'] ?? false; }
function runStep($label, $command) { record(['step', $label, $command]); return $GLOBALS['scenario']['rc'] ?? 0; }
PHP;
        $script = 'eval('.var_export($stubs, true).');'
            .\pmssTestingFunctionExport('pmssEnsureBootTuning', 'PMSS\\BootTuningStagesFixture')
            .'$GLOBALS["scenario"] = '.var_export($scenario, true).'; $GLOBALS["events"] = []; $error = null;'
            .'try { \\PMSS\\BootTuningStagesFixture\\pmssEnsureBootTuning('
            .'static function ($message) { \\PMSS\\BootTuningStagesFixture\\record(["log", $message]); }, '
            .var_export($scenario['scriptTarget'] ?? null, true).', '.var_export($scenario['serviceTarget'] ?? null, true).');'
            .'} catch (\\Throwable $caught) { $error = [get_class($caught), $caught->getMessage()]; }'
            .'echo json_encode([$GLOBALS["events"], $error]);';
        $run = $this->pmssExecInlinePhpWithTempStderr($script);
        $this->assertSame(0, $run['result']['rc'], $this->pmssReadFileOrEmpty($run['stderrPath']));
        return $this->pmssDecodeJsonArray($run['result']['output']);
    }

    private function trace(string $script = self::SCRIPT_TARGET, string $service = self::SERVICE_TARGET): array
    {
        return [
            ['exists', self::SCRIPT], ['exists', self::SERVICE], ['read', self::SCRIPT], ['read', self::SERVICE],
            ['write', $script, 'raw %%PMSS_BOOT_TUNING_SCRIPT%%', 'Boot tuning script', ['mode' => 0755]],
            ['write', $service, 'raw '.$script, 'Boot tuning service', ['mode' => 0644]],
            ['reason', null, true, true], ['skip', 'fixture', 'Enabling PMSS boot tuning service'],
            ['step', 'Reloading systemd unit files (PMSS boot tuning)', 'systemctl daemon-reload || true'],
            ['step', 'Enabling PMSS boot tuning service', 'systemctl enable pmss-boot-tuning.service || true'],
            ['step', 'Starting PMSS boot tuning service', 'systemctl start pmss-boot-tuning.service || true'],
        ];
    }

    public function testSuccessfulDefaultAndExplicitTargetsRetainBytesModesAndOrder(): void
    {
        $this->assertSame([$this->trace(), null], $this->observe());
        $this->assertSame([$this->trace('/fixture/custom-script', '/fixture/custom.service'), null],
            $this->observe(['scriptTarget' => '/fixture/custom-script', 'serviceTarget' => '/fixture/custom.service']));
    }

    public function testMissingAndUnreadableTemplatesNeverPublish(): void
    {
        foreach ([self::SCRIPT, self::SERVICE] as $index => $path) {
            $label = $index === 0 ? 'Boot tuning script' : 'Boot tuning service';
            $events = array_slice($this->trace(), 0, $index + 1);
            $events[] = ['log', '[SKIP] '.$label.' template missing: '.$path];
            $this->assertSame([$events, null], $this->observe(['missing' => $path]));
            $events = array_slice($this->trace(), 0, $index + 3);
            $events[] = ['log', '[WARN] Unable to read '.strtolower($label).' template: '.$path];
            $this->assertSame([$events, null], $this->observe(['unreadable' => $path]));
        }
    }

    public function testFailedWritesAndCommandsRetainBestEffortProgressAndSkipOrder(): void
    {
        $this->assertSame([$this->trace(), null], $this->observe(['write' => false, 'rc' => 1]));
        $this->assertSame([array_slice($this->trace(), 0, 8), null], $this->observe(['skip' => true]));
    }

    public function testExceptionsAndErrorsStopAtTheirOriginalStage(): void
    {
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ($this->trace() as $index => $event) {
                $this->assertSame([array_slice($this->trace(), 0, $index + 1), [$type, 'fixture failure']],
                    $this->observe(['throwAt' => $index + 1, 'throwType' => $type]));
            }
        }
    }
}
