<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class statsDockerToggleContractsTest extends TestCase
{
    public function testStatsPageDoesNotPostCustomerActionsIntoOperatorDockerCli(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/skel/www/stats.php', ['Docker policy changes are handled by platform tooling.'], ['docker_toggle_state', '/scripts/userDocker.php', 'UserConfigStore']);
    }
}
