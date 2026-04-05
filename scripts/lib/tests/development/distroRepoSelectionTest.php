<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class DistroRepoSelectionTest extends TestCase
{
    /**
     * Ensure known Debian codenames map to the expected major versions.
     */
    public function testVersionFromCodenameMapping(): void
    {
        $this->assertEquals(10, \pmssVersionFromCodename('buster'));
        $this->assertEquals(11, \pmssVersionFromCodename('BULLSEYE'));
        $this->assertEquals(12, \pmssVersionFromCodename('bookworm'));
        $this->assertEquals(0, \pmssVersionFromCodename('marsupial'));
    }

    /**
     * Verify detection prefers the codename when VERSION_ID disagrees.
     */
    public function testDetectDistroTrustsCodenameForVersion(): void
    {
        $this->pmssWithOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '10',
            'VERSION_CODENAME' => 'bullseye',
        ], function (): void {
            $info = \pmssDetectDistro();
            $this->assertEquals('debian', $info['name']);
            $this->assertEquals('bullseye', $info['codename']);
            $this->assertEquals(11, $info['version']);
        });
    }

    /**
     * Confirm detection falls back to VERSION_ID when codename is unknown.
     */
    public function testDetectDistroFallsBackToVersionDigits(): void
    {
        $this->pmssWithOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '42',
            'VERSION_CODENAME' => 'hyperion',
        ], function (): void {
            $info = \pmssDetectDistro();
            $this->assertEquals(42, $info['version']);
            $this->assertEquals('hyperion', $info['codename']);
        });
    }

    /**
     * Apt templates should overwrite the configured sources when hashes differ.
     */
    public function testUpdateAptSourcesWritesTemplateForBullseye(): void
    {
        $initial = "deb http://mirror.invalid buster main\n";
        $target = $this->pmssWriteTempFile('sources', $initial);
        $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $target], function () use ($initial, $target): void {
            $template = "deb http://mirror.example bullseye main contrib non-free\n";
            $currentHash = sha1($initial);
            $logs = [];
            $logger = $this->pmssMakeArrayLogger($logs);

            \updateAptSources('debian', 11, $currentHash, [
                'bullseye' => $template,
                'buster'   => '',
                'jessie'   => '',
                'bookworm' => '',
                'trixie'   => '',
            ], $logger);

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
        $target = $this->pmssWriteTempFile('sources', $initial);
        $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $target], function () use ($initial, $target): void {
            $template = "deb http://mirror.example buster main\n";
            $currentHash = sha1($initial);
            $logs = [];
            $logger = $this->pmssMakeArrayLogger($logs);

            \updateAptSources('debian', 10, $currentHash, [
                'buster'   => $template,
                'bullseye' => '',
                'jessie'   => '',
                'bookworm' => '',
                'trixie'   => '',
            ], $logger);

            $this->assertEquals($template, file_get_contents($target));
            $this->pmssAssertMessagesContain($logs, 'PMSS_TEST_MODE: skipping apt conf/clean (Buster)', 'Expected EOL post-hook to log test-mode skip');
        });
    }
}
