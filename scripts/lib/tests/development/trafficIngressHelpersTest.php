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
        $target = $root.'/target';
        $this->pmssEnsureDir($target, 0700);
        $link = $root.'/link';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $this->assertTrue(!\pmssEnsureSafeDir($link, 0700));
    }

    public function testEnsureDirRejectsSymlinkedParentDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        $target = $root.'/target';
        $this->pmssEnsureDir($target, 0700);

        $symlinkedParent = $root.'/state';
        $this->pmssCreateSymlinkOrSkip($target, $symlinkedParent);

        $this->assertTrue(!\pmssEnsureSafeDir($symlinkedParent.'/daily', 0700));
        $this->assertTrue(!is_dir($target.'/daily'), 'must not create directories via symlinked parent');
    }

    public function testReadStateMissingReturnsEmpty(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        $state = \pmssTrafficIngressReadState($root.'/missing.json');
        $this->assertTrue($state === []);
    }

    public function testWriteStateRoundTrip(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        $path = $root.'/state.json';
        $payload = ['ingress' => 123, 'egress' => 456];
        \pmssTrafficIngressWriteState($path, $payload);
        $loaded = \pmssTrafficIngressReadState($path);
        $this->assertEquals($payload, $loaded);
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testReadStateInvalidJsonReturnsEmpty(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        $path = $root.'/bad.json';
        $this->pmssWriteFile($path, 'not-json');
        $loaded = \pmssTrafficIngressReadState($path);
        $this->assertTrue($loaded === []);
    }

    public function testReadStateRejectsSymlink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        $target = $root.'/target.json';
        $this->pmssWriteFile($target, json_encode(['ingress' => 1]));
        $path = $root.'/state.json';
        $this->pmssCreateSymlinkOrSkip($target, $path);

        $this->assertTrue(\pmssTrafficIngressReadState($path) === []);
    }

    public function testReadCountersParsesSystemctlOutputViaSharedHelper(): void
    {
        $binDir = $this->pmssMakeLineOutputStub('systemctl', ['IPIngressBytes=123', 'IPEgressBytes=456'], 'pmss-ingress-systemctl-');

        $this->pmssWithPathPrefix($binDir, function (): void {
            $this->assertEquals(['ingress' => 123, 'egress' => 456], \pmssTrafficIngressReadCounters(1000));
        });
    }

    public function testReadCountersReturnsNullWhenRequiredCounterMissing(): void
    {
        $binDir = $this->pmssMakeLineOutputStub('systemctl', ['IPIngressBytes=123'], 'pmss-ingress-systemctl-');

        $this->pmssWithPathPrefix($binDir, function (): void {
            $this->assertEquals(null, \pmssTrafficIngressReadCounters(1000));
        });
    }

    public function testWriteStateRejectsSymlink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ingress-', 0700);
        $target = $root.'/target.json';
        $this->pmssWriteFile($target, json_encode(['ingress' => 5, 'egress' => 6]));
        $path = $root.'/state.json';
        $this->pmssCreateSymlinkOrSkip($target, $path);

        \pmssTrafficIngressWriteState($path, ['ingress' => 123, 'egress' => 456]);

        $loaded = json_decode((string) file_get_contents($target), true);
        $this->assertEquals(['ingress' => 5, 'egress' => 6], $loaded);
    }
}
