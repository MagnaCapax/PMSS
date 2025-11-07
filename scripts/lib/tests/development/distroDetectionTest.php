<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class DistroDetectionTest extends TestCase
{
    /**
     * Ensure codename mapping overrides a mismatched VERSION_ID.
     */
    public function testDetectPrefersCodenameWhenVersionMismatches(): void
    {
        $this->withOsRelease([
            'ID'                => 'debian',
            'VERSION_ID'        => '11',
            'VERSION_CODENAME'  => 'bookworm',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals(12, $detected['version']);
            $this->assertEquals('bookworm', $detected['codename']);
        });
    }

    /**
     * Verify VERSION_ID kicks in when the codename is absent.
     */
    public function testDetectFallsBackToVersionId(): void
    {
        $this->withOsRelease([
            'ID'         => 'debian',
            'VERSION_ID' => '11',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals(11, $detected['version']);
            $this->assertEquals('', $detected['codename']);
        }, true);
    }

    /**
     * Confirm codename case is normalised prior to mapping.
     */
    public function testDetectNormalisesCodenameCase(): void
    {
        $this->withOsRelease([
            'ID'                => 'debian',
            'VERSION_CODENAME'  => 'Bullseye',
            'VERSION_ID'        => '',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals('bullseye', $detected['codename']);
            $this->assertEquals(11, $detected['version']);
        });
    }

    /**
     * If both codename and version are missing we should surface zero.
     */
    public function testDetectHandlesMissingVersionSignals(): void
    {
        $this->withOsRelease([
            'ID' => 'debian',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals(0, $detected['version']);
        }, true);
    }

    /**
     * Non-numeric VERSION_ID strings should still produce an integer.
     */
    public function testDetectParsesMessyVersionId(): void
    {
        $this->withOsRelease([
            'ID'         => 'debian',
            'VERSION_ID' => '12 (testing snapshot)',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals(12, $detected['version']);
        });
    }

    /**
     * Unknown versions should skip template rewrites and reuse existing sources.
     */
    public function testRefreshRepositoriesSkipsWhenVersionUnknown(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };
        $originalDryRun = getenv('PMSS_DRY_RUN');
        putenv('PMSS_DRY_RUN=1');
        \pmssRefreshRepositories('debian', 0, $logger);
        if ($originalDryRun === false) {
            putenv('PMSS_DRY_RUN');
        } else {
            putenv('PMSS_DRY_RUN='.$originalDryRun);
        }
        $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
            return strpos($line, 'reusing existing sources') !== false;
        }), 'Expected reuse notice when version unresolved');
    }

    /**
     * Repository updates should write new content when hashes differ.
     */
    public function testUpdateAptSourcesWritesTemplate(): void
    {
        $template = "deb https://mirror.invalid bookworm main\n";
        $this->withConfigTemplates(['bookworm' => $template], function () use ($template): void {
            $tmpDir = sys_get_temp_dir().'/pmss-apt-'.bin2hex(random_bytes(4));
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0775, true);
            }
            $sources = $tmpDir.'/sources.list';
            file_put_contents($sources, "deb https://old.invalid stable main\n");
            putenv('PMSS_APT_SOURCES_PATH='.$sources);

            $logs = [];
            $logger = function (string $message) use (&$logs): void {
                $logs[] = $message;
            };

            try {
                \pmssUpdateAptSources('debian', 12, sha1('different'), [
                    'bookworm' => $template,
                    'bullseye' => '',
                    'buster'   => '',
                    'jessie'   => '',
                    'trixie'   => '',
                ], $logger);

                $this->assertEquals($template, file_get_contents($sources));
                $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
                    return strpos($line, 'Applied Debian Bookworm repository config') !== false;
                }));
            } finally {
                if (file_exists($sources)) {
                    unlink($sources);
                }
                $backup = $sources.'.pmss-backup';
                if (file_exists($backup)) {
                    unlink($backup);
                }
                putenv('PMSS_APT_SOURCES_PATH');
                @rmdir($tmpDir);
            }
        });
    }

    /**
     * Unsupported distros should emit an informative log message.
     */
    public function testUpdateAptSourcesLogsUnsupportedDistro(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };
        \pmssUpdateAptSources('alpine', 3, 'hash', [], $logger);
        $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
            return strpos($line, 'Unsupported distro') !== false;
        }));
    }

    /**
     * Helper to stage an os-release fixture for the duration of the callback.
     */
    private function withOsRelease(array $fields, callable $callback, bool $maskLsbRelease = false): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pmss-osr-');
        if ($file === false) {
            throw new \RuntimeException('Unable to allocate os-release fixture');
        }
        file_put_contents($file, $this->renderOsRelease($fields));
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $originalPath = $maskLsbRelease ? getenv('PATH') : null;
        if ($maskLsbRelease) {
            putenv('PATH='.sys_get_temp_dir());
        }
        try {
            $callback();
        } finally {
            if ($maskLsbRelease) {
                if ($originalPath === false || $originalPath === null) {
                    putenv('PATH');
                } else {
                    putenv('PATH='.$originalPath);
                }
            }
            @unlink($file);
            putenv('PMSS_OS_RELEASE_PATH');
            \pmssResetOsReleaseCache();
        }
    }

    /**
     * Helper to stage template directory overrides.
     */
    private function withConfigTemplates(array $templates, callable $callback): void
    {
        $dir = sys_get_temp_dir().'/pmss-config-'.bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        foreach ($templates as $codename => $content) {
            file_put_contents($dir."/template.sources.$codename", $content);
        }
        putenv('PMSS_CONFIG_DIR='.$dir);
        try {
            $callback($dir);
        } finally {
            putenv('PMSS_CONFIG_DIR');
            foreach ((glob($dir.'/template.sources.*') ?: []) as $item) {
                @unlink($item);
            }
            @rmdir($dir);
        }
    }

    /**
     * Render key/value pairs into an os-release style document.
     */
    private function renderOsRelease(array $fields): string
    {
        $lines = [];
        foreach ($fields as $key => $value) {
            if ($value === '') {
                $lines[] = $key.'=';
            } else {
                $lines[] = $key.'="'.str_replace('"', '\"', $value).'"';
            }
        }
        return implode("\n", $lines)."\n";
    }
}
