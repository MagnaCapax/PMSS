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
        return $this->pmssRunPhpScript(getcwd().'/scripts/util/userConfigCgroup.php', $args, $env);
    }
}
