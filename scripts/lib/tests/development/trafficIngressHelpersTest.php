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
        $root = $this->makeRoot();
        $target = $root.'/target';
        $this->pmssEnsureDir($target, 0700);
        $link = $root.'/link';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $this->assertTrue(!\pmssTrafficIngressEnsureDir($link, 0700));
    }

    public function testEnsureDirRejectsSymlinkedParentDirectory(): void
    {
        $root = $this->makeRoot();
        $target = $root.'/target';
        $this->pmssEnsureDir($target, 0700);

        $symlinkedParent = $root.'/state';
        $this->pmssCreateSymlinkOrSkip($target, $symlinkedParent);

        $this->assertTrue(!\pmssTrafficIngressEnsureDir($symlinkedParent.'/daily', 0700));
        $this->assertTrue(!is_dir($target.'/daily'), 'must not create directories via symlinked parent');
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
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testReadStateInvalidJsonReturnsEmpty(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/bad.json';
        $this->pmssWriteFile($path, 'not-json');
        $loaded = \pmssTrafficIngressReadState($path);
        $this->assertTrue($loaded === []);
    }

    public function testReadStateRejectsSymlink(): void
    {
        $root = $this->makeRoot();
        $target = $root.'/target.json';
        $this->pmssWriteFile($target, json_encode(['ingress' => 1]));
        $path = $root.'/state.json';
        $this->pmssCreateSymlinkOrSkip($target, $path);

        $this->assertTrue(\pmssTrafficIngressReadState($path) === []);
    }

    public function testReadCountersParsesSystemctlOutputViaSharedHelper(): void
    {
        $root = $this->makeRoot();
        $binDir = $root.'/bin';
        $this->pmssEnsureDir($binDir);
        $scriptPath = $binDir.'/systemctl';
        $this->pmssWriteFile($scriptPath, "#!/bin/sh\necho 'IPIngressBytes=123'\necho 'IPEgressBytes=456'\n");
        @chmod($scriptPath, 0755);

        $this->pmssWithPathPrefix($binDir, function (): void {
            $this->assertEquals(['ingress' => 123, 'egress' => 456], \pmssTrafficIngressReadCounters(1000));
        });
    }

    public function testReadCountersReturnsNullWhenRequiredCounterMissing(): void
    {
        $root = $this->makeRoot();
        $binDir = $root.'/bin';
        $this->pmssEnsureDir($binDir);
        $scriptPath = $binDir.'/systemctl';
        $this->pmssWriteFile($scriptPath, "#!/bin/sh\necho 'IPIngressBytes=123'\n");
        @chmod($scriptPath, 0755);

        $this->pmssWithPathPrefix($binDir, function (): void {
            $this->assertEquals(null, \pmssTrafficIngressReadCounters(1000));
        });
    }

    public function testWriteStateRejectsSymlink(): void
    {
        $root = $this->makeRoot();
        $target = $root.'/target.json';
        $this->pmssWriteFile($target, json_encode(['ingress' => 5, 'egress' => 6]));
        $path = $root.'/state.json';
        $this->pmssCreateSymlinkOrSkip($target, $path);

        \pmssTrafficIngressWriteState($path, ['ingress' => 123, 'egress' => 456]);

        $loaded = json_decode((string) file_get_contents($target), true);
        $this->assertEquals(['ingress' => 5, 'egress' => 6], $loaded);
    }
}
