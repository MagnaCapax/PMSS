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

        $this->assertStringContainsAllStrings(['$scgiPort = (int) ($configuration[\'config\'][\'scgiPort\'] ?? 0);', "updateRutorrentConfig(\$user['name'], \$scgiPort);"], $source);
    }

    public function testRtorrentRestartContractRemainsStable(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsAllStrings(["'/home/%s/session/rtorrent.lock'", 'pmssUserConfigRtorrentLockPid($lockFile)', "pmssUserConfigRtorrentProcessOwnedBy(\$pid, \$user['id'])", "runStep('Restarting rTorrent', sprintf('kill -9 %d', \$pid));"], $source);
        $this->assertStringNotContainsString('(int) $pid'.'Chunk', $source);
    }

    public function testShellNormalizationContractRemainsStable(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsAllStrings(["file_exists('/bin/bash')", "runStep('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg(\$user['name'])));"], $source);
    }

    public function testCgroupConfigurationContractRemainsStable(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsAllStrings(["'/scripts/util/userConfigCgroup.php'", "runStep(\n    'Configuring cgroups',", "'--memory-high=' . \$user['memory']"], $source);
    }

    public function testUserConfigUsesSharedWelcomeCliParser(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsAllStrings(["require_once __DIR__.'/../lib/cli/optionParser.php';", "require_once __DIR__.'/../lib/user/userConfigCli.php';", "pmssUserConfigCliResourceOptionNames('addUserOption')", "array_merge(['upload-throttle-kib', 'welcome-message', 'docker-enabled'], \$resourceOptions)", "pmssCliOption(\$parsed, 'upload-throttle-kib')", "pmssCliOption(\$parsed, 'welcome-message')", "pmssCliOption(\$parsed, 'docker-enabled')", "pmssUserConfigCliExplicitResources(\$parsed, \$args, 'addUserOption', 'userConfigIndex')", "\$presence = array_fill_keys(array_keys(\$explicitResourceOverrides), true);"], $source);
        $this->assertStringNotContainsString("strpos(\$arg, '--upload-throttle-kib=')", $source, 'userConfig.php should not keep a manual --upload-throttle-kib scan');
        $this->assertStringNotContainsString("strpos(\$arg, '--welcome-message=')", $source, 'userConfig.php should not keep a manual --welcome-message scan');
        $this->assertStringNotContainsString("strpos(\$arg, '--docker-enabled=')", $source, 'userConfig.php should not keep a manual --docker-enabled scan');
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

        $this->assertStringContainsAllStrings(["'Rootless Docker disabled by config for '.\$user['name']", "runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg(\$user['name'])));", "runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');", "'Configuring rootless Docker'", "'machinectl shell %1\$s@ /usr/bin/dockerd-rootless-setuptool.sh install'"], $source);
    }

    public function testUserConfigUsesSharedResourceSpecForPositionals(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsAllStrings(["pmssUserConfigCliResolvedResources(\$parsed, \$args, 'addUserOption', 'userConfigIndex')", "pmssUserConfigCliPersistedStoredResources(\$existing)", "pmssUserConfigCliApplyPersistedResources(\$payload, \$user, \$presence)", "pmssUserConfigCliBuildCgroupResourceArgs(\$user)"], $source);
        $this->assertStringNotContainsString('pmssUserConfigCli'.'PersistedResourcePresence', $source, 'userConfig.php should derive persisted presence from explicit resource overrides');
        $this->assertStringNotContainsString("'--cpu-weight=' . \$user['CPUWeight']", $source, 'userConfig.php should not keep a duplicated cpu-weight flag path');
        $this->assertStringNotContainsString("'--io-weight=' . \$user['IOWeight']", $source, 'userConfig.php should not keep a duplicated io-weight flag path');
        $this->assertStringNotContainsString("['CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit']", $source, 'userConfig.php should not keep a duplicated persisted-resource key list');
    }

    public function testUserConfigUsesSharedWelcomeAndPersistFlows(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsAllStrings(["pmssWelcomeUserMessageSet(\$user['name'], \$expectedHome, \$welcomeMessage)", "unset(\$payload['welcomeMessage']);"], $source);
        $this->assertStringNotContainsString('pmssUserConfigClear'.'WelcomeMessage', $source, 'userConfig.php should clear legacy welcome banners inline');
        $this->assertStringContainsString("\$store->persist(\$user['name'], \$payload)", $source);
        $this->assertStringNotContainsString('writeUserCache(', $source, 'userConfig.php should not bypass the shared persist flow');
    }

    public function testGeneratedPortAndQbittorrentWritesAreChecked(): void
    {
        $source = $this->loadUserConfigSubsystemSource();

        $this->assertStringContainsAllStrings(['@file_put_contents($rclonePortFile, (string) rand(1500, 65500)) === false', 'Warning: failed to write rclone port', 'Warning: failed to create qBittorrent config directory', '@file_put_contents($qbittorrentConfigFile, $qbittorrentConfig) === false', 'Warning: failed to write qBittorrent port'], $source);
    }
}
