<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/CodexLauncherTestCase.php';

class CodexRunnerEventsTest extends CodexLauncherTestCase
{
    public function testPriorProtectedEditsAndStagingArePreserved(): void
    {
        $tracked = $this->launcherRoot.'/development/README.md';
        $new = $this->launcherRoot.'/development/operator-note.txt';
        file_put_contents($tracked, "\nOperator fixture edit\n", FILE_APPEND);
        file_put_contents($new, 'operator fixture');
        $before = file_get_contents($tracked);
        $git = 'git -C '.escapeshellarg($this->launcherRoot);
        $this->assertSame(0, $this->pmssExecShellCommand($git.' add development/README.md')['rc']);
        $indexBefore = $this->pmssExecShellCommand($git.' diff --cached')['output'];
        $result = $this->launch('codex-run.sh', ['run', '--prompt', 'fixture']);
        $this->assertSame(3, $result['rc'], $result['output']);
        $this->assertSame($before, file_get_contents($tracked));
        $this->assertSame('operator fixture', file_get_contents($new));
        $this->assertSame($indexBefore, $this->pmssExecShellCommand($git.' diff --cached')['output']);
        $events = $this->events();
        $this->assertSame(3, end($events)['rc']);
    }

    public function testMissingExecutableHasCompleteFailedLifecycle(): void
    {
        $result = $this->launch('codex-run.sh', ['run', '--prompt', 'fixture', '--exec', 'missing-fixture-assistant']);
        $this->assertSame(127, $result['rc'], $result['output']);
        $events = $this->events();
        $this->assertSame(['runner_start', 'assistant_invoke_start', 'assistant_invoke_end', 'runner_end'], array_column($events, 'event'));
        $this->assertSame(127, $events[3]['rc']);
        $this->assertSame('error', $events[3]['level']);
    }

    public function testAssistantFailureIsNotReportedAsSuccess(): void
    {
        $result = $this->launch('codex-run.sh', ['run', '--prompt', 'fixture'], ['PMSS_TEST_CODEX_RC' => '23']);
        $this->assertSame(23, $result['rc'], $result['output']);
        $events = $this->events();
        $this->assertSame(23, end($events)['rc']);
        $this->assertSame('error', $events[2]['level']);
    }

    public function testPromptFailureLogsEndBeforeAnyAssistantStarts(): void
    {
        $result = $this->launch('codex-run.sh', ['run', '--prompt-file', $this->tempDir.'/absent']);
        $this->assertSame(2, $result['rc'], $result['output']);
        $events = $this->events();
        $this->assertSame(['runner_start', 'runner_end'], array_column($events, 'event'));
        $this->assertSame(2, $events[1]['rc']);
        $this->assertFalse(file_exists($this->tempDir.'/assistant.json'));
    }

    public function testUnwritableLogRefusesUnobservedAssistantLaunch(): void
    {
        file_put_contents($this->tempDir.'/not-directory', 'fixture');
        $result = $this->launch('codex-run.sh', ['run', '--prompt', 'fixture', '--event-log', $this->tempDir.'/not-directory/events.jsonl']);
        $this->assertSame(1, $result['rc'], $result['output']);
        $this->assertStringContainsString('cannot append JSONL event', $result['output']);
        $this->assertFalse(file_exists($this->tempDir.'/assistant.json'));
    }

    public function testExplicitLogPathAndControlCharactersRemainValidJson(): void
    {
        $log = $this->tempDir.'/explicit.jsonl';
        $result = $this->launch('codex-run.sh', ['run', '--prompt', 'fixture', '--dry-run', '--event-log', $log],
            ['PMSS_CODEX_RUN_EVENT_LOG' => $this->tempDir.'/ignored.jsonl']);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertFalse(file_exists($this->tempDir.'/ignored.jsonl'));
        $detail = "tab\t newline\n control\x01 backslash\\ quote\"";
        $args = [$log, 'fixture', 'info', 'check', 'fixture-id', '0', '0', $detail, 'fixture'];
        $result = $this->pmssExecShellCommand('php '.escapeshellarg($this->launcherRoot.'/development/lib/codex-events.php').' '
            .implode(' ', array_map('escapeshellarg', $args)));
        $this->assertSame(0, $result['rc'], $result['output']);
        $rows = file($log, FILE_IGNORE_NEW_LINES);
        $this->assertSame(3, count($rows));
        $this->assertSame($detail, json_decode($rows[2], true)['detail']);
    }

    public function testConcurrentExplicitLogAppendsAreComplete(): void
    {
        $log = $this->tempDir.'/concurrent.jsonl';
        $writer = escapeshellarg($this->launcherRoot.'/development/lib/codex-events.php');
        $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\n"
            ."for i in {1..12}; do\nphp ".$writer.' '.escapeshellarg($log)
            ." fixture info check \"fixture-\$i\" 0 0 ".escapeshellarg(str_repeat('payload', 2000))." fixture &\ndone\nwait\n");
        $rows = file($log, FILE_IGNORE_NEW_LINES);
        $this->assertSame(12, count($rows));
        $ids = [];
        foreach ($rows as $row) {
            $event = json_decode($row, true);
            $this->assertTrue(is_array($event));
            $this->assertSame(14000, strlen($event['detail']));
            $ids[] = $event['correlationId'];
        }
        $this->assertSame(12, count(array_unique($ids)));
    }

    public function testInvalidNumericFieldsCannotAppendFalseSuccess(): void
    {
        $log = $this->tempDir.'/invalid.jsonl';
        foreach (['failed', '-1', '0,null'] as $invalid) {
            $args = [$log, 'fixture', 'info', 'check', 'fixture-id', $invalid, '0', 'fixture', 'fixture'];
            $result = $this->pmssExecShellCommand('php '.escapeshellarg($this->launcherRoot.'/development/lib/codex-events.php').' '
                .implode(' ', array_map('escapeshellarg', $args)));
            $this->assertSame(2, $result['rc'], $result['output']);
        }
        $this->assertFalse(file_exists($log));
    }
}
