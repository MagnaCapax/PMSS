<?php
namespace PMSS\Tests;

/**
 * Shared CLI runner for hermetic `userConfigCgroup.php` contract tests.
 */
trait UserConfigCgroupCliTrait
{
    /** Execute the userConfigCgroup CLI with optional environment overrides. */
    protected function pmssRunUserConfigCgroupCli(array $args, array $env = []): string
    {
        $envPrefix = '';
        foreach ($env as $key => $value) {
            $envPrefix .= $key.'='.escapeshellarg($value).' ';
        }

        $command = 'php '.escapeshellarg(getcwd().'/scripts/util/userConfigCgroup.php');
        if ($args !== []) {
            $command .= ' '.implode(' ', array_map('escapeshellarg', $args));
        }

        return (string) @shell_exec($envPrefix.$command.' 2>&1');
    }
}
