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
                    '$scgiPort = (int) ($configuration[\'config\'][\'scgiPort\'] ?? 0);',
                    "updateRutorrentConfig(\$user['name'], \$scgiPort);",
                    "'/home/%s/session/rtorrent.lock'",
                    'pmssUserConfigRtorrentLockPid($lockFile)',
                    "pmssUserConfigRtorrentProcessOwnedBy(\$pid, \$user['id'])",
                    "runStep('Restarting rTorrent', sprintf('kill -9 %d', \$pid));",
                    "file_exists('/bin/bash')",
                    "runStep('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg(\$user['name'])));",
                    'pmssUserConfigCliBuildCgroupApplyArgs($user[\'name\'], (int) $user[\'memory\'], $user)',
                    "runStep(\n    'Configuring cgroups',",
                    "require_once __DIR__.'/../lib/cli/optionParser.php';",
                    "require_once __DIR__.'/../lib/user/userConfigCli.php';",
                    "pmssUserConfigCliResourceOptionNames('addUserOption')",
                    "array_merge(['upload-throttle-kib', 'welcome-message', 'docker-enabled'], \$resourceOptions)",
                    "pmssCliOption(\$parsed, 'upload-throttle-kib')",
                    "pmssCliOption(\$parsed, 'welcome-message')",
                    "pmssCliOption(\$parsed, 'docker-enabled')",
                    "pmssUserConfigCliExplicitResources(\$parsed, \$args, 'addUserOption', 'userConfigIndex')",
                    "\$presence = array_fill_keys(array_keys(\$explicitResourceOverrides), true);",
                    "'Rootless Docker disabled by config for '.\$user['name']",
                    "runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg(\$user['name'])));",
                    "runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');",
                    "'Configuring rootless Docker'",
                    "'machinectl shell %1\$s@ /usr/bin/dockerd-rootless-setuptool.sh install'",
                    '@file_put_contents($rclonePortFile, (string) rand(1500, 65500)) === false',
                    'Warning: failed to write rclone port',
                    'Warning: failed to create qBittorrent config directory',
                    '@file_put_contents($qbittorrentConfigFile, $qbittorrentConfig) === false',
                    'Warning: failed to write qBittorrent port',
                    "pmssUserConfigCliResolvedResources(\$parsed, \$args, 'addUserOption', 'userConfigIndex')",
                    "pmssUserConfigCliPersistedStoredResources(\$existing)",
                    "pmssUserConfigCliApplyPersistedResources(\$payload, \$user, \$presence)",
                    "pmssWelcomeUserMessageSet(\$user['name'], \$expectedHome, \$welcomeMessage)",
                    "unset(\$payload['welcomeMessage']);",
                    "\$store->persist(\$user['name'], \$payload)",
                ],
                'forbidden' => [
                    '(int) $pid'.'Chunk',
                    "strpos(\$arg, '--upload-throttle-kib=')" => 'userConfig.php should not keep a manual scan: --upload-throttle-kib',
                    "strpos(\$arg, '--welcome-message=')" => 'userConfig.php should not keep a manual scan: --welcome-message',
                    "strpos(\$arg, '--docker-enabled=')" => 'userConfig.php should not keep a manual scan: --docker-enabled',
                    'pmssUserConfigCli'.'PersistedResourcePresence' => 'userConfig.php should not keep duplicated resource presence logic',
                    "'--cpu-weight=' . \$user['CPUWeight']" => 'userConfig.php should not keep duplicated CPU weight CLI rendering',
                    "'--io-weight=' . \$user['IOWeight']" => 'userConfig.php should not keep duplicated IO weight CLI rendering',
                    "['CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit']" => 'userConfig.php should not keep duplicated resource key lists',
                    'pmssUserConfigClear'.'WelcomeMessage' => 'userConfig.php should keep shared welcome flow',
                    'writeUserCache(' => 'userConfig.php should keep shared persist flow',
                ],
            ],
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
