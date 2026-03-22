<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/traffic/ingress.php';

class TrafficIngressHelpersTest extends TestCase
{
    private function makeRoot(): string
    {
        return $this->pmssMakeTempDir('pmss-ingress-', 0700);
    }

    public function testEnsureDirRejectsRelative(): void
    {
        $this->assertTrue(!\pmssTrafficIngressEnsureDir('relative/path', 0700));
    }

    public function testEnsureDirCreatesDirectory(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/state';
        $this->assertTrue(\pmssTrafficIngressEnsureDir($path, 0700));
        $this->assertTrue(is_dir($path));
    }

    public function testEnsureDirRejectsSymlink(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }
        $root = $this->makeRoot();
        $target = $root.'/target';
        @mkdir($target, 0700, true);
        $link = $root.'/link';
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation failed');
        }
        $this->assertTrue(!\pmssTrafficIngressEnsureDir($link, 0700));
    }

    public function testReadStateMissingReturnsEmpty(): void
    {
        $root = $this->makeRoot();
        $state = \pmssTrafficIngressReadState($root.'/missing.json');
        $this->assertTrue($state === []);
    }

    public function testWriteStateRoundTrip(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/state.json';
        $payload = ['ingress' => 123, 'egress' => 456];
        \pmssTrafficIngressWriteState($path, $payload);
        $loaded = \pmssTrafficIngressReadState($path);
        $this->assertEquals($payload, $loaded);
    }

    public function testReadStateInvalidJsonReturnsEmpty(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/bad.json';
        @file_put_contents($path, 'not-json');
        $loaded = \pmssTrafficIngressReadState($path);
        $this->assertTrue($loaded === []);
    }
}
