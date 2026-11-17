<?php
/** Exercise the catalog.php relay command with synthetic proc metadata and command stubs. */
namespace PMSS\Tests\Development;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/agentDiagnostics.php';

use PMSS\Tests\TestCase;

final class AgentDiagnosticsNetRelayTest extends TestCase
{
    public function testRelayCountersHaveUnitsAndDoNotExposeDescriptorNames(): void
    {
        $root = $this->relayFixture();
        $this->relayProcess($root, '101', [13 => '900', 14 => '100', 19 => '3', 21 => '456', 23 => '25']);
        $this->relayProcess($root, '102', [13 => '50', 14 => '25', 19 => '1', 21 => '789', 23 => '0']);
        $before = microtime(true);
        $rows = $this->relayCollect($root, "101\n102\n");
        $after = microtime(true);

        $this->assertSame(2, count($rows));
        $this->pmssAssertArraySubsetSame(['pid' => 101, 'threads' => 3, 'rss_bytes' => 102400,
            'cpu_ticks' => 1000, 'clock_ticks_per_second' => 100, 'start_ticks' => 456,
            'open_descriptors' => 2], $rows[0]);
        $this->pmssAssertArraySubsetSame(['pid' => 102, 'threads' => 1, 'rss_bytes' => 0,
            'cpu_ticks' => 75, 'start_ticks' => 789], $rows[1]);
        foreach ($rows as $row) $this->assertTrue($row['timestamp'] >= $before && $row['timestamp'] <= $after);
        $this->assertStringNotContainsString('private-fixture', json_encode($rows));
    }

    public function testAbsentRelayIsAnEmptyListAndHostSnapshotOmitsTheProbe(): void
    {
        $this->assertSame([], $this->relayCollect($this->relayFixture(), ''));
        $this->assertFalse(isset(\pmssAgentDiagnosticsSectionSpecs()['user_net_relay']));
    }

    public function testVanishedMalformedAndNonRelayProcessesAreSkipped(): void
    {
        $root = $this->relayFixture();
        $this->relayProcess($root, '102', [13 => '-1']);
        $this->relayProcess($root, '103', [19 => 'bad']);
        $this->relayProcess($root, '104', [1 => '(slirp4netns helper)']);
        $this->relayProcess($root, '105', [1 => '(other)']);
        $this->pmssWriteFile($root.'/proc/106/stat', '106 (slirp4netns) S');
        $this->relayProcess($root, '107', []);
        $this->relayProcess($root, '108', []);
        unlink($root.'/proc/108/status');
        $this->relayProcess($root, '109', []);
        $this->pmssWriteFile($root.'/proc/109/status', "Uid:\tinvalid\n");
        $rows = $this->relayCollect($root, "101\n102\n103\n104\n105\n106\n../107\n107\n108\n109\n");
        $this->assertSame([107], array_column($rows, 'pid'));
    }

    public function testForeignEffectiveUidCannotProduceARelayRow(): void
    {
        $root = $this->relayFixture();
        $this->relayProcess($root, '101', []);
        $this->assertSame([], $this->relayCollect($root, "101\n", (string) (fileowner($root) + 1)));
    }

    public function testUnreadableDescriptorsAreUnknownRatherThanZero(): void
    {
        $root = $this->relayFixture();
        $this->relayProcess($root, '101', []);
        rename($root.'/proc/101/fd', $root.'/proc/101/fd-missing');
        $rows = $this->relayCollect($root, "101\n");
        $this->assertSame(null, $rows[0]['open_descriptors']);
    }

    public function testInvalidUidAndClockCannotProduceMisleadingCounters(): void
    {
        $root = $this->relayFixture();
        $this->relayProcess($root, '101', []);
        foreach ([['', '100'], ['unknown', '100'], [(string) fileowner($root), '0']] as $values) {
            $result = $this->relayCollect($root, "101\n", $values[0], $values[1]);
            $this->assertSame(1, $result['rc']);
        }
    }

    public function testAccountArgumentIsQuotedForBothSelectors(): void
    {
        $user = "fixtur01'; printf injected";
        $spec = \pmssAgentDiagnosticsSectionSpecs($user)['user_net_relay'];
        $this->assertSame('command', $spec['type']);
        $this->assertSame('json', $spec['format']);
        $this->assertStringContainsString('id -u '.escapeshellarg($user), $spec['command']);
        $this->assertStringContainsString('pgrep -u '.escapeshellarg($user).' -x slirp4netns', $spec['command']);
    }

    private function relayFixture(): string
    {
        return $this->pmssMakeNamedTempDir('pmss-agent-relay-');
    }

    private function relayProcess(string $root, string $pid, array $overrides): void
    {
        // Full /proc/stat field offsets, including the pid and comm columns.
        $fields = array_replace(array_fill(0, 24, '0'), [0 => $pid, 1 => '(slirp4netns)', 2 => 'S'], $overrides);
        $uid = (string) fileowner($root);
        $this->pmssWriteFiles($root.'/proc/'.$pid, ['stat' => implode(' ', $fields)."\n", 'status' => "Uid:\t$uid\t$uid\t$uid\t$uid\n",
            'fd/0' => '', 'fd/private-fixture' => '']);
    }

    private function relayCollect(string $root, string $pids, ?string $uid = null, string $hz = '100'): array
    {
        $bin = $this->pmssWriteExecutableFiles($root.'/bin', [
            'pgrep' => "#!/bin/sh\n[ \"$1\" = -u ] && [ \"$2\" = fixtur01 ] && [ \"$3\" = -x ] && [ \"$4\" = slirp4netns ] || exit 2\nprintf %s ".escapeshellarg($pids)."\n".($pids === '' ? 'exit 1' : ''),
            'id' => "#!/bin/sh\n[ \"$1\" = -u ] && [ \"$2\" = fixtur01 ] || exit 2\nprintf %s ".escapeshellarg($uid ?? (string) fileowner($root))."\n",
            'getconf' => "#!/bin/sh\nif [ \"$1\" = CLK_TCK ]; then printf %s ".escapeshellarg($hz)."; else printf 4096; fi\n",
        ]);
        $spec = \pmssAgentDiagnosticsSectionSpecs('fixtur01')['user_net_relay'];
        // Rewrite only the proc root; the real catalog command and projection execute on the testing VM.
        $spec['command'] = str_replace('"/proc/"', '"'.$root.'/proc/"', $spec['command']);
        $result = [];
        $this->pmssWithPathPrefix($bin, function () use ($spec, &$result): void {
            $result = \pmssAgentDiagnosticsSpecCollect($spec);
        });
        return $result;
    }
}
