<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class DistroRepoSelectionTest extends TestCase
{
    /**
     * Ensure known Debian codenames map to the expected major versions.
     */
    public function testVersionFromCodenameMapping(): void
    {
        foreach (['buster' => 10, 'BULLSEYE' => 11, 'bookworm' => 12, 'marsupial' => 0] as $codename => $version) {
            $this->assertEquals($version, \pmssVersionFromCodename($codename));
        }
    }

    /**
     * Verify detection prefers the codename when VERSION_ID disagrees.
     */
    public function testDetectDistroTrustsCodenameForVersion(): void
    {
        $this->pmssAssertDetectedDistro([
            'ID' => 'debian',
            'VERSION_ID' => '10',
            'VERSION_CODENAME' => 'bullseye',
        ], 'debian', 11, 'bullseye');
    }

    /**
     * Confirm detection falls back to VERSION_ID when codename is unknown.
     */
    public function testDetectDistroFallsBackToVersionDigits(): void
    {
        $this->pmssAssertDetectedDistro([
            'ID' => 'debian',
            'VERSION_ID' => '42',
            'VERSION_CODENAME' => 'hyperion',
        ], 'debian', 42, 'hyperion');
    }

    /**
     * Apt templates should overwrite the configured sources when hashes differ.
     */
    public function testUpdateAptSourcesWritesTemplateForBullseye(): void
    {
        $initial = "deb http://mirror.invalid buster main\n";
        $this->pmssWithTempAptSources($initial, function (string $target) use ($initial): void {
            $template = "deb http://mirror.example bullseye main contrib non-free\n";
            $currentHash = sha1($initial);
            $logs = [];
            $logger = $this->pmssMakeArrayLogger($logs);

            \pmssUpdateAptSources('debian', 11, $currentHash, $this->pmssDebianRepoTemplates([
                'bullseye' => $template,
            ]), $logger);

            $written = file_get_contents($target);
            $this->assertEquals($template, $written);
            $this->pmssAssertMessagesContain($logs, 'Applied Debian Bullseye');

            $backup = $target.'.pmss-backup';
            $this->assertEquals($initial, file_get_contents($backup));
        });
    }

    public function testUpdateAptSourcesEolSuiteLogsTestModeSkip(): void
    {
        $initial = "deb http://mirror.invalid bullseye main\n";
        $this->pmssWithTempAptSources($initial, function (string $target) use ($initial): void {
            $template = "deb http://mirror.example buster main\n";
            $currentHash = sha1($initial);
            $logs = [];
            $logger = $this->pmssMakeArrayLogger($logs);

            \pmssUpdateAptSources('debian', 10, $currentHash, $this->pmssDebianRepoTemplates([
                'buster' => $template,
            ]), $logger);

            $this->assertEquals($template, file_get_contents($target));
            $this->pmssAssertMessagesContain($logs, 'PMSS_TEST_MODE: skipping apt conf/clean (Buster)', 'Expected EOL post-hook to log test-mode skip');
        });
    }
}
