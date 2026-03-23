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
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testReadStateInvalidJsonReturnsEmpty(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/bad.json';
        @file_put_contents($path, 'not-json');
        $loaded = \pmssTrafficIngressReadState($path);
        $this->assertTrue($loaded === []);
    }

    public function testReadStateRejectsSymlink(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }

        $root = $this->makeRoot();
        $target = $root.'/target.json';
        @file_put_contents($target, json_encode(['ingress' => 1]));
        $path = $root.'/state.json';
        if (!@symlink($target, $path)) {
            throw new SkipTest('symlink creation failed');
        }

        $this->assertTrue(\pmssTrafficIngressReadState($path) === []);
    }

    public function testReadCountersParsesSystemctlOutputViaSharedHelper(): void
    {
        $root = $this->makeRoot();
        $binDir = $root.'/bin';
        @mkdir($binDir, 0755, true);
        $scriptPath = $binDir.'/systemctl';
        @file_put_contents($scriptPath, "#!/bin/sh\necho 'IPIngressBytes=123'\necho 'IPEgressBytes=456'\n");
        @chmod($scriptPath, 0755);

        $originalPath = getenv('PATH');
        $pathPrefix = ($originalPath !== false && $originalPath !== '') ? ':'.$originalPath : '';
        putenv('PATH='.$binDir.$pathPrefix);

        try {
            $this->assertEquals(['ingress' => 123, 'egress' => 456], \pmssTrafficIngressReadCounters(1000));
        } finally {
            if ($originalPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH='.$originalPath);
            }
        }
    }

    public function testReadCountersReturnsNullWhenRequiredCounterMissing(): void
    {
        $root = $this->makeRoot();
        $binDir = $root.'/bin';
        @mkdir($binDir, 0755, true);
        $scriptPath = $binDir.'/systemctl';
        @file_put_contents($scriptPath, "#!/bin/sh\necho 'IPIngressBytes=123'\n");
        @chmod($scriptPath, 0755);

        $originalPath = getenv('PATH');
        $pathPrefix = ($originalPath !== false && $originalPath !== '') ? ':'.$originalPath : '';
        putenv('PATH='.$binDir.$pathPrefix);

        try {
            $this->assertEquals(null, \pmssTrafficIngressReadCounters(1000));
        } finally {
            if ($originalPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH='.$originalPath);
            }
        }
    }

    public function testWriteStateRejectsSymlink(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }

        $root = $this->makeRoot();
        $target = $root.'/target.json';
        @file_put_contents($target, json_encode(['ingress' => 5, 'egress' => 6]));
        $path = $root.'/state.json';
        if (!@symlink($target, $path)) {
            throw new SkipTest('symlink creation failed');
        }

        \pmssTrafficIngressWriteState($path, ['ingress' => 123, 'egress' => 456]);

        $loaded = json_decode((string) file_get_contents($target), true);
        $this->assertEquals(['ingress' => 5, 'egress' => 6], $loaded);
    }
}
