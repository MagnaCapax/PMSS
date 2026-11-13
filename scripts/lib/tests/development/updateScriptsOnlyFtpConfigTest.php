<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateScriptsOnlyFtpConfigTest extends TestCase
{
    public function testScriptsOnlyRefreshesFtpConfigWhenAvailable(): void
    {
        $source = $this->pmssReadRepoFile('scripts/update.php');
        $this->assertStringContainsAllStrings(
            ['Refreshing FTP configuration for --scripts-only run', 'Skipping update-step2.php (--scripts-only)'],
            $source,
            'update.php scripts-only branch should contain: '
        );
        $this->assertOrderedStrings(
            ["if (\$options['scripts_only'])", '/scripts/util/ftpConfig.php'],
            $source,
            'update.php should contain scripts-only FTP refresh: ',
            'ftpConfig refresh should be inside the scripts-only branch: '
        );
    }
}
