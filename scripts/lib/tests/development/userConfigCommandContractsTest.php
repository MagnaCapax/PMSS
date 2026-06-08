<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class userConfigCommandContractsTest extends TestCase
{
    public function testUserConfigSourceContractsRemainStable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', [
            "require_once __DIR__.'/../lib/user/userConfigRuntime.php';",
            'pmssUserConfigInvocationMode($args, $welcomeMessage !== null, $namedConfigChange)',
            'pmssUserConfigWelcomeOnlyPersist($store, $user[\'name\'], $expectedHome, $welcomeMessage, $existing)',
            'pmssUserConfigPayloadBuild($store, $existing, $user, $presence, $dockerEnabled)',
            'pmssNetworkPortFileWrite($rclonePortFile, (int) rand(1500, 65500), 1024, 65500, 0644)',
            '@file_put_contents($qbittorrentConfigFile, $qbittorrentConfig) === false',
            'pmssUserConfigApplyCgroupAndDocker($user, $store)',
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/userConfig.php', [], [
            '(int) $pid'.'Chunk',
            "strpos(\$arg, '--upload-throttle-kib=')" => 'userConfig.php should not keep a manual scan: --upload-throttle-kib',
            'pmssUserConfigCli'.'PersistedResourcePresence' => 'userConfig.php should not keep duplicated resource presence logic',
            "'--cpu-weight=' . \$user['CPUWeight']" => 'userConfig.php should not keep duplicated CPU weight CLI rendering',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/userConfigRuntime.php', [
            'function pmssUserConfigPayloadBuild(',
            'function pmssUserConfigApplyCgroupAndDocker(',
            "runStep('Configuring cgroups', pmssBuildCommand('php', \$args));",
            "'Rootless Docker disabled by config for '.\$user['name']",
            "'machinectl shell %1\$s@ /usr/bin/dockerd-rootless-setuptool.sh install'",
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/userConfigCli.php', "'/scripts/util/userConfigCgroup.php'");
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
