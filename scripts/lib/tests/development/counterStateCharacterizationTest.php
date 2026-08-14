<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/log.php';
require_once dirname(__DIR__, 2).'/traffic/ingress.php';

class CounterStateCharacterizationTest extends TestCase
{
    private function makeRoot(): string
    {
        return $this->pmssMakeTempDir('pmss-counter-state-', 0700);
    }

    public function testSharedStateCreatesMissingFileWithSelectedDeltaFields(): void
    {
        $path = $this->makeRoot().'/state.json';
        $state = ['ingress' => 123, 'egress' => 456, 'ts' => 1];

        $result = \pmssCounterStateUpdate($path, $state, ['ingress']);

        $this->assertEquals(['ingress' => 123], $result['delta']);
        $this->assertEquals([], $result['previous_state']);
        $this->assertEquals($state, $this->pmssReadJsonArrayFile($path, []));
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testSharedStateComputesDeltaAgainstPreviousValues(): void
    {
        $path = $this->makeRoot().'/state.json';
        $this->pmssWriteFile($path, json_encode(['ingress' => 100, 'egress' => 200, 'ts' => 1]));

        $result = \pmssCounterStateUpdate($path, ['ingress' => 160, 'egress' => 260, 'ts' => 2], ['ingress', 'egress']);

        $this->assertEquals(['ingress' => 60, 'egress' => 60], $result['delta']);
        $this->assertEquals(['ingress' => 100, 'egress' => 200, 'ts' => 1], $result['previous_state']);
    }

    public function testResourceLogReseedsIoDeltaOnBlkioSourceSwitch(): void
    {
        // #707 §0 phantom-delta guard: the pre-fix state has io_*_ops=0 (throttle-sourced) and
        // no io_source. After the fix reads bfq (a large cumulative), the naive delta would be
        // the FULL cumulative and could trip live monthly-IOPS enforcement. On the source switch
        // the io_* deltas MUST be zeroed for one sample (baseline reseed), then delta normally.
        $path = $this->makeRoot().'/state.json';
        $this->pmssWriteFile($path, json_encode([
            'io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0,
            'cpu_nsec' => 1000, 'memory' => 500, 'tasks' => 3, 'ts' => 1,
        ]));

        $migration = \pmssResourceLogUpdateState($path, [
            'io_read' => 9000, 'io_write' => 8000, 'io_read_ops' => 5000000, 'io_write_ops' => 4000000,
            'cpu_nsec' => 1500, 'memory' => 600, 'tasks' => 4, 'io_source' => 'bfq',
        ]);
        $this->assertSame(0, $migration['delta']['io_read'], 'io_read must reseed to 0 on source switch');
        $this->assertSame(0, $migration['delta']['io_write']);
        $this->assertSame(0, $migration['delta']['io_read_ops'], 'phantom io_read_ops must not reach enforcement');
        $this->assertSame(0, $migration['delta']['io_write_ops']);
        $this->assertSame(500, $migration['delta']['cpu_nsec'], 'non-io delta unaffected by the guard');

        // Subsequent sample: same source, normal delta (guard does not persist).
        $next = \pmssResourceLogUpdateState($path, [
            'io_read' => 9500, 'io_write' => 8100, 'io_read_ops' => 5000100, 'io_write_ops' => 4000050,
            'cpu_nsec' => 2000, 'memory' => 600, 'tasks' => 4, 'io_source' => 'bfq',
        ]);
        $this->assertSame(500, $next['delta']['io_read']);
        $this->assertSame(100, $next['delta']['io_read_ops']);
        $this->assertSame(50, $next['delta']['io_write_ops']);
    }

    public function testSharedStateAcceptsOnlyNonNegativePersistedIntegerCounters(): void
    {
        $path = $this->makeRoot().'/state.json';
        $this->pmssWriteFile($path, json_encode(['ingress' => '100', 'egress' => -1, 'cpu_nsec' => true, 'ts' => 1]));

        $result = \pmssCounterStateUpdate($path, ['ingress' => 160, 'egress' => 50, 'cpu_nsec' => 20, 'ts' => 2], ['ingress', 'egress', 'cpu_nsec']);

        $this->assertEquals(['ingress' => 60, 'egress' => 50, 'cpu_nsec' => 20], $result['delta']);
        $this->assertEquals(['ingress' => '100', 'egress' => -1, 'cpu_nsec' => true, 'ts' => 1], $result['previous_state']);
    }

    public function testSharedStateTreatsCounterResetAsCurrentValue(): void
    {
        $path = $this->makeRoot().'/state.json';
        $this->pmssWriteFile($path, json_encode(['ingress' => 500, 'egress' => 200, 'ts' => 1]));

        $result = \pmssCounterStateUpdate($path, ['ingress' => 40, 'egress' => 80, 'ts' => 2], ['ingress']);

        $this->assertEquals(['ingress' => 40], $result['delta']);
        $this->assertEquals(500, $result['previous_state']['ingress']);
    }

    public function testSharedStateIgnoresInvalidJsonSeed(): void
    {
        $path = $this->makeRoot().'/state.json';
        $this->pmssWriteFile($path, 'not-json');

        $result = \pmssCounterStateUpdate($path, ['ingress' => 77, 'egress' => 88, 'ts' => 2], ['ingress']);

        $this->assertEquals(['ingress' => 77], $result['delta']);
        $this->assertEquals([], $result['previous_state']);
    }

    public function testSharedStateRejectsUnsafePathsWithoutWriting(): void
    {
        $relativePath = 'relative/state.json';
        $relativeResult = \pmssCounterStateUpdate($relativePath, ['ingress' => 123, 'egress' => 456, 'ts' => 1], ['ingress']);
        $this->assertEquals(['ingress' => 123], $relativeResult['delta']);
        $this->assertEquals([], $relativeResult['previous_state']);
        $this->assertTrue(!is_file($relativePath));

        $root = $this->makeRoot();
        $target = $root.'/target.json';
        $this->pmssWriteFile($target, json_encode(['ingress' => 5, 'egress' => 6]));
        $path = $root.'/state.json';
        $this->pmssCreateSymlinkOrSkip($target, $path);

        $result = \pmssCounterStateUpdate($path, ['ingress' => 123, 'egress' => 456, 'ts' => 1], ['ingress']);

        $this->assertEquals(['ingress' => 123], $result['delta']);
        $this->assertEquals([], $result['previous_state']);
        $this->assertEquals(['ingress' => 5, 'egress' => 6], $this->pmssReadJsonArrayFile($target, []));
    }

    public function testIngressStateUsesSharedCounterWriterSnapshot(): void
    {
        $path = $this->makeRoot().'/state.json';

        $result = \pmssTrafficIngressUpdateState($path, ['ingress' => 123, 'egress' => 456]);

        $this->assertEquals(123, $result['delta']);
        $this->assertEquals(null, $result['previous_ingress']);
        $this->assertEquals(['ingress' => 123, 'egress' => 456], array_intersect_key($this->pmssReadJsonArrayFile($path, []), ['ingress' => true, 'egress' => true]));
        $this->pmssAssertRepoFileContainsString('scripts/lib/traffic/ingress.php', 'pmssCounterStateUpdate(');
        $this->pmssAssertRepoFileContainsString('scripts/lib/resources/log.php', 'pmssCounterStateUpdate(');
    }
}
