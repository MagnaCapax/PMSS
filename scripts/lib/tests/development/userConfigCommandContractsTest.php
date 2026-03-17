<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class userConfigCommandContractsTest extends TestCase
{
    private function loadSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 4).'/scripts/'.$relativePath;
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    private function loadUserConfigSubsystemSource(): string
    {
        return $this->loadSource('util/userConfig.php')
            ."\n"
            .$this->loadSource('lib/user/system.php');
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
}
