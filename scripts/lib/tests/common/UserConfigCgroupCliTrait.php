<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';

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

    /** Execute userConfigCgroup with a generated cgroup.policy.php fixture. */
    protected function pmssRunUserConfigCgroupCliWithPolicy(array $policy, array $args, array $env = []): string
    {
        $configDirectory = $this->pmssMakeCgroupPolicyConfigDir($policy);
        return $this->pmssRunUserConfigCgroupCli($args, array_merge($env, ['PMSS_CONFIG_DIR' => $configDirectory]));
    }
}
