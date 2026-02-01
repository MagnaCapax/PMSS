<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class IopingPackageQueueTest extends TestCase
{
    public function testSystemPackagesQueueIncludesIoping(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $path = $repoRoot.'/scripts/lib/update/apps/packages/system.php';
        $src = (string) @file_get_contents($path);
        $this->assertTrue($src !== '', 'Expected to read system package group file');
        $this->assertTrue(strpos($src, "'ioping'") !== false, 'Expected ioping to be queued as a standard package');
    }
}

