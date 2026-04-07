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
        $this->assertStringContainsString('pmssCounterStateUpdate(', $this->pmssReadRepoFile('scripts/lib/traffic/ingress.php'));
        $this->assertStringContainsString('pmssCounterStateUpdate(', $this->pmssReadRepoFile('scripts/lib/resources/log.php'));
    }
}
