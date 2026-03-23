<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class DistroDetectionTest extends TestCase
{
    public function testVersionFromCodenameMapsKnownReleases(): void
    {
        $this->assertEquals(8, \pmssVersionFromCodename('jessie'));
        $this->assertEquals(9, \pmssVersionFromCodename('stretch'));
        $this->assertEquals(10, \pmssVersionFromCodename('buster'));
        $this->assertEquals(11, \pmssVersionFromCodename('bullseye'));
        $this->assertEquals(12, \pmssVersionFromCodename('bookworm'));
        $this->assertEquals(13, \pmssVersionFromCodename('trixie'));
        $this->assertEquals(0, \pmssVersionFromCodename('unknown'));
        $this->assertEquals(11, \pmssVersionFromCodename('Bullseye'));
    }

    public function testDebianCodenameFromMajorMapsKnownReleases(): void
    {
        $this->assertEquals('jessie', \pmssDebianCodenameFromMajor(8));
        $this->assertEquals('stretch', \pmssDebianCodenameFromMajor(9));
        $this->assertEquals('buster', \pmssDebianCodenameFromMajor(10));
        $this->assertEquals('bullseye', \pmssDebianCodenameFromMajor(11));
        $this->assertEquals('bookworm', \pmssDebianCodenameFromMajor(12));
        $this->assertEquals('trixie', \pmssDebianCodenameFromMajor(13));
        $this->assertEquals('', \pmssDebianCodenameFromMajor(99));
    }

    public function testStandaloneDistroLibraryStillBootstrapsLegacyLogmsg(): void
    {
        $path = dirname(__DIR__, 2).'/update/distro.php';
        $script = 'require '.var_export($path, true).'; $function = new ReflectionFunction("logmsg"); echo str_replace("\\\\", "/", $function->getFileName());';
        $command = 'PMSS_TEST_MODE=1 '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>/dev/null';

        $source = trim((string) @shell_exec($command));

        $this->assertStringContainsString('/scripts/lib/log.php', $source);
    }

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

    public function testOsReleaseHelpersNormalizeCodenameAndMajorVersion(): void
    {
        $this->withOsRelease([
            'ID'                => 'debian',
            'VERSION_ID'        => '12 (testing snapshot)',
            'VERSION_CODENAME'  => ' Bookworm ',
        ], function (): void {
            $this->assertEquals('12', \getDistroVersion());
            $this->assertEquals('bookworm', \getDistroCodename());
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
        }, true);
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
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use ($logger): void {
            \pmssRefreshRepositories('debian', 0, $logger);
        });
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

            $logs = [];
            $logger = function (string $message) use (&$logs): void {
                $logs[] = $message;
            };

            try {
                $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $sources], function () use ($template, $logger): void {
                    \pmssUpdateAptSources('debian', 12, sha1('different'), [
                        'bookworm' => $template,
                        'bullseye' => '',
                        'buster'   => '',
                        'jessie'   => '',
                        'trixie'   => '',
                    ], $logger);
                });

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
                @rmdir($tmpDir);
            }
        });
    }

    /**
     * Parent directory creation failures should abort before touching sources.list.
     */
    public function testSafeWriteSourcesLogsParentDirectoryFailure(): void
    {
        $tmpDir = sys_get_temp_dir().'/pmss-apt-parent-'.bin2hex(random_bytes(4));
        $blocker = $tmpDir.'/blocked';
        @mkdir($tmpDir, 0755, true);
        file_put_contents($blocker, 'not-a-directory');

        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        try {
            $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $blocker.'/sources.list'], function () use ($logger): void {
                $this->assertTrue(!\pmssSafeWriteSources("deb https://mirror.invalid bookworm main\n", 'Bookworm', $logger));
            });
            $this->assertTrue((bool) array_filter($logs, static function (string $line) use ($blocker): bool {
                return strpos($line, '[ERROR] Unable to create parent directory for Bookworm sources.list: '.$blocker) !== false;
            }));
            $this->assertTrue(!file_exists($blocker.'/sources.list'));
            $this->assertTrue(!file_exists($blocker.'/sources.list.pmss-backup'));
        } finally {
            @unlink($blocker);
            @rmdir($tmpDir);
        }
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

    public function testLoadRepoTemplateLogsMissingAndEmptyTemplates(): void
    {
        $configDir = sys_get_temp_dir().'/pmss-config-'.bin2hex(random_bytes(4));
        @mkdir($configDir, 0755, true);

        try {
            $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $configDir], function () use ($configDir): void {
                $logs = [];
                $logger = function (string $message) use (&$logs): void {
                    $logs[] = $message;
                };

                $this->assertEquals('', \pmssLoadRepoTemplate('bookworm', $logger));
                $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
                    return strpos($line, 'Repository template missing: ') !== false;
                }));

                $logs = [];
                file_put_contents($configDir.'/template.sources.bookworm', " \n\t ");
                $this->assertEquals('', \pmssLoadRepoTemplate('bookworm', $logger));
                $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
                    return strpos($line, 'Repository template empty: ') !== false;
                }));
            });
        } finally {
            @unlink($configDir.'/template.sources.bookworm');
            @rmdir($configDir);
        }
    }

    public function testDebianReleaseSpecsExposeSharedRepositoryMetadata(): void
    {
        $specs = \pmssDebianReleaseSpecs();

        $this->assertEquals('stretch', $specs[9]['repo']);
        $this->assertEquals('Stretch', $specs[9]['label']);
        $this->assertTrue($specs[9]['eol']);
        $this->assertTrue($specs[9]['sources_template'] === false);
        $this->assertEquals('trixie', $specs[13]['repo']);
        $this->assertTrue($specs[13]['sources_template']);
        $this->assertTrue($specs[13]['eol'] === false);
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
        \pmssResetOsReleaseCache();

        $env = ['PMSS_OS_RELEASE_PATH' => $file];
        if ($maskLsbRelease) {
            $env['PATH'] = sys_get_temp_dir();
        }

        try {
            $this->pmssWithEnv($env, $callback);
        } finally {
            @unlink($file);
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
        try {
            $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $dir], function () use ($callback, $dir): void {
                $callback($dir);
            });
        } finally {
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
