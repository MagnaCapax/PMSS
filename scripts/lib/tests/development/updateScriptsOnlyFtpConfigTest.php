<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateScriptsOnlyFtpConfigTest extends TestCase
{
    public function testScriptsOnlyRefreshesFtpConfigWhenAvailable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/update.php',
            ['Refreshing FTP configuration for --scripts-only run', 'Skipping update-step2.php (--scripts-only)'],
            'update.php scripts-only branch should contain: '
        );
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/update.php',
            ["if (\$options['scripts_only'])", '/scripts/util/ftpConfig.php'],
            'update.php should contain scripts-only FTP refresh: ',
            'ftpConfig refresh should be inside the scripts-only branch: '
        );
    }
}
