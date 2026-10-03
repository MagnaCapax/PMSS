<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/CodexLauncherTestCase.php';

class AgenticIssuesEligibilityTest extends CodexLauncherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $gh = <<<'PHP'
#!/usr/bin/env php
<?php
$rows = json_decode(file_get_contents(getenv('PMSS_TEST_ISSUES')), true);
if ($argv[2] === 'list' && in_array('length', $argv, true)) {
    echo count($rows) > 0 ? "1\n" : "0\n";
    exit(0);
}
if ($argv[2] === 'list') {
    foreach ($rows as $row) {
        if (array_intersect($row['labels'], ['needs-investigation', 'blocked', 'complete-verify'])) {
            continue;
        }
        echo $row['number']."\t".$row['title']."\t".implode(',', $row['labels'])."\n";
    }
    exit(0);
}
foreach ($rows as $row) {
    if ((string) $row['number'] !== $argv[3]) {
        continue;
    }
    if (in_array('.updatedAt', $argv, true)) {
        echo $row['updatedAt']."\n";
    } else {
        echo "Title: ".$row['title']."\nLabels: ".implode(', ', $row['labels'])."\n\nBody:\nfixture\n";
    }
    exit(0);
}
exit(1);
PHP;
        $intent = <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(getenv('PMSS_TEST_INTENT_CALLS'), json_encode(array_slice($argv, 1))."\n", FILE_APPEND);
$responses = json_decode(file_get_contents(getenv('PMSS_TEST_INTENT_RESPONSES')), true);
$response = $responses[$argv[1]] ?? ['rc' => 1, 'out' => ''];
echo $response['out'];
exit($response['rc']);
PHP;
        file_put_contents($this->tempDir.'/bin/gh', $gh);
        file_put_contents($this->tempDir.'/bin/intent check', $intent);
        chmod($this->tempDir.'/bin/gh', 0755);
        chmod($this->tempDir.'/bin/intent check', 0755);
        $this->launcherEnv['PMSS_TEST_ISSUES'] = $this->tempDir.'/issues.json';
        $this->launcherEnv['PMSS_TEST_INTENT_CALLS'] = $this->tempDir.'/calls.jsonl';
        $this->launcherEnv['PMSS_TEST_INTENT_RESPONSES'] = $this->tempDir.'/responses.json';
        $this->launcherEnv['PMSS_INTENT_CHECK_CMD'] = $this->tempDir.'/bin/intent check';
        $this->fixture([1001]);
    }

    private function fixture(array $numbers, array $labels = [], array $responses = [], string $updatedAt = '2026-10-03T12:00:00Z'): void
    {
        $rows = [];
        foreach ($numbers as $number) {
            $rows[] = ['number' => $number, 'title' => 'Fixture '.$number,
                'labels' => $labels[$number] ?? ['bug'], 'updatedAt' => $updatedAt];
        }
        file_put_contents($this->launcherEnv['PMSS_TEST_ISSUES'], json_encode($rows));
        file_put_contents($this->launcherEnv['PMSS_TEST_INTENT_RESPONSES'], json_encode($responses));
        @unlink($this->launcherEnv['PMSS_TEST_INTENT_CALLS']);
    }

    private function calls(): array
    {
        $lines = @file($this->launcherEnv['PMSS_TEST_INTENT_CALLS'], FILE_IGNORE_NEW_LINES);
        return $lines === false ? [] : array_map('json_decode', $lines);
    }

    private function select(array $options = [], array $env = []): array
    {
        return $this->launch('agentic-issues.sh', array_merge(['--select-only'], $options), $env);
    }

    public function testDisabledModePreservesBuildReadyOnly(): void
    {
        $command = $this->launcherEnv['PMSS_INTENT_CHECK_CMD'];
        unset($this->launcherEnv['PMSS_INTENT_CHECK_CMD']);
        foreach ([[], ['PMSS_INTENT_CHECK_CMD' => ''],
            ['PMSS_INTENT_CHECK_CMD' => $this->launcherEnv['PMSS_TEST_ISSUES']]] as $env) {
            $run = $this->select([], $env);
            $this->assertSame(0, $run['rc'], $run['output']);
            $this->assertStringContainsString('intent check disabled', $run['output']);
            $this->assertStringContainsString('No approved issues after gate', $run['output']);
        }
        $this->launcherEnv['PMSS_INTENT_CHECK_CMD'] = $command;
        $this->assertSame([], $this->calls());
    }

    public function testBuildReadyDoesNotCallIntentCheck(): void
    {
        $this->fixture([1001], [1001 => ['bug', 'build-ready']]);
        $run = $this->select();
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertStringContainsString('selected 1 issue(s): 1001', $run['output']);
        $this->assertSame([], $this->calls());
    }

    public function testClearRequiresExitZeroAndTimestampAndSingleArgument(): void
    {
        $timestamp = '2026-10-03T12:00:00Z';
        foreach ([
            ['rc' => 0, 'out' => "CLEAR updated_at=$timestamp\n", 'selected' => true],
            ['rc' => 1, 'out' => "CLEAR updated_at=$timestamp\n", 'selected' => false],
            ['rc' => 0, 'out' => "ordinary output\n", 'selected' => false],
            ['rc' => 0, 'out' => "CLEAR\n", 'selected' => false],
        ] as $case) {
            $this->fixture([1001], [], [1001 => $case]);
            $run = $this->select();
            $this->assertSame(0, $run['rc'], $run['output']);
            $this->assertSame($case['selected'], strpos($run['output'], 'selected 1 issue(s): 1001') !== false);
            $this->assertSame([['1001']], $this->calls());
        }
    }

    public function testCallCapAndMaxIssuesAvoidExtraCalls(): void
    {
        $this->fixture([1001, 1002, 1003, 1004, 1005]);
        $run = $this->select();
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertSame(3, count($this->calls()));
        $this->assertSame(1, substr_count($run['output'], 'call cap (3) reached'));

        $clear = ['rc' => 0, 'out' => "CLEAR updated_at=2026-10-03T12:00:00Z\n"];
        $this->fixture([1001, 1002, 1003], [], [1001 => $clear, 1002 => $clear]);
        $run = $this->select(['--max-issues', '1']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertSame([['1001']], $this->calls());
    }

    public function testParkedLabelsNeverReachIntentCheck(): void
    {
        $this->fixture([1001, 1002, 1003], [1001 => ['needs-investigation'],
            1002 => ['blocked'], 1003 => ['complete-verify']]);
        $run = $this->select();
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertSame([], $this->calls());
    }

    public function testFreshnessSkipsChangedIssueAndAllowsMatchingTimestamp(): void
    {
        $clear = ['rc' => 0, 'out' => "CLEAR updated_at=2026-10-03T12:00:00Z\n"];
        $this->fixture([1001], [], [1001 => $clear], '2026-10-03T13:00:00Z');
        $run = $this->launch('agentic-issues.sh', ['--exec', '/bin/true']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertStringContainsString('changed after intent check', $run['output']);
        $this->assertStringContainsString('No issues left after freshness check', $run['output']);
        $this->assertFalse(strpos($run['output'], '[agentic-issues] done') !== false);

        $this->fixture([1001], [], [1001 => $clear]);
        $run = $this->launch('agentic-issues.sh', ['--exec', '/bin/true']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertStringContainsString('[agentic-issues] done', $run['output']);
    }
}
