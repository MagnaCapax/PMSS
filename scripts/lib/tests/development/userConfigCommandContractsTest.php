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
        $this->assertStringContainsString("runStep('Restarting rTorrent', sprintf('kill -9 %d', \$pid));", $source);
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
        $this->assertStringContainsString(
            "pmssParseCliTokens(\$argv ?? (\$_SERVER['argv'] ?? []), ['upload-throttle-kib', 'welcome-message'])",
            $source
        );
        $this->assertStringContainsString("pmssCliOption(\$parsed, 'upload-throttle-kib')", $source);
        $this->assertStringContainsString("pmssCliOption(\$parsed, 'welcome-message')", $source);
        $this->assertTrue(
            strpos($source, "strpos(\$arg, '--upload-throttle-kib=')") === false,
            'userConfig.php should not keep a manual --upload-throttle-kib scan'
        );
        $this->assertTrue(
            strpos($source, "strpos(\$arg, '--welcome-message=')") === false,
            'userConfig.php should not keep a manual --welcome-message scan'
        );
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
}
