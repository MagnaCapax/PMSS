<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Characterize skip logging for nginx user config generation.
 */
class NginxConfigSkipLoggingTest extends TestCase
{
    public function testGeneratorLogsMissingRtorrentPrerequisite(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/nginxConfig/userConfigsGenerate.php');

        $this->assertStringContainsString('function pmssCreateNginxConfigLogSkippedUser(string $user, string $reason): void', $source);
        $this->assertStringContainsString("pmssCreateNginxConfigLogSkippedUser(\$thisUser, 'missing .rtorrent.rc prerequisite');", $source);
    }

    public function testGeneratorLogsInvalidLighttpdPortSkip(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/nginxConfig/userConfigsGenerate.php');

        $this->assertStringContainsString('lighttpd port missing or invalid after refresh attempt', $source);
        $this->assertStringContainsString('WARN: skipping nginx config for %s: %s', $source);
    }
}
