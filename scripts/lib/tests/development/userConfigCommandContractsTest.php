<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class userConfigCommandContractsTest extends TestCase
{
    public function testRutorrentConfigUpdateContractRemainsStable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ['$scgiPort = (int) ($configuration[\'config\'][\'scgiPort\'] ?? 0);', "updateRutorrentConfig(\$user['name'], \$scgiPort);"]);
    }

    public function testRtorrentRestartContractRemainsStable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ["'/home/%s/session/rtorrent.lock'", 'pmssUserConfigRtorrentLockPid($lockFile)', "pmssUserConfigRtorrentProcessOwnedBy(\$pid, \$user['id'])", "runStep('Restarting rTorrent', sprintf('kill -9 %d', \$pid));"]);
        $this->pmssAssertRepoFileNotContainsString('scripts/util/userConfig.php', '(int) $pid'.'Chunk');
    }

    public function testShellNormalizationContractRemainsStable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ["file_exists('/bin/bash')", "runStep('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg(\$user['name'])));"]);
    }

    public function testCgroupConfigurationContractRemainsStable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ["'/scripts/util/userConfigCgroup.php'", "runStep(\n    'Configuring cgroups',", "'--memory-high=' . \$user['memory']"]);
    }

    public function testUserConfigUsesSharedWelcomeCliParser(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ["require_once __DIR__.'/../lib/cli/optionParser.php';", "require_once __DIR__.'/../lib/user/userConfigCli.php';", "pmssUserConfigCliResourceOptionNames('addUserOption')", "array_merge(['upload-throttle-kib', 'welcome-message', 'docker-enabled'], \$resourceOptions)", "pmssCliOption(\$parsed, 'upload-throttle-kib')", "pmssCliOption(\$parsed, 'welcome-message')", "pmssCliOption(\$parsed, 'docker-enabled')", "pmssUserConfigCliExplicitResources(\$parsed, \$args, 'addUserOption', 'userConfigIndex')", "\$presence = array_fill_keys(array_keys(\$explicitResourceOverrides), true);"]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/util/userConfig.php', [
            "strpos(\$arg, '--upload-throttle-kib=')",
            "strpos(\$arg, '--welcome-message=')",
            "strpos(\$arg, '--docker-enabled=')",
        ], 'userConfig.php should not keep a manual scan: ');
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
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ["'Rootless Docker disabled by config for '.\$user['name']", "runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg(\$user['name'])));", "runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');", "'Configuring rootless Docker'", "'machinectl shell %1\$s@ /usr/bin/dockerd-rootless-setuptool.sh install'"]);
    }

    public function testUserConfigUsesSharedResourceSpecForPositionals(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ["pmssUserConfigCliResolvedResources(\$parsed, \$args, 'addUserOption', 'userConfigIndex')", "pmssUserConfigCliPersistedStoredResources(\$existing)", "pmssUserConfigCliApplyPersistedResources(\$payload, \$user, \$presence)", "pmssUserConfigCliBuildCgroupResourceArgs(\$user)"]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/util/userConfig.php', [
            'pmssUserConfigCli'.'PersistedResourcePresence',
            "'--cpu-weight=' . \$user['CPUWeight']",
            "'--io-weight=' . \$user['IOWeight']",
            "['CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit']",
        ], 'userConfig.php should not keep duplicated resource logic: ');
    }

    public function testUserConfigUsesSharedWelcomeAndPersistFlows(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ["pmssWelcomeUserMessageSet(\$user['name'], \$expectedHome, \$welcomeMessage)", "unset(\$payload['welcomeMessage']);", "\$store->persist(\$user['name'], \$payload)"]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/util/userConfig.php', [
            'pmssUserConfigClear'.'WelcomeMessage',
            'writeUserCache(',
        ], 'userConfig.php should keep shared welcome/persist flow: ');
    }

    public function testGeneratedPortAndQbittorrentWritesAreChecked(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', ['@file_put_contents($rclonePortFile, (string) rand(1500, 65500)) === false', 'Warning: failed to write rclone port', 'Warning: failed to create qBittorrent config directory', '@file_put_contents($qbittorrentConfigFile, $qbittorrentConfig) === false', 'Warning: failed to write qBittorrent port']);
    }
}
