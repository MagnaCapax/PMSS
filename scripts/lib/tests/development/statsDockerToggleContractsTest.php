<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class statsDockerToggleContractsTest extends TestCase
{
    public function testStatsPageDoesNotPostCustomerActionsIntoOperatorDockerCli(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertStringNotContainsString('docker_toggle_state', $source);
        $this->assertStringNotContainsString('/scripts/userDocker.php', $source);
        $this->assertStringNotContainsString('UserConfigStore', $source);
        $this->assertStringContainsString('Docker policy changes are handled by platform tooling.', $source);
    }
}
