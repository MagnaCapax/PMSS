<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/traffic/ingress.php';

class TrafficIngressHelpersTest extends TestCase
{
    public function testEnsureDirRejectsRelative(): void
    {
        $this->assertTrue(!\pmssEnsureSafeDir('relative/path', 0700));
    }

    public function testEnsureDirCreatesDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        $path = $root.'/state';
        $this->assertTrue(\pmssEnsureSafeDir($path, 0700));
        $this->assertTrue(is_dir($path));
    }

    public function testEnsureDirRejectsSymlink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        [$target, $link] = $this->pmssCreateSymlinkedDirectoryOrSkip($root.'/target', $root.'/link', 0700);
        $this->assertTrue(!\pmssEnsureSafeDir($link, 0700));
    }

    public function testEnsureDirRejectsSymlinkedParentDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        [$target, $symlinkedParent] = $this->pmssCreateSymlinkedDirectoryOrSkip($root.'/target', $root.'/state', 0700);

        $this->assertTrue(!\pmssEnsureSafeDir($symlinkedParent.'/daily', 0700));
        $this->assertTrue(!is_dir($target.'/daily'), 'must not create directories via symlinked parent');
    }

    public function testUpdateStateMissingReturnsCurrentCountersAndWritesState(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        $path = $root.'/state.json';
        $result = \pmssTrafficIngressUpdateState($path, ['ingress' => 123, 'egress' => 456]);
        $this->assertEquals(123, $result['delta']);
        $this->assertEquals(null, $result['previous_ingress']);
        $loaded = $this->pmssReadJsonArrayFile($path, []);
        $this->assertEquals(123, $loaded['ingress']);
        $this->assertEquals(456, $loaded['egress']);
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testUpdateStateHandlesStoredStateScenarios(): void
    {
        $cases = [
            [json_encode(['ingress' => 100, 'egress' => 200, 'ts' => 1]), ['ingress' => 160, 'egress' => 260], 60, 100],
            [json_encode(['ingress' => 500, 'egress' => 200, 'ts' => 1]), ['ingress' => 40, 'egress' => 80], 40, 500],
            ['not-json', ['ingress' => 77, 'egress' => 88], 77, null],
        ];
        foreach ($cases as [$seed, $counters, $expectedDelta, $expectedPrevious]) {
            $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
            $path = $root.'/state.json';
            $this->pmssWriteFile($path, $seed);
            $result = \pmssTrafficIngressUpdateState($path, $counters);
            $this->assertEquals($expectedDelta, $result['delta']);
            $this->assertEquals($expectedPrevious, $result['previous_ingress']);
        }
    }

    public function testReadCountersHandlesPresentAndMissingMetrics(): void
    {
        $cases = [
            [['IPIngressBytes=123', 'IPEgressBytes=456'], ['ingress' => 123, 'egress' => 456]],
            [['IPIngressBytes=123'], null],
        ];
        foreach ($cases as [$output, $expected]) {
            $binDir = $this->pmssMakeLineOutputStub('systemctl', $output, 'pmss-ingress-systemctl-');
            $this->pmssWithPathPrefix($binDir, function () use ($expected): void {
                $this->assertEquals($expected, \pmssTrafficIngressReadCounters(1000));
            });
        }
    }

    public function testUpdateStateRejectsUnsafePaths(): void
    {
        $relativePath = 'relative/state.json';
        $relativeResult = \pmssTrafficIngressUpdateState($relativePath, ['ingress' => 123, 'egress' => 456]);
        $this->assertEquals(123, $relativeResult['delta']);
        $this->assertEquals(null, $relativeResult['previous_ingress']);
        $this->assertTrue(!is_file($relativePath));

        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        [$target, $path] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target.json', $root.'/state.json', json_encode(['ingress' => 5, 'egress' => 6]), 0700);
        $result = \pmssTrafficIngressUpdateState($path, ['ingress' => 123, 'egress' => 456]);
        $this->assertEquals(123, $result['delta']);
        $this->assertEquals(null, $result['previous_ingress']);
        $loaded = $this->pmssReadJsonArrayFile($target, []);
        $this->assertEquals(['ingress' => 5, 'egress' => 6], $loaded);
    }
}
