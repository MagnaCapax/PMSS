<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class userConfigCommandContractsTest extends TestCase
{
    private function loadUserConfigSubsystemSource(): string
    {
        return $this->pmssReadRepoFile('scripts/util/userConfig.php');
    }

    public function testRutorrentConfigUpdateContractRemainsStable(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString('$scgiPort = (int) ($configuration[\'config\'][\'scgiPort\'] ?? 0);', $source);
        $this->assertStringContainsString("updateRutorrentConfig(\$user['name'], \$scgiPort);", $source);
    }

    public function testRtorrentRestartContractRemainsStable(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString("'/home/%s/session/rtorrent.lock'", $source);
        $this->assertStringContainsString('pmssUserConfigRtorrentLockPid($lockFile)', $source);
        $this->assertStringContainsString("pmssUserConfigRtorrentProcessOwnedBy(\$pid, \$user['id'])", $source);
        $this->assertStringContainsString("runStep('Restarting rTorrent', sprintf('kill -9 %d', \$pid));", $source);
        $this->assertStringNotContainsString('(int) $pid'.'Chunk', $source);
    }

    public function testShellNormalizationContractRemainsStable(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString("file_exists('/bin/bash')", $source);
        $this->assertStringContainsString("runStep('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg(\$user['name'])));", $source);
    }

    public function testCgroupConfigurationContractRemainsStable(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString("'/scripts/util/userConfigCgroup.php'", $source);
        $this->assertStringContainsString("runStep(\n    'Configuring cgroups',", $source);
        $this->assertStringContainsString("'--memory-high=' . \$user['memory']", $source);
    }

    public function testUserConfigUsesSharedWelcomeCliParser(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString("require_once __DIR__.'/../lib/cli/optionParser.php';", $source);
        $this->assertStringContainsString("require_once __DIR__.'/../lib/user/userConfigCli.php';", $source);
        $this->assertStringContainsString("pmssUserConfigCliResourceOptionNames('addUserOption')", $source);
        $this->assertStringContainsString("array_merge(['upload-throttle-kib', 'welcome-message', 'docker-enabled'], \$resourceOptions)", $source);
        $this->assertStringContainsString("pmssCliOption(\$parsed, 'upload-throttle-kib')", $source);
        $this->assertStringContainsString("pmssCliOption(\$parsed, 'welcome-message')", $source);
        $this->assertStringContainsString("pmssCliOption(\$parsed, 'docker-enabled')", $source);
        $this->assertStringContainsString("pmssUserConfigCliExplicitResources(\$parsed, \$args, 'addUserOption', 'userConfigIndex')", $source);
        $this->assertStringContainsString("\$presence = array_fill_keys(array_keys(\$explicitResourceOverrides), true);", $source);
        $this->assertTrue(
            strpos($source, "strpos(\$arg, '--upload-throttle-kib=')") === false,
            'userConfig.php should not keep a manual --upload-throttle-kib scan'
        );
        $this->assertTrue(
            strpos($source, "strpos(\$arg, '--welcome-message=')") === false,
            'userConfig.php should not keep a manual --welcome-message scan'
        );
        $this->assertTrue(
            strpos($source, "strpos(\$arg, '--docker-enabled=')") === false,
            'userConfig.php should not keep a manual --docker-enabled scan'
        );
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

    public function testRootlessDockerProvisioningContractRemainsStable(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString("'Rootless Docker disabled by config for '.\$user['name']", $source);
        $this->assertStringContainsString("runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg(\$user['name'])));", $source);
        $this->assertStringContainsString("runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');", $source);
        $this->assertStringContainsString("'Configuring rootless Docker'", $source);
        $this->assertStringContainsString("'machinectl shell %1\$s@ /usr/bin/dockerd-rootless-setuptool.sh install'", $source);
    }

    public function testUserConfigUsesSharedResourceSpecForPositionals(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString("pmssUserConfigCliResolvedResources(\$parsed, \$args, 'addUserOption', 'userConfigIndex')", $source);
        $this->assertStringContainsString("pmssUserConfigCliPersistedStoredResources(\$existing)", $source);
        $this->assertStringContainsString("pmssUserConfigCliApplyPersistedResources(\$payload, \$user, \$presence)", $source);
        $this->assertStringContainsString("pmssUserConfigCliBuildCgroupResourceArgs(\$user)", $source);
        $this->assertTrue(
            strpos($source, 'pmssUserConfigCli'.'PersistedResourcePresence') === false,
            'userConfig.php should derive persisted presence from explicit resource overrides'
        );
        $this->assertTrue(
            strpos($source, "'--cpu-weight=' . \$user['CPUWeight']") === false,
            'userConfig.php should not keep a duplicated cpu-weight flag path'
        );
        $this->assertTrue(
            strpos($source, "'--io-weight=' . \$user['IOWeight']") === false,
            'userConfig.php should not keep a duplicated io-weight flag path'
        );
        $this->assertTrue(
            strpos($source, "['CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit']") === false,
            'userConfig.php should not keep a duplicated persisted-resource key list'
        );
    }

    public function testUserConfigUsesSharedWelcomeAndPersistFlows(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString("pmssWelcomeUserMessageSet(\$user['name'], \$expectedHome, \$welcomeMessage)", $source);
        $this->assertStringContainsString("unset(\$payload['welcomeMessage']);", $source);
        $this->assertTrue(
            strpos($source, 'pmssUserConfigClear'.'WelcomeMessage') === false,
            'userConfig.php should clear legacy welcome banners inline'
        );
        $this->assertStringContainsString("\$store->persist(\$user['name'], \$payload)", $source);
        $this->assertTrue(
            strpos($source, 'writeUserCache(') === false,
            'userConfig.php should not bypass the shared persist flow'
        );
    }

    public function testGeneratedPortAndQbittorrentWritesAreChecked(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsString('@file_put_contents($rclonePortFile, (string) rand(1500, 65500)) === false', $source);
        $this->assertStringContainsString('Warning: failed to write rclone port', $source);
        $this->assertStringContainsString('Warning: failed to create qBittorrent config directory', $source);
        $this->assertStringContainsString('@file_put_contents($qbittorrentConfigFile, $qbittorrentConfig) === false', $source);
        $this->assertStringContainsString('Warning: failed to write qBittorrent port', $source);
    }
}
