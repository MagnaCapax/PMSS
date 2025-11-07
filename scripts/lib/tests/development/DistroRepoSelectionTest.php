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
        $osRelease = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID="10"',
            'VERSION_CODENAME=bullseye',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('debian', $info['name']);
        $this->assertEquals('bullseye', $info['codename']);
        $this->assertEquals(11, $info['version']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    /**
     * Confirm detection falls back to VERSION_ID when codename is unknown.
     */
    public function testDetectDistroFallsBackToVersionDigits(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID="42"',
            'VERSION_CODENAME=hyperion',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals(42, $info['version']);
        $this->assertEquals('hyperion', $info['codename']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    /**
     * Apt templates should overwrite the configured sources when hashes differ.
     */
    public function testUpdateAptSourcesWritesTemplateForBullseye(): void
    {
        $initial = "deb http://mirror.invalid buster main\n";
        $target = $this->tempFile('sources', $initial);
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $template = "deb http://mirror.example bullseye main contrib non-free\n";
        $currentHash = sha1($initial);
        $logs = [];
        $logger = function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        };

        \updateAptSources('debian', 11, $currentHash, [
            'bullseye' => $template,
            'buster'   => '',
            'jessie'   => '',
            'bookworm' => '',
            'trixie'   => '',
        ], $logger);

        $written = file_get_contents($target);
        $this->assertEquals($template, $written);
        $this->assertTrue((bool)array_filter($logs, static function ($m) { return strpos($m, 'Applied Debian Bullseye') !== false; }));

        $backup = $target.'.pmss-backup';
        $this->assertEquals($initial, file_get_contents($backup));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    /**
     * Helper to write a temporary os-release fixture.
     */
    private function writeOsRelease(array $lines): string
    {
        $file = $this->tempFile('os-release', implode("\n", $lines)."\n");
        return $file;
    }

    /**
     * Create a temp file with predefined contents, ensuring directories exist.
     */
    private function tempFile(string $prefix, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmss-'.$prefix.'-');
        if ($path === false) {
            $path = sys_get_temp_dir().'/pmss-'.$prefix.'-'.bin2hex(random_bytes(6));
        }
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Clear an environment variable in a portable manner.
     */
    private function clearEnv(string $name): void
    {
        putenv($name);
    }
}
