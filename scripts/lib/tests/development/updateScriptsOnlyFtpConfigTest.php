<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateScriptsOnlyFtpConfigTest extends TestCase
{
    public function testScriptsOnlyRefreshesFtpConfigWhenAvailable(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../update.php');
        $posScriptsOnly = strpos($src, "if (\$options['scripts_only'])");
        $posFtpConfig = strpos($src, '/scripts/util/ftpConfig.php');
        $posLog = strpos($src, 'Refreshing FTP configuration for --scripts-only run');

        $this->assertTrue($posScriptsOnly !== false, 'update.php should contain the scripts-only branch');
        $this->assertTrue($posFtpConfig !== false, 'update.php should reference ftpConfig.php');
        $this->assertTrue($posLog !== false, 'update.php should log ftpConfig refresh in scripts-only mode');
        $this->assertTrue($posScriptsOnly < $posFtpConfig, 'ftpConfig refresh should be inside the scripts-only branch');
        $this->assertStringContainsString('Skipping update-step2.php (--scripts-only)', $src, 'update.php should still skip update-step2 during scripts-only runs');
    }
}
