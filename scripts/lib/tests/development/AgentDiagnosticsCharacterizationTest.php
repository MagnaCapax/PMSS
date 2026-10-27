<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/agentDiagnostics.php';

class AgentDiagnosticsCharacterizationTest extends TestCase
{
    /** Lock decoding, fallback and wrapping, including their format-specific precedence. */
    public function testResultShapes(): void
    {
        foreach ([0, 7, 124] as $rc) {
            foreach ([
                ['text', "  value\n", 'value'],
                ['text', '', 'fallback'],
                ['text', '0', '0'],
                ['other', "  value\n", 'value'],
                ['int', ' 42tail ', 42],
                ['int', '', 0],
                ['lines', " a\n\n0\nb \n", $rc === 0 ? ['a', '0', 'b'] : []],
                ['json', '{"a":1}', $rc === 0 ? ['a' => 1] : ['error' => 'probe failed', 'rc' => $rc, 'stderr' => 'error']],
                ['json', ' null ', $rc === 0 ? ['error' => 'probe returned invalid JSON', 'rc' => 0, 'stdout' => 'null'] : ['error' => 'probe failed', 'rc' => $rc, 'stderr' => 'error']],
            ] as [$format, $stdout, $expected]) {
                foreach ([false, true] as $wrap) {
                    $spec = ['type' => 'command', 'command' => 'probe', 'format' => $format, 'fallback' => 'fallback'];
                    if ($wrap) $spec['wrap'] = 'raw';
                    $wrapped = $wrap && !in_array($format, ['int', 'lines', 'json'], true);
                    $actual = $this->collect($spec, ['rc' => $rc, 'stdout' => $stdout, 'stderr' => " error\n"]);
                    $this->assertSame($wrapped ? ['raw' => $expected] : $expected, $actual['value']);
                    $this->assertSame([['command', 'probe', 60, false, 'Failed to launch command']], $actual['trace']);
                }
            }
        }
    }

    /** File payloads retain whitespace and ignore command formatting/fallback fields. */
    public function testFilesAndNestedAcquisitionOrder(): void
    {
        $file = ['type' => 'file', 'env' => 'FIXTURE_PATH', 'path' => '/fixture', 'format' => 'json', 'fallback' => 'unused'];
        foreach ([false, '', " raw\n", '0'] as $raw) {
            foreach ([false, true] as $wrap) {
                $spec = $file;
                if ($wrap) $spec['wrap'] = 'raw';
                $value = is_string($raw) ? $raw : '';
                $this->assertSame($wrap ? ['raw' => $value] : $value, $this->collect($spec, [], $raw)['value']);
            }
        }
        $spec = ['file' => $file, 'nested' => [
            'php' => ['type' => 'php', 'path' => 'util/probe.php', 'args' => ['--json'], 'format' => 'json', 'timeout' => '2'],
            'shell' => ['type' => 'command', 'command' => 'probe', 'timeout' => 99],
        ]];
        $actual = $this->collect($spec, ['rc' => 0, 'stdout' => '{"ok":true}'], " file\n");
        $this->assertSame(['file' => " file\n", 'nested' => ['php' => ['ok' => true], 'shell' => '{"ok":true}']], $actual['value']);
        $this->assertSame([
            ['file', '/fixture'],
            ['php', 'util/probe.php', ['--json'], 2],
            ['command', 'probe', 60, false, 'Failed to launch command'],
        ], $actual['trace']);
    }

    /** Text sections retain exact framing, field order and trailing newline handling. */
    public function testTextBytes(): void
    {
        $this->assertSame("PMSS Agent Diagnostics\ntimestamp: fixed\nhostname: fixture\nversion: v\nversion_completed: \nuser: -\n\n== raw ==\n line\n\n== data ==\n{\n    \"ok\": true\n}\n",
            \pmssAgentDiagnosticsRenderText(['timestamp' => 'fixed', 'hostname' => 'fixture', 'version' => 'v',
                'sections' => ['raw' => " line \n\n", 'data' => ['ok' => true]]]));
    }

    /** Accept only positive integers or decimal strings, retaining the sixty-second cap. */
    public function testTimeoutAcceptance(): void
    {
        foreach ([null, false, true, [], 0, -1, 1.0, '', '0', '00', '-1', '+1', ' 1', "1\0", 'bad'] as $value) {
            $this->assertSame(60, \pmssAgentDiagnosticsSpecTimeout(['timeout' => $value]));
        }
        foreach ([1, '01', 59, '59', 60, '60', PHP_INT_MAX, str_repeat('9', 40)] as $value) {
            $this->assertSame(min(60, (int) $value), \pmssAgentDiagnosticsSpecTimeout(['timeout' => $value]));
        }
    }

    /** Missing scripts retain the diagnostic name selected by each root layout. */
    public function testScriptRootLayouts(): void
    {
        $root = $this->pmssMakeNamedTempDir('pmss-agent-layout-');
        $this->pmssWithEnv(['PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => $root], function () use ($root): void {
            foreach ([false, true] as $repositoryLayout) {
                if ($repositoryLayout) mkdir($root.'/scripts');
                $expected = $repositoryLayout ? 'scripts/missing.php' : 'missing.php';
                $this->assertSame(['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script missing or unreadable: '.$expected],
                    \pmssAgentDiagnosticsPhpScript('missing.php'));
                $this->assertSame(['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script missing or unreadable: scripts/missing.php'],
                    \pmssAgentDiagnosticsPhpScript('scripts/missing.php'));
            }
        });
        // Moving the executor must retain /scripts as the default, even outside an installed tree.
        $this->pmssWithEnv(['PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT' => null], function (): void {
            $result = \pmssAgentDiagnosticsPhpScript('util/agentDiagnostics.php', ['--help']);
            $this->assertSame(0, $result['rc']);
            $this->assertStringContainsString('agentDiagnostics.php [--json]', $result['stdout']);
        });
    }

    /** Reflect the live implementation into an isolated process with in-memory probes. */
    private function collect(array $spec, array $result, $raw = false): array
    {
        $script = 'namespace DiagnosticsCharacterization; require '.var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).';'
            .'const PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT = 60;'
            .'function pmssCommandCapture(...$args) { $GLOBALS["trace"][] = array_merge(["command"], $args); return $GLOBALS["result"]; }'
            .'function pmssAgentDiagnosticsPhpScript(...$args) { $GLOBALS["trace"][] = array_merge(["php"], $args); return $GLOBALS["result"]; }'
            .'function file_get_contents($path) { $GLOBALS["trace"][] = ["file", $path]; return $GLOBALS["raw"]; }';
        foreach (get_defined_functions()['user'] as $name) {
            if (strpos(strtolower($name), 'pmssagentdiagnostics') !== 0 || strtolower($name) === 'pmssagentdiagnosticsphpscript') continue;
            $function = new \ReflectionFunction($name);
            $source = implode('', array_slice(file($function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
            $script .= 'eval('.var_export('declare(strict_types=1); namespace DiagnosticsCharacterization; '.$source, true).');';
        }
        $script .= '$GLOBALS["trace"] = []; $GLOBALS["result"] = '.var_export($result, true).'; $GLOBALS["raw"] = '.var_export($raw, true).';';
        return $this->pmssRunInlinePhpJson($script.'echo json_encode(["value" => pmssAgentDiagnosticsSpecCollect('.var_export($spec, true).'), "trace" => $GLOBALS["trace"]]);');
    }
}
