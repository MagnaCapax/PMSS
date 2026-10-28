<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
if (!defined('PMSS_TEST_MODE')) define('PMSS_TEST_MODE', true);
require_once dirname(__DIR__, 2).'/update/apps/rclone.php';

/** The rclone zip is installed only after its SHA-256 matches the pin. */
final class RcloneInstallerTest extends TestCase
{
    public function testPinIsAWellFormedVersionAndSha256(): void
    {
        $this->assertMatches('/\A\d+\.\d+\.\d+\z/', PMSS_RCLONE_VERSION);
        $this->assertMatches('/\A[a-f0-9]{64}\z/', PMSS_RCLONE_SHA256);
    }

    public function testInstallUnpacksTheVerifiedDownloadOnly(): void
    {
        $this->pmssResetRuntimeProfile();
        $this->pmssCaptureStdout(static function (): void {
            \pmssRcloneInstallFromZip('1.69.1', PMSS_RCLONE_SHA256);
            \pmssRcloneInstallFromZip('1.69.2', 'not-a-sha256');
            \pmssRcloneInstallFromZip('1.69.1; id', PMSS_RCLONE_SHA256);
        }, ['PMSS_DRY_RUN' => '1']);

        $commands = $this->pmssProfileCommands();
        $this->assertSame(2, count($commands), implode(' | ', $commands));
        $this->assertMatches("~\Awget '-q' '-O' '([^']+)' 'https://downloads\.rclone\.org/v1\.69\.1/rclone-v1\.69\.1-linux-amd64\.zip'\z~", $commands[0]);
        preg_match("~'-O' '([^']+)'~", $commands[0], $download);
        $install = (string) $this->pmssFindProfileCommand('Installing rclone 1.69.1');
        $this->assertStringContainsString("unzip '-q' '".$download[1]."'", $install);
        $this->assertStringContainsString("install '-m' '0755' '-o' 'root' '-g' 'root' 'rclone' '/usr/bin/rclone'", $install);
        $this->assertStringNotContainsString('wget', $install);
    }
}
