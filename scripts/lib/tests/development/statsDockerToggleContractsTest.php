<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class statsDockerToggleContractsTest extends TestCase
{
    public function testStatsPageUsesSharedPersistFlowForDockerToggle(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertStringContainsString("\$store->persist(\$username, \$payload)", $source);
        $this->assertTrue(
            strpos($source, 'writeUserCache(') === false,
            'stats.php should not bypass UserConfigStore::persist()'
        );
    }
}
