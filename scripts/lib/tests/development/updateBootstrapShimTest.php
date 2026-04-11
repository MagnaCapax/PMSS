<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapShimTest extends TestCase
{
    public function testShimLoadsUpdaterBootstrapWithoutStrictTypesFatal(): void
    {
        $shimPath = dirname(__DIR__).'/common/updateBootstrapShim.php';
        $result = $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('require '.var_export($shimPath, true).'; echo function_exists("parseArguments") ? "ok" : "missing";'),
            ['PMSS_TEST_MODE' => '1'],
            '2>&1'
        );

        $this->assertEquals(0, $result['rc'], 'update bootstrap shim should load cleanly');
        $this->assertEquals('ok', trim($result['output']), 'update bootstrap shim should expose updater helpers');
    }
}
