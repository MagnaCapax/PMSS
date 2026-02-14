<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/log.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class ResourceLogHelpersTest extends TestCase
{
    private function makeRoot(): string
    {
        $root = sys_get_temp_dir().'/pmss-resource-'.bin2hex(random_bytes(4));
        @mkdir($root, 0700, true);
        return $root;
    }

    public function testEnsureDirRejectsRelative(): void
    {
        $this->assertTrue(!\pmssResourceLogEnsureDir('relative/path', 0700));
    }

    public function testEnsureDirCreatesDirectory(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/state';
        $this->assertTrue(\pmssResourceLogEnsureDir($path, 0700));
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
        $this->assertTrue(!\pmssResourceLogEnsureDir($link, 0700));
    }

    public function testReadStateMissingReturnsEmpty(): void
    {
        $root = $this->makeRoot();
        $state = \pmssResourceLogReadState($root.'/missing.json');
        $this->assertTrue($state === []);
    }

    public function testWriteStateRoundTrip(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/state.json';
        $payload = ['io_read' => 123, 'io_write' => 456];
        \pmssResourceLogWriteState($path, $payload);
        $loaded = \pmssResourceLogReadState($path);
        $this->assertEquals($payload, $loaded);
    }

    public function testDeltaUsesPreviousWhenAvailable(): void
    {
        $this->assertEquals(50, \pmssResourceLogDelta(150, 100));
    }

    public function testDeltaHandlesCounterReset(): void
    {
        $this->assertEquals(90, \pmssResourceLogDelta(90, 100));
    }

    public function testUserValidationRejectsUppercase(): void
    {
        $this->assertTrue(!\pmssResourceLogIsValidUser('Alice'));
    }
}
