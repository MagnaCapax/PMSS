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
            $this->pmssTestModeEnv(),
            '2>&1'
        );

        $this->assertEquals(0, $result['rc'], 'update bootstrap shim should load cleanly');
        $this->assertEquals('ok', trim($result['output']), 'update bootstrap shim should expose updater helpers');
    }

    public function testShimLoadsAfterSharedLogBootstrap(): void
    {
        $logPath = dirname(__DIR__, 2).'/log.php';
        $shimPath = dirname(__DIR__).'/common/updateBootstrapShim.php';
        $script = 'require '.var_export($logPath, true).'; '
            .'require '.var_export($shimPath, true).'; '
            .'echo function_exists("parseArguments") ? "ok" : "missing";';
        $result = $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script),
            $this->pmssTestModeEnv(),
            '2>&1'
        );

        $this->assertEquals(0, $result['rc'], 'update bootstrap shim should load after shared log.php');
        $this->assertEquals('ok', trim($result['output']), 'update bootstrap shim should expose updater helpers after log.php');
    }

    public function testShimLoadsAfterSharedRuntimeBootstrap(): void
    {
        $runtimePath = dirname(__DIR__, 2).'/runtime.php';
        $shimPath = dirname(__DIR__).'/common/updateBootstrapShim.php';
        $script = 'require '.var_export($runtimePath, true).'; '
            .'require '.var_export($shimPath, true).'; '
            .'echo function_exists("parseArguments") ? "ok" : "missing";';
        $result = $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script),
            $this->pmssTestModeEnv(),
            '2>&1'
        );

        $this->assertEquals(0, $result['rc'], 'update bootstrap shim should load after shared runtime.php');
        $this->assertEquals('ok', trim($result['output']), 'update bootstrap shim should expose updater helpers after runtime.php');
    }
}
