<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

/** Locks scripts/lib/update/systemPrep/locale.php without reading or changing host locale state. */
class LocaleBaselineCharacterizationTest extends TestCase
{
    private const LINE = 'en_US.UTF-8 UTF-8';
    private const DEFAULTS = "LANG=en_US.UTF-8\nLC_TIME=en_US.UTF-8\n";

    private function observe(array $scenario = []): array
    {
        $stubs = <<<'PHP'
namespace PMSS\LocaleBaselineFixture;
function record($event) {
    $GLOBALS['events'][] = $event;
    if (($GLOBALS['scenario']['throwAt'] ?? -1) === count($GLOBALS['events'])) {
        $type = $GLOBALS['scenario']['throwType']; throw new $type('fixture failure');
    }
}
function file_get_contents($path) { record(['read', $path]); return $GLOBALS['scenario']['files'][$path]; }
function exec($command, &$output) { record(['exec', $command]); $output = $GLOBALS['scenario']['available']; return false; }
function logMessage($message) { record(['log', $message]); }
function runStep($label, $command) { record(['step', $label, $command]); return $GLOBALS['scenario']['rc'] ?? 0; }
function pmssLocaleGenWriteComplete($path, $content) { record(['write', $path, $content]); return $GLOBALS['scenario']['write'] ?? true; }
function motdGenerate() { record(['motd']); }
PHP;
        $scenario['files'] = ($scenario['files'] ?? []) + ['/etc/locale.gen' => self::LINE."\n",
            '/etc/default/locale' => self::DEFAULTS, '/etc/timezone' => "Europe/Helsinki\n"];
        $scenario += ['available' => ['en_US.utf8']];
        $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/runtime/config.php'), true).';'
            .'eval('.var_export($stubs, true).');'
            .\pmssTestingFunctionExport('pmssEnsureLocaleBaseline', 'PMSS\\LocaleBaselineFixture', [
                "require_once dirname(__DIR__).'/../motd/Generator.php';" => '',
                "require_once dirname(__DIR__, 3).'/motd/Generator.php';" => '',
                '\\Motd::motdGenerate();' => 'motdGenerate();',
            ])
            .'$GLOBALS["scenario"] = '.var_export($scenario, true).'; $GLOBALS["events"] = []; $error = null;'
            .'try { \\PMSS\\LocaleBaselineFixture\\pmssEnsureLocaleBaseline(); }'
            .'catch (\\Throwable $caught) { $error = [get_class($caught), $caught->getMessage()]; }'
            .'echo json_encode([$GLOBALS["events"], $error]);';
        $run = $this->pmssExecInlinePhpWithTempStderr($script);
        $this->assertSame(0, $run['result']['rc'], $this->pmssReadFileOrEmpty($run['stderrPath']));
        return $this->pmssDecodeJsonArray($run['result']['output']);
    }

    private function trace(): array
    {
        return [['read', '/etc/locale.gen'], ['exec', 'locale -a 2>/dev/null'],
            ['log', '[SKIP] en_US.UTF-8 already generated'], ['read', '/etc/default/locale'],
            ['log', '[SKIP] Default system locale already set to en_US.UTF-8 (LC_TIME=en_US.UTF-8)'],
            ['read', '/etc/timezone'], ['log', '[SKIP] System timezone already set to Europe/Helsinki'], ['motd']];
    }

    public function testConvergedLocalePreservesAcceptedLibcSpellingsAndPrefixMatching(): void
    {
        foreach (['en_US.UTF-8', 'en_US.utf8', 'EN_us.UtF8'] as $available) {
            $this->assertSame([$this->trace(), null], $this->observe(['available' => ['', 'C', " $available ", 'other'],
                'files' => ['/etc/locale.gen' => "# disabled\r\n eN_uS.uTf-8 UTF-8 trailing text\r\n"]]));
        }
        foreach ([[], ['en_US'], ['en_US.UTF-8-extra'], ['#en_US.utf8']] as $available) {
            $trace = $this->trace();
            $trace[2] = ['step', 'Generating en_US.UTF-8 locale', 'locale-gen en_US.UTF-8'];
            $this->assertSame([$trace, null], $this->observe(['available' => $available]));
        }
    }

    public function testMissingAndAppendedLocaleFilesKeepBytesWarningsAndGeneration(): void
    {
        foreach ([false, '', "other UTF-8\r\n\n"] as $gen) {
            foreach ([true, false] as $written) {
                $content = $gen === false ? self::LINE."\n" : rtrim($gen, "\r\n")."\n".self::LINE."\n";
                $message = $gen === false
                    ? ($written ? '[WARN] /etc/locale.gen missing; created with ' : '[WARN] Unable to create /etc/locale.gen with ')
                    : ($written ? 'Appended ' : '[WARN] Unable to append ');
                $message .= self::LINE.($gen === false ? '' : ' to /etc/locale.gen');
                $trace = array_merge([['read', '/etc/locale.gen'], ['write', '/etc/locale.gen', $content], ['log', $message]],
                    array_slice($this->trace(), 1));
                $trace[4] = ['step', 'Generating en_US.UTF-8 locale', 'locale-gen en_US.UTF-8'];
                $this->assertSame([$trace, null], $this->observe(['files' => ['/etc/locale.gen' => $gen], 'write' => $written, 'rc' => 1]));
            }
        }
    }

    public function testCommentedLocaleRetainsSedAndRegenerationEvenIfAlreadyAvailable(): void
    {
        $trace = $this->trace();
        $trace[2] = ['step', 'Generating en_US.UTF-8 locale', 'locale-gen en_US.UTF-8'];
        array_splice($trace, 1, 0, [['step', 'Enabling en_US.UTF-8 in /etc/locale.gen',
            "sed -i 's/^# *en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/' /etc/locale.gen"]]);
        $this->assertSame([$trace, null], $this->observe(['files' => ['/etc/locale.gen' => '# '.self::LINE."\n"]]));
    }

    public function testDefaultsRetainCaseInsensitiveLastAssignmentAndLiteralValues(): void
    {
        foreach ([self::DEFAULTS, "# ignored\r\nlang=other\r\n LaNg= en_US.UTF-8 \r\nlc_time=en_US.UTF-8\n"] as $data) {
            $this->assertSame([$this->trace(), null], $this->observe(['files' => ['/etc/default/locale' => $data]]));
        }
        foreach ([false, '', 'LANG=en_US.UTF-8', self::DEFAULTS.'lang=other',
            'LANG="en_US.UTF-8"'."\nLC_TIME=en_US.UTF-8", "LANG =en_US.UTF-8\nLC_TIME=en_US.UTF-8",
            self::DEFAULTS.'LC_TIME=en_US.UTF-8=extra'] as $data) {
            $trace = $this->trace();
            $trace[4] = ['step', 'Setting default system locale', 'update-locale LANG=en_US.UTF-8 LC_TIME=en_US.UTF-8'];
            $this->assertSame([$trace, null], $this->observe(['files' => ['/etc/default/locale' => $data]]));
        }
    }

    public function testTimezoneAndMotdFollowLocaleEvenAfterFailedCommands(): void
    {
        $trace = $this->trace();
        $trace[6] = ['step', 'Setting system timezone to Europe/Helsinki', "timedatectl set-timezone Europe/Helsinki 2>/dev/null || (ln -sf /usr/share/zoneinfo/Europe/Helsinki /etc/localtime && echo 'Europe/Helsinki' > /etc/timezone)"];
        foreach ([false, '', 'Etc/UTC'] as $timezone) {
            $this->assertSame([$trace, null], $this->observe(['files' => ['/etc/timezone' => $timezone], 'rc' => 1]));
        }
    }

    public function testExceptionsAndErrorsPropagateWithoutRunningLaterStages(): void
    {
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ($this->trace() as $index => $event) {
                $this->assertSame([array_slice($this->trace(), 0, $index + 1), [$type, 'fixture failure']],
                    $this->observe(['throwAt' => $index + 1, 'throwType' => $type]));
            }
        }
    }
}
