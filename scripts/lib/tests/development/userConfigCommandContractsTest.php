<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class userConfigCommandContractsTest extends TestCase
{
    public function testUserConfigSourceContractsRemainStable(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/userConfig.php' => [
                'required' => [
                    "require_once __DIR__.'/../lib/user/userConfigRuntime.php';",
                    'pmssUserConfigInvocationMode($args, $welcomeMessage !== null, $namedConfigChange)',
                    'pmssUserConfigWelcomeOnlyPersist($store, $user[\'name\'], $expectedHome, $welcomeMessage, $existing)',
                    'pmssUserConfigPayloadBuild($store, $existing, $user, $presence, $dockerEnabled)',
                    "pmssPortManagerAssignServicePort(\$user['name'], 'rclone')",
                    '@file_put_contents($qbittorrentConfigFile, $qbittorrentConfig) === false',
                    "if (pmssUserConfigDiskQuotaShouldApply(\$configMode)) {\n    userApplyDiskQuota(\$user);\n}",
                    'pmssUserConfigApplyCgroupAndDocker($user, $store)',
                ],
                'forbidden' => [
                    '(int) $pid'.'Chunk',
                    "strpos(\$arg, '--upload-throttle-kib=')" => 'userConfig.php should not keep a manual scan: --upload-throttle-kib',
                    'pmssUserConfigCli'.'PersistedResourcePresence' => 'userConfig.php should not keep duplicated resource presence logic',
                    "'--cpu-weight=' . \$user['CPUWeight']" => 'userConfig.php should not keep duplicated CPU weight CLI rendering',
                ],
            ],
            'scripts/lib/user/userConfigRuntime.php' => ['required' => [
                'function pmssUserConfigPayloadBuild(',
                'function pmssUserConfigApplyCgroupAndDocker(',
                "runStep('Configuring cgroups', pmssBuildCommand('php', \$args));",
                "'Rootless Docker disabled by config for '.\$user['name']",
                "pmssBuildUserShellCommand(\$user['name'], \$dockerSetupCmd)",
                '/usr/bin/dockerd-rootless-setuptool.sh install',
            ], 'forbidden' => [
                'machinectl shell' => 'rootless-Docker setup must be manager-independent (ADR-0027): no `machinectl shell user@` (needs a working per-user systemd manager, which fails under cgroup v2 + hidepid=2, systemd issue 12955)',
            ]],
            'scripts/lib/user/userConfigCli.php' => ['required' => ["'/scripts/util/userConfigCgroup.php'"]],
        ]);
    }

    public function testUsageTextSeparatesNamedOptionsFromPositionals(): void
    {
        $usage = $this->pmssRunRepoPhpScript('scripts/util/userConfig.php');

        $this->assertStringContainsAllStrings([
            'Usage',
            './userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB',
            './userConfig.php USERNAME [RESOURCE_OPTIONS]',
            './userConfig.php USERNAME --welcome-message=HTML',
            'Positional Parameters',
            'Named Options',
            '--cpu-weight=WEIGHT',
            '--upload-throttle-kib=KIB',
            '--iops-limit=OPS',
            '--docker-enabled=true|false',
            'Examples',
        ], $usage);
    }

}
