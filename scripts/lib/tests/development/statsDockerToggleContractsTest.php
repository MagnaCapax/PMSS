<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class statsDockerToggleContractsTest extends TestCase
{
    public function testStatsPageDoesNotPostCustomerActionsIntoOperatorDockerCli(): void
    {
        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/stats.php', ['docker_toggle_state', '/scripts/userDocker.php', 'UserConfigStore']);
        $this->pmssAssertRepoFileContainsString('etc/skel/www/stats.php', 'Docker policy changes are handled by platform tooling.');
    }
}
