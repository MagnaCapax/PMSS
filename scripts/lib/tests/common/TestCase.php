<?php
namespace {
    if (!function_exists('pmssTestInstallRunUserStepShim')) {
        function pmssTestInstallRunUserStepShim(string $mode = 'noop'): void
        {
            $GLOBALS['PMSS_TEST_RUNUSERSTEP_MODE'] = $mode;
            if (function_exists('runUserStep')) {
                return;
            }
            require_once __DIR__.'/runUserStepShim.php';
        }
    }
}

namespace PMSS\Tests {

require_once __DIR__.'/../../testing/filesystem.php';
require_once __DIR__.'/FilesystemCleanupTrait.php';

class SkipTest extends \Exception {}

abstract class TestCase
{
    use FilesystemCleanupTrait;

    /**
     * @var array<int, array{0:bool|string,1:string,2:?string}>
     */
    private $results = [];

    /**
     * @var array<int, string>
     */
    private $tempPaths = [];

    /**
     * @var string Shared fixture directory for tests with one primary temp root.
     */
    protected $tempDir = '';

    /**
     * @var array<string, string>
     */
    private $tempDirProperties = [];

    /**
     * @var array<int, array{values:array<string, string|false>,unsetEmptyString:bool}>
     */
    private $trackedEnvOverrides = [];

    /** Provide a shared no-op setup hook for inheriting tests. */
    protected function setUp(): void
    {
    }

    /** Provide a shared no-op teardown hook for inheriting tests. */
    protected function tearDown(): void
    {
    }

    /** Reset the shared runtime profile globals between dry-run assertions. */
    protected function pmssResetRuntimeProfile(): void
    {
        unset($GLOBALS['PMSS_PROFILE'], $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);
    }

    /** Return recorded runtime commands from the shared dry-run profile. */
    protected function pmssProfileCommands(): array
    {
        return array_map(static function (array $entry): string {
            return (string) ($entry['command'] ?? '');
        }, $GLOBALS['PMSS_PROFILE'] ?? []);
    }

    /** Return a dry-run profile command whose description contains a substring. */
    protected function pmssFindProfileCommand(string $needle): ?string
    {
        foreach (($GLOBALS['PMSS_PROFILE'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $description = (string) ($entry['description'] ?? '');
            if (strpos($description, $needle) === false) {
                continue;
            }

            return isset($entry['command']) ? (string) $entry['command'] : null;
        }

        return null;
    }

    public function run(): array
    {
        $methods = array_filter(get_class_methods($this), static function ($method) {
            return strpos($method, 'test') === 0;
        });
        foreach ($methods as $method) {
            $status = true;
            $message = null;
            $setUpCompleted = false;
            try {
                $this->setUp();
                $setUpCompleted = true;
                $this->$method();
            } catch (SkipTest $e) {
                $status = 'skip';
                $message = $e->getMessage();
            } catch (\AssertionError $e) {
                $status = false;
                $message = $e->getMessage();
            } catch (\Throwable $e) {
                $status = false;
                $message = $e->getMessage();
            }

            try {
                if ($setUpCompleted) {
                    $this->tearDown();
                }
            } catch (\Throwable $e) {
                $status = false;
                $message = $e->getMessage();
            }

            try {
                $this->pmssCleanupTrackedEnvOverrides();
            } catch (\Throwable $e) {
                $status = false;
                $message = $e->getMessage();
            }

            $this->pmssCleanupTempPaths();
            $this->results[] = [$status, $method, $message];
        }
        return $this->results;
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \AssertionError($message !== '' ? $message : 'Assertion failed: expected true');
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertTrue(!$condition, $message !== '' ? $message : 'Assertion failed: expected false');
    }

    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            $msg = $message !== '' ? $message : sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
            throw new \AssertionError($msg);
        }
    }

    protected function assertSame($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $msg = $message !== '' ? $message : sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
            throw new \AssertionError($msg);
        }
    }

    protected function assertMatches(string $pattern, string $value, string $message = ''): void
    {
        if (!preg_match($pattern, $value)) {
            $msg = $message !== '' ? $message : sprintf('Value %s does not match pattern %s', $value, $pattern);
            throw new \AssertionError($msg);
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) === false) {
            $msg = $message !== '' ? $message : sprintf('Expected string to contain %s, but it did not', var_export($needle, true));
            throw new \AssertionError($msg);
        }
    }

    /** Assert that a string omits a forbidden substring. */
    protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) !== false) {
            throw new \AssertionError($message !== '' ? $message : sprintf('Expected string to not contain %s, but it did', var_export($needle, true)));
        }
    }

    /** Keep the PMSS-prefixed assertion available for existing tests. */
    protected function pmssAssertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertStringNotContainsString($needle, $haystack, $message);
    }

    protected function assertStringContainsAllStrings(array $needles, string $haystack, string $messagePrefix = ''): void
    {
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $haystack, $messagePrefix !== '' ? $messagePrefix.$needle : '');
        }
    }

    /** Assert required and forbidden substrings against one haystack. */
    protected function assertStringContainsAndOmitsStrings(array $required, array $forbidden, string $haystack, string $messagePrefix = ''): void
    {
        $this->assertStringContainsAllStrings($required, $haystack, $messagePrefix);
        foreach ($forbidden as $key => $value) {
            $needle = is_int($key) ? (string) $value : (string) $key;
            $message = is_int($key) ? ($messagePrefix !== '' ? $messagePrefix.$needle : '') : (string) $value;
            $this->pmssAssertStringNotContainsString($needle, $haystack, $message);
        }
    }

    /** Assert that a generated fixture file exists and contains every expected fragment. */
    protected function pmssAssertFileContainsAllStrings(string $path, array $needles, string $missingMessage = '', string $messagePrefix = ''): string
    {
        $this->assertTrue(file_exists($path), $missingMessage !== '' ? $missingMessage : 'Expected file to exist: '.$path);
        $content = (string) file_get_contents($path);
        $this->assertStringContainsAllStrings($needles, $content, $messagePrefix);
        return $content;
    }

    protected function assertOrderedStrings(array $needles, string $haystack, string $missingPrefix = '', string $orderPrefix = ''): void
    {
        $offset = -1;
        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle);
            $this->assertTrue($position !== false, $missingPrefix !== '' ? $missingPrefix.$needle : 'Missing substring: '.$needle);
            $this->assertTrue($position > $offset, $orderPrefix !== '' ? $orderPrefix.$needle : 'String order changed at: '.$needle);
            $offset = $position;
        }
    }

    protected function fail(string $message = ''): void
    {
        throw new \AssertionError($message !== '' ? $message : 'Test failed');
    }

    /** Assert that a callback throws the expected exception type and optional message fragment. */
    protected function assertThrows(string $class, callable $callback, string $messageFragment = '', ?int $code = null): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->assertTrue(is_a($e, $class), sprintf('Expected %s, got %s', $class, get_class($e)));
            if ($messageFragment !== '') {
                $this->assertStringContainsString($messageFragment, $e->getMessage());
            }
            if ($code !== null) {
                $this->assertEquals($code, $e->getCode());
            }
            return;
        }

        $this->fail('Expected '.$class.', none thrown');
    }

    /** Assert that a callback throws RuntimeException with an optional message fragment. */
    protected function assertThrowsRuntime(callable $callback, string $messageFragment = '', ?int $code = null): void
    {
        $this->assertThrows(\RuntimeException::class, $callback, $messageFragment, $code);
    }

    /** Skip the current test when an optional helper is unavailable. */
    protected function pmssSkipUnlessFunctionExists(string $function): void
    {
        if (!function_exists($function)) {
            throw new SkipTest($function.' helper not present in this baseline');
        }
    }

    /**
     * Capture stdout emitted by a callback and return its result alongside the buffer.
     *
     * @return array{0:mixed,1:string}
     */
    protected function pmssCaptureStdout(callable $callback): array
    {
        ob_start();
        try {
            return [$callback(), (string) ob_get_clean()];
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /** Assert required and forbidden fstab options for one mount point. */
    protected function pmssAssertFstabOptions(string $fstab, string $mountPoint, array $required, array $forbidden = []): void
    {
        $options = [];
        foreach (file($fstab, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (is_array($columns) && count($columns) >= 4 && $columns[1] === $mountPoint) {
                $options = array_values(array_filter(explode(',', $columns[3]), 'strlen'));
                break;
            }
        }
        foreach ($required as $option) {
            $this->assertTrue(in_array($option, $options, true), 'expected '.$option.' option');
        }
        foreach ($forbidden as $option) {
            $this->assertTrue(!in_array($option, $options, true), 'expected '.$option.' removed');
        }
    }

    protected function isSandbox(): bool
    {
        if (getenv('PMSS_SANDBOX') === '1') return true;
        // Not root or no systemd bus → assume sandbox/CI
        $notRoot = function_exists('posix_geteuid') ? (posix_geteuid() !== 0) : true;
        $noSystemd = !is_dir('/run/systemd/system');
        return $notRoot || $noSystemd;
    }

    /** Resolve the test temp root, honoring the shared hermetic override. */
    private function pmssTempRoot(): string
    {
        $base = getenv('PMSS_TEST_TEMP_ROOT');
        return is_string($base) && $base !== '' ? $base : sys_get_temp_dir();
    }

    /** Create a unique temporary directory for hermetic tests. */
    protected function pmssMakeTempDir(string $prefix, int $mode = 0755): string
    {
        $path = rtrim($this->pmssTempRoot(), '/').'/'.$prefix.bin2hex(random_bytes(6));
        $this->assertTrue(@mkdir($path, $mode, true) || is_dir($path), 'Expected temporary directory to exist: '.$path);
        $this->tempPaths[] = $path;
        return $path;
    }

    /** Create and track a unique temporary file for hermetic tests. */
    protected function pmssMakeTempFile(string $prefix = 'pmss'): string
    {
        $base = $this->pmssTempRoot();
        $path = @tempnam($base, $prefix);
        if ($path === false) {
            $path = rtrim($base, '/').'/'.$prefix.bin2hex(random_bytes(6));
            $this->assertTrue(@touch($path), 'Expected temporary file to exist: '.$path);
        }

        $this->assertTrue(is_file($path), 'Expected temporary file to exist: '.$path);
        $this->tempPaths[] = $path;
        return $path;
    }

    /** Point PMSS_HOME_DIR at a fixture home root and restore it automatically after the test. */
    protected function pmssTrackHomeRoot(string $homeRoot, bool $unsetEmptyString = true): string
    {
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $homeRoot], $unsetEmptyString);
        return $homeRoot;
    }

    /** Create a fresh fixture home root and register it as PMSS_HOME_DIR for the current test. */
    protected function pmssMakeTrackedHomeRoot(string $prefix, int $mode = 0755): string
    {
        return $this->pmssTrackHomeRoot($this->pmssMakeTempDir($prefix, $mode));
    }

    /** Reserve and track a unique temporary filesystem path for hermetic tests. */
    protected function pmssMakeTempPath(string $prefix, string $suffix = ''): string
    {
        $path = rtrim($this->pmssTempRoot(), '/').'/'.$prefix.bin2hex(random_bytes(6)).$suffix;
        $this->tempPaths[] = $path;
        return $path;
    }

    /** Assert that a callback leaves no new paths behind for a glob pattern. */
    protected function pmssAssertNoNewGlobMatches(string $pattern, callable $callback, string $message = ''): void
    {
        $before = $this->pmssGlobMatches($pattern);
        $callback();
        $after = $this->pmssGlobMatches($pattern);

        $this->assertEquals([], array_values(array_diff($after, $before)), $message !== '' ? $message : 'Expected no new paths for '.$pattern);
    }

    /** Return glob matches as an array, treating unsupported patterns as empty. */
    protected function pmssGlobMatches(string $pattern): array
    {
        $matches = glob($pattern);
        return is_array($matches) ? array_values($matches) : [];
    }

    /** Create a tracked temporary PHP fixture path keyed by a readable suffix. */
    protected function pmssMakeTempPhpPath(string $prefix, string $suffix): string
    {
        return $this->pmssMakeTempPath($prefix.$suffix.'-', '.php');
    }

    /** Create a temporary home fixture and optionally nest it beneath a relative path. */
    protected function pmssMakeUserHomeTree(string $prefix, string $relativeDir = '', string $homeRelativePath = ''): string
    {
        $home = $this->pmssMakeTempDir($prefix);
        if ($homeRelativePath !== '') {
            $home .= '/'.trim($homeRelativePath, '/');
            $this->pmssEnsureDir($home, 0755);
        }

        if ($relativeDir !== '') {
            $this->pmssEnsureDir($home.'/'.ltrim($relativeDir, '/'), 0755);
        }

        return $home;
    }

    /** Build a user-home path under a caller-provided temporary home root. */
    protected function pmssUserHomePath(string $homeRoot, string $user, string $relative = ''): string
    {
        $home = rtrim($homeRoot, '/').'/'.$user;
        return $relative === '' ? $home : $home.'/'.ltrim($relative, '/');
    }

    /** Build the standard user/home context array consumed by fixture helpers. */
    protected function pmssUserHomeContext(string $homeRoot, string $user): array
    {
        return ['user' => $user, 'home' => $this->pmssUserHomePath($homeRoot, $user)];
    }

    /** Build the updater user-context shape shared by user maintenance handlers. */
    protected function pmssUserUpdateContext(string $home, string $user = 'dummy', array $extra = []): array
    {
        return array_replace([
            'user' => $user,
            'home' => $home,
            'user_esc' => escapeshellarg($user),
        ], $extra);
    }

    /** Write a PHP array fixture that can be loaded with include/require. */
    protected function pmssWritePhpArrayFixture(array $value, string $prefix = 'pmss-fixture-'): string
    {
        $path = $this->pmssMakeTempPath($prefix, '.php');
        return $this->pmssWriteFile($path, "<?php return ".var_export($value, true).";\n");
    }

    /** Read an array-shaped JSON fixture and optionally fall back on decode failures. */
    protected function pmssReadJsonArrayFile(string $path, ?array $default = null, string $message = ''): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if ($default !== null) {
            return $default;
        }

        $this->fail($message !== '' ? $message : 'Expected JSON array fixture: '.$path);
        return [];
    }

    /** Create a tracked JSON-lines fixture path under a fresh temporary directory. */
    protected function pmssMakeJsonLogPath(string $dirPrefix, string $filename = 'fixture.jsonl'): string
    {
        return $this->pmssMakeTempDir($dirPrefix, 0700).'/'.$filename;
    }

    /** Create a storage benchmark JSONL fixture and append the supplied entries. */
    protected function pmssWriteStorageBenchmarkLog(array $entries, string $dirPrefix = 'pmss-bench-'): string
    {
        $log = $this->pmssMakeJsonLogPath($dirPrefix, 'benchmark-storage.jsonl');
        $this->pmssAppendFixtureLines($log, $entries);
        return $log;
    }

    /** Create a one-run storage benchmark JSONL fixture with a preflight entry. */
    protected function pmssWriteStorageBenchmarkRunLog(string $runId, string $runTs, array $entries = [], array $preflightExtra = [], string $dirPrefix = 'pmss-bench-'): string { array_unshift($entries, $this->pmssStorageBenchmarkPreflightEntry($runId, $runTs, $preflightExtra)); return $this->pmssWriteStorageBenchmarkLog($entries, $dirPrefix); }

    /** Build storage-benchmark JSONL entries with the common run identity. */
    protected function pmssStorageBenchmarkEntry(string $runId, string $runTs, string $test, array $extra = []): array { return array_replace(['run_id' => $runId, 'run_ts' => $runTs, 'test' => $test], $extra); }
    protected function pmssStorageBenchmarkPreflightEntry(string $runId, string $runTs, array $extra = []): array { return $this->pmssStorageBenchmarkEntry($runId, $runTs, 'preflight-idle', array_replace(['ok' => true], $extra)); }

    /** Build the repeated file-backed fio entry shape used by storage benchmark tests. */
    protected function pmssStorageBenchmarkFileEntry(string $runId, string $runTs, string $test = 'randread-small', array $metrics = [], array $extra = []): array { return $this->pmssStorageBenchmarkEntry($runId, $runTs, $test, array_replace(['params' => ['rw' => 'randread'], 'metrics' => array_replace(['read_bw_MBps' => 1, 'write_bw_MBps' => 0, 'read_iops' => 1, 'write_iops' => 0, 'read_p95_ms' => 1, 'write_p95_ms' => 0], $metrics)], $extra)); }

    /** Persist serialized fixture data while ensuring the parent path exists. */
    protected function pmssWriteSerializedFixture(string $path, $value): void
    {
        $this->pmssWriteFile($path, serialize($value));
    }

    protected function pmssBuildWindowValues($month, $week = null, $day = null, $hour = null): array
    {
        $week = $week ?? $month;
        $day = $day ?? $week;
        return ['month' => $month, 'week' => $week, 'day' => $day, 'hour' => $hour ?? $day];
    }

    /** Build the serialized resource stats shape used by report-oriented tests. */
    protected function pmssBuildResourceStatsPayloadFromValues(array $values): array
    {
        $memoryRaw = $values['memory'] ?? ['month' => $values['memory_avg_month']];
        $tasksRaw = $values['tasks'] ?? [];
        return [
            'io_read' => ['raw' => $values['io_read']],
            'io_write' => ['raw' => $values['io_write']],
            'io_read_ops' => ['raw' => $values['io_read_ops'] ?? []],
            'io_write_ops' => ['raw' => $values['io_write_ops'] ?? []],
            'cpu' => ['raw' => $values['cpu']],
            'memory' => ['current' => $values['memory_current'] ?? ($memoryRaw['month'] ?? 0.0), 'raw' => $memoryRaw],
            'ram_hours' => ['raw' => $values['ram_hours']],
            'tasks' => ['current' => $values['tasks_current'] ?? ($tasksRaw['month'] ?? 0.0), 'raw' => $tasksRaw],
        ];
    }

    /** Build the normalized resource report row shape used by report assertions. */
    protected function pmssBuildResourceReportRowFromValues(array $values): array
    {
        return [
            'io_read' => array_map('floatval', $values['io_read']), 'io_write' => array_map('floatval', $values['io_write']),
            'io_read_ops' => array_map('floatval', $values['io_read_ops'] ?? $this->pmssBuildWindowValues(0.0)), 'io_write_ops' => array_map('floatval', $values['io_write_ops'] ?? $this->pmssBuildWindowValues(0.0)),
            'cpu' => array_map('floatval', $values['cpu']), 'ram_hours' => array_map('floatval', $values['ram_hours']),
            'memory' => ['current' => (float) $values['memory_current'], 'avg_month' => (float) $values['memory_avg_month']],
            'tasks' => ['current' => (float) $values['tasks_current']],
        ];
    }

    /** Keep shared source-based listUsers consumer assertions in one place. */
    protected function pmssListUsersConsumerMap(): array
    {
        return [
            'pmssResourceLogManagedUserUids()' => [
                'scripts/cron/resourceLog.php',
                'scripts/cron/resourceSnapshot.php',
                'scripts/cron/trafficLog.php',
                'scripts/cron/trafficIngressLog.php',
                'scripts/util/makeMonitoringRules.php',
            ],
            "pmssListManagedUsers('/scripts/listUsers.php')" => [
                'scripts/cron/updateQuotas.php',
                'scripts/util/checkRutorrentPlugins.php',
                'scripts/util/setupNetwork.php',
                'scripts/lib/user/resourcesList.php',
            ],
            'pmssManagedUsersSelectFromCommand(' => [
                'scripts/cron/checkLighttpdInstances.php',
                'scripts/util/checkUserHtpasswd.php',
                'scripts/util/userConfigLighttpd.php',
                'scripts/lib/nginxConfig/main.php',
            ],
            'pmssListManagedUsersResult(' => [
                'scripts/cron/checkRtorrent.php',
                'scripts/cron/userTrackerCleaner.php',
                'scripts/lib/traffic/report.php',
            ],
            'pmssListManagedUsersFromResult(pmssListManagedUsersResult(' => [
                'scripts/cron/trafficLimits.php',
                'scripts/lib/resources/show.php',
                'scripts/userTorrents.php',
            ],
        ];
    }

    /** Append fixture lines, encoding arrays as JSON and preserving raw strings. */
    protected function pmssAppendFixtureLines(string $path, array $lines): void
    {
        foreach ($lines as $line) {
            $payload = is_array($line) ? json_encode($line) : (string) $line;
            $this->assertTrue($payload !== false, 'Expected fixture line to encode as JSON');
            $written = @file_put_contents($path, $payload."\n", FILE_APPEND);
            $this->assertTrue($written !== false, 'Expected fixture line to be appended: '.$path);
        }
    }

    /** Create a tracked readable file under a fresh temporary directory. */
    protected function pmssMakeReadableTempPath(string $dirPrefix, string $filePrefix = 'pmss'): string
    {
        $path = @tempnam($this->pmssMakeTempDir($dirPrefix), $filePrefix);
        $this->assertTrue($path !== false, 'Expected a temporary readable path');
        $this->assertTrue(is_file((string) $path), 'Expected temporary readable path to exist');
        return (string) $path;
    }

    /** Return a complete Debian repo-template map with caller overrides layered on top. */
    protected function pmssDebianRepoTemplates(array $overrides = []): array
    {
        return array_merge([
            'jessie' => '',
            'buster' => '',
            'bullseye' => '',
            'bookworm' => '',
            'trixie' => '',
        ], $overrides);
    }

    /** Expose a caller-provided sources.list path through PMSS_APT_SOURCES_PATH. */
    protected function pmssWithAptSourcesPath(string $path, callable $callback): void
    {
        $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $path], function () use ($callback, $path): void {
            $callback($path);
        });
    }

    /** Stage a temporary sources.list fixture and expose it through PMSS_APT_SOURCES_PATH. */
    protected function pmssWithTempAptSources(string $content, callable $callback): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sources-', 0700);
        $path = $this->pmssWriteFile($dir.'/sources.list', $content, 0700);
        @chmod($path, 0600);

        $this->pmssWithAptSourcesPath($path, $callback);
    }

    /** Stage template.sources.* fixtures under PMSS_CONFIG_DIR for repository update tests. */
    protected function pmssWithRepoTemplates(array $templates, callable $callback, int $dirMode = 0700): void
    {
        $dir = $this->pmssMakeTempDir('pmss-config-', $dirMode);

        foreach ($templates as $codename => $content) {
            $path = $this->pmssWriteFile(
                $dir.'/template.sources.'.$codename,
                rtrim((string) $content, "\n")."\n",
                $dirMode
            );
            @chmod($path, 0600);
        }

        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $dir], function () use ($callback, $dir): void {
            $callback($dir);
        });
    }

    /**
     * Create paired config and rsyslog target directories for remote logging tests.
     *
     * @return array{cfgDir:string,targetDir:string,target:string}
     */
    protected function pmssRemoteLoggingFixture(string $prefix = 'pmss-remote-logging-'): array
    {
        $targetDir = $this->pmssMakeTempDir($prefix.'rsyslog-');
        return [
            'cfgDir' => $this->pmssMakeTempDir($prefix.'cfg-'),
            'targetDir' => $targetDir,
            'target' => $targetDir.'/50-pmss-remote.conf',
        ];
    }

    /** Apply a remote logging fixture under its hermetic config and target directories. */
    protected function pmssApplyRemoteLoggingFixture(array $fixture, array &$messages, array $extraEnv = []): void
    {
        $this->pmssWithEnv(array_merge([
            'PMSS_CONFIG_DIR' => $fixture['cfgDir'],
            'PMSS_RSYSLOG_CONF_DIR' => $fixture['targetDir'],
        ], $extraEnv), function () use (&$messages): void {
            \pmssApplyRemoteLogging($this->pmssMakeArrayLogger($messages));
        });
    }

    /**
     * Prepare a hermetic systemd user-slice fixture with templates, policy, and env overrides.
     *
     * @param array<string, mixed> $options
     * @return array{cfgDir:string,dropDir:string,env:array<string,string>}
     */
    protected function pmssSystemdSliceFixturePrepare(array $options = []): array
    {
        $cfgDir = $this->pmssMakeTempDir(isset($options['cfgPrefix']) ? (string) $options['cfgPrefix'] : 'pmss-cg-cfg-');
        $dropDir = isset($options['dropDir'])
            ? (string) $options['dropDir']
            : $this->pmssMakeTempDir(isset($options['dropPrefix']) ? (string) $options['dropPrefix'] : 'pmss-cg-drop-');

        $v1Template = array_key_exists('v1Template', $options) ? $options['v1Template'] : 'ignored';
        $v2Template = array_key_exists('v2Template', $options) ? $options['v2Template'] : 'ignored';

        if ($v1Template !== null) {
            $this->pmssWriteFile($cfgDir.'/template.cgroup.user-slice.v1.conf', (string) $v1Template);
        }
        if ($v2Template !== null) {
            $this->pmssWriteFile($cfgDir.'/template.cgroup.user-slice.v2.conf', (string) $v2Template);
        }
        if (array_key_exists('policy', $options)) {
            $this->pmssWriteFile($cfgDir.'/cgroup.policy.php', (string) $options['policy']);
        }

        $env = [
            'PMSS_CGROUP_MODE' => isset($options['mode']) ? (string) $options['mode'] : 'v2',
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_SYSTEMD_USER_SLICE_DIR' => $dropDir,
            'PMSS_TOTAL_MEM_MIB' => (string) (isset($options['totalMemMiB']) ? $options['totalMemMiB'] : 2048),
        ];
        if (isset($options['totalCpuThreads'])) {
            $env['PMSS_TOTAL_CPU_THREADS'] = (string) $options['totalCpuThreads'];
        }
        if (isset($options['env']) && is_array($options['env'])) {
            foreach ($options['env'] as $key => $value) {
                $env[(string) $key] = (string) $value;
            }
        }

        return [
            'cfgDir' => $cfgDir,
            'dropDir' => $dropDir,
            'env' => $env,
        ];
    }

    /** @param array{env:array<string,string>} $fixture */
    protected function pmssSystemdSliceEnsure(array $fixture): void
    {
        $this->pmssWithEnv($fixture['env'], function (): void {
            \pmssEnsureSystemdSlices('logmsg');
        });
    }

    /** @param array{dropDir:string,env:array<string,string>} $fixture */
    protected function pmssSystemdSliceDropinRender(array $fixture, string $name = '15-pmss.conf'): string
    {
        $this->pmssSystemdSliceEnsure($fixture);
        return (string) file_get_contents(rtrim($fixture['dropDir'], '/').'/'.$name);
    }

    /** Render the canonical drop-in directly from fixture options. */
    protected function pmssSystemdSliceRender(array $options = [], string $name = '15-pmss.conf'): string
    {
        return $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare($options), $name);
    }
    /** Build a simple `[Slice]` template with optional directives before `TasksMax`. */
    protected function pmssSystemdSliceTasksTemplate(array $directives = []): string
    {
        $lines = ['[Slice]'];
        foreach ($directives as $directive) { $lines[] = (string) $directive; }
        $lines[] = 'TasksMax=%%USER_CGROUP_TASKS_MAX%%';
        return implode("\n", $lines)."\n";
    }
    /** Build a PHP policy fixture matching the on-disk cgroup policy contract. */
    protected function pmssSystemdSlicePolicySource(array $policy): string
    {
        return '<?php return '.var_export($policy, true).";\n";
    }

    /** Create an executable test stub in a fresh PATH directory. */
    protected function pmssMakeExecutableStub(string $binaryName, string $script, string $dirPrefix): string
    {
        $binDir = $this->pmssMakeTempDir($dirPrefix);
        $this->pmssWriteExecutableFile($binDir.'/'.$binaryName, $script);
        return $binDir;
    }

    /** Create a stub that prints fixed lines to stdout for parser tests. */
    protected function pmssMakeLineOutputStub(string $binaryName, array $outputLines, string $dirPrefix): string
    {
        $script = "#!/bin/sh\n";
        foreach ($outputLines as $line) {
            $script .= "printf '%s\\n' ".escapeshellarg($line)."\n";
        }

        return $this->pmssMakeExecutableStub($binaryName, $script, $dirPrefix);
    }

    /** Create a stub that appends every invocation to a log file. */
    protected function pmssMakeInvocationLogStub(string $binaryName, string $logPath, string $dirPrefix): string
    {
        return $this->pmssMakeExecutableStub(
            $binaryName,
            "#!/bin/sh\nprintf '%s\\n' \"\$*\" >>".escapeshellarg($logPath)."\nexit 0\n",
            $dirPrefix
        );
    }

    /** Assign a temporary directory to a test property and track it for automatic teardown cleanup. */
    protected function pmssAssignTempDirProperty(
        string $propertyName,
        string $prefix,
        int $mode = 0755,
        ?string $baseDir = null
    ): void {
        $path = $this->pmssMakeNamedTempDir($prefix, $mode, $baseDir);
        if (!in_array($path, $this->tempPaths, true)) {
            $this->tempPaths[] = $path;
        }
        $property = new \ReflectionProperty($this, $propertyName);
        $property->setAccessible(true);
        $property->setValue($this, $path);
        $this->tempDirProperties[$propertyName] = $propertyName;
    }

    /** Build an environment array with a stub directory prepended to PATH. */
    protected function pmssPathPrefixedEnvironment(string $prefix, array $environment = []): array
    {
        $originalPath = getenv('PATH');
        $environment['PATH'] = $prefix.(($originalPath !== false && $originalPath !== '') ? ':'.$originalPath : '');
        return $environment;
    }

    /** Return the current process owner name when POSIX account lookups are available. */
    protected function pmssCurrentOwner(): string
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
            return '';
        }
        $ownerInfo = @posix_getpwuid(posix_geteuid());
        return is_array($ownerInfo) && isset($ownerInfo['name']) ? (string) $ownerInfo['name'] : '';
    }

    /** Remove a temporary directory tree created during tests. */
    protected function pmssRemoveTree(string $path): void
    {
        \pmssTestingRemoveTree($path);
    }

    /** Remove tracked temporary paths created by the test harness. */
    private function pmssCleanupTempPaths(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            $this->cleanup($path);
        }

        foreach ($this->tempDirProperties as $propertyName) {
            $property = new \ReflectionProperty($this, $propertyName);
            $property->setAccessible(true);
            $property->setValue($this, '');
        }

        $this->tempPaths = [];
        $this->tempDirProperties = [];
    }

    /** Restore a previous environment variable value captured with getenv(). */
    protected function pmssRestoreEnv(string $key, $value, bool $unsetEmptyString = false): void
    {
        if ($value === false || $value === null || ($unsetEmptyString && $value === '')) {
            putenv($key);
            return;
        }

        putenv($key.'='.$value);
    }

    /** Capture current values for a list of environment variables. */
    protected function pmssCaptureEnv(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = getenv($key);
        }

        return $values;
    }

    /** Restore a map of environment variables captured with pmssCaptureEnv(). */
    protected function pmssRestoreEnvMap(array $values, bool $unsetEmptyString = false): void
    {
        foreach ($values as $key => $value) {
            $this->pmssRestoreEnv($key, $value, $unsetEmptyString);
        }
    }

    /**
     * Apply temporary environment overrides and restore the requested env keys afterwards.
     *
     * @param array<string, string|null> $values
     * @param array<int, string> $trackedKeys
     */
    protected function pmssWithTrackedEnv(
        array $values,
        callable $callback,
        array $trackedKeys = [],
        bool $unsetEmptyString = false
    ): void {
        $previous = $this->pmssCaptureEnv(array_values(array_unique(array_merge(array_keys($values), $trackedKeys))));
        $this->pmssRestoreEnvMap($values, $unsetEmptyString);

        try {
            $callback();
        } finally {
            $this->pmssRestoreEnvMap($previous, $unsetEmptyString);
        }
    }

    /**
     * Apply temporary environment variable overrides for the duration of a callback.
     *
     * @param array<string, string|null> $values
     */
    protected function pmssWithEnv(array $values, callable $callback): void
    {
        $this->pmssWithTrackedEnv($values, $callback);
    }

    /** Apply temporary env overrides while prepending a stub directory to PATH. */
    protected function pmssWithPathPrefixedEnv(string $prefix, array $values, callable $callback): void
    {
        $this->pmssWithEnv($this->pmssPathPrefixedEnvironment($prefix, $values), $callback);
    }

    /**
     * Apply environment overrides for the current test and restore them automatically afterwards.
     *
     * @param array<string, string|null> $values
     */
    protected function pmssTrackEnvOverrides(array $values, bool $unsetEmptyString = false): void
    {
        $this->trackedEnvOverrides[] = [
            'values' => $this->pmssCaptureEnv(array_keys($values)),
            'unsetEmptyString' => $unsetEmptyString,
        ];

        $this->pmssRestoreEnvMap($values);
    }

    /** Track environment keys for automatic restoration without changing their current values. */
    protected function pmssTrackEnvKeys(array $keys, bool $unsetEmptyString = false): void
    {
        $this->trackedEnvOverrides[] = [
            'values' => $this->pmssCaptureEnv($keys),
            'unsetEmptyString' => $unsetEmptyString,
        ];
    }

    /** Restore all environment overrides registered for the current test. */
    private function pmssCleanupTrackedEnvOverrides(): void
    {
        foreach (array_reverse($this->trackedEnvOverrides) as $trackedOverride) {
            $this->pmssRestoreEnvMap($trackedOverride['values'], $trackedOverride['unsetEmptyString']);
        }

        $this->trackedEnvOverrides = [];
    }

    /** Temporarily prepend a directory to PATH for a callback. */
    protected function pmssWithPathPrefix(string $prefix, callable $callback): void
    {
        $this->pmssWithPathPrefixedEnv($prefix, [], $callback);
    }

    /**
     * Stage a temporary os-release fixture for the duration of a callback.
     *
     * @param array<string, string> $fields
     */
    protected function pmssWithOsRelease(array $fields, callable $callback, bool $maskLsbRelease = false): void
    {
        $file = $this->pmssWriteTempFile('osr', $this->pmssRenderOsRelease($fields));
        \pmssResetOsReleaseCache();

        $env = ['PMSS_OS_RELEASE_PATH' => $file];
        if ($maskLsbRelease) {
            $env['PATH'] = sys_get_temp_dir();
        }

        try {
            $this->pmssWithEnv($env, $callback);
        } finally {
            \pmssResetOsReleaseCache();
        }
    }

    /** Assert distro detection output from a staged os-release fixture. */
    protected function pmssAssertDetectedDistro(array $fields, string $expectedName, int $expectedVersion, string $expectedCodename, bool $maskLsbRelease = false): void
    {
        $this->pmssWithOsRelease($fields, function () use ($expectedCodename, $expectedName, $expectedVersion): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals($expectedName, $detected['name']);
            $this->assertEquals($expectedVersion, $detected['version']);
            $this->assertEquals($expectedCodename, $detected['codename']);
        }, $maskLsbRelease);
    }

    /**
     * Render an os-release fixture body from key/value pairs.
     *
     * @param array<string, string> $fields
     */
    protected function pmssRenderOsRelease(array $fields): string
    {
        $lines = [];
        foreach ($fields as $key => $value) {
            if ($value === '') {
                $lines[] = $key.'=';
                continue;
            }

            $lines[] = $key.'="'.str_replace('"', '\"', $value).'"';
        }

        return implode("\n", $lines)."\n";
    }

    /** Run a shell command with optional environment overrides and return combined output. */
    protected function pmssRunShellCommand(string $command, array $environment = [], string $stderrRedirect = '2>&1'): string
    {
        $command = $this->pmssBuildCommandEnvironmentPrefix($environment).$command;
        return (string) @shell_exec($stderrRedirect !== '' ? $command.' '.$stderrRedirect : $command);
    }

    /** @return array{rc:int, output:string, lines:array<int, string>} */
    protected function pmssExecShellCommand(string $command, array $environment = [], string $stderrRedirect = '2>&1'): array
    {
        $output = [];
        $rc = 0;
        @exec(
            $this->pmssBuildCommandEnvironmentPrefix($environment).$command.($stderrRedirect !== '' ? ' '.$stderrRedirect : ''),
            $output,
            $rc
        );
        return [
            'rc' => $rc,
            'output' => implode("\n", $output),
            'lines' => $output,
        ];
    }

    /** Extract one shell function body from a source fixture for focused harness tests. */
    protected function pmssExtractShellFunction(string $source, string $name): string
    {
        $pattern = '/^'.preg_quote($name, '/').'\(\) \{(?:\n(?:.*\n)*?^\}|[^\n]*\})/m';
        $matched = preg_match($pattern, $source, $matches);

        $this->assertSame(1, $matched, 'Failed to extract shell function '.$name);
        return $matches[0];
    }

    /** Extract multiple shell functions from the same source fixture in caller order. */
    protected function pmssExtractShellFunctions(string $source, array $names): string
    {
        $functions = [];
        foreach ($names as $name) {
            $functions[] = $this->pmssExtractShellFunction($source, (string) $name);
        }

        return implode("\n\n", $functions);
    }

    /** Write and execute a temporary shell harness, returning combined output. */
    protected function pmssRunShellHarness(string $script, string $dirPrefix = 'pmss-shell-harness-'): string
    {
        $harness = $this->pmssMakeTempDir($dirPrefix).'/run.sh';
        $this->pmssWriteExecutableFile($harness, $script);

        $result = $this->pmssExecShellCommand(escapeshellarg($harness));
        $this->assertSame(0, $result['rc'], $result['output']);
        return $result['output'];
    }

    protected function pmssRunInlinePhp(string $script, array $environment = [], string $stderrRedirect = '2>/dev/null'): string
    {
        return $this->pmssRunShellCommand(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script), $environment, $stderrRedirect);
    }

    protected function pmssRunInlinePhpJson(string $script, array $environment = [], string $stderrRedirect = '2>/dev/null'): array
    {
        return $this->pmssDecodeJsonArray(
            $this->pmssRunInlinePhp($script, $environment, $stderrRedirect)
        );
    }

    /** Run inline PHP after loading a required file fixture. */
    protected function pmssRunInlinePhpRequire(string $requiredPath, string $script, array $environment = [], string $stderrRedirect = '2>/dev/null', bool $requireOnce = false): string { return $this->pmssRunInlinePhp(($requireOnce ? 'require_once ' : 'require ').var_export($requiredPath, true).'; '.$script, $environment, $stderrRedirect); }

    /** Run required inline PHP and decode its JSON array output. */
    protected function pmssRunInlinePhpRequireJson(string $requiredPath, string $script, array $environment = [], string $stderrRedirect = '2>/dev/null', bool $requireOnce = false): array { return $this->pmssDecodeJsonArray($this->pmssRunInlinePhpRequire($requiredPath, $script, $environment, $stderrRedirect, $requireOnce)); }

    /** Run inline PHP after loading a repository-relative fixture path. */
    protected function pmssRunRepoInlinePhpRequire(string $relativePath, string $script, array $environment = [], string $stderrRedirect = '2>/dev/null', bool $requireOnce = false): string { return $this->pmssRunInlinePhpRequire($this->pmssRepoPath($relativePath), $script, $environment, $stderrRedirect, $requireOnce); }

    /** Run repository-relative inline PHP and decode its JSON array output. */
    protected function pmssRunRepoInlinePhpRequireJson(string $relativePath, string $script, array $environment = [], string $stderrRedirect = '2>/dev/null', bool $requireOnce = false): array { return $this->pmssRunInlinePhpRequireJson($this->pmssRepoPath($relativePath), $script, $environment, $stderrRedirect, $requireOnce); }

    /** Return inline PHP shims shared by traffic-limit style CLI subprocess tests. */
    protected function pmssInlinePhpTrafficCliShims(string $homeGlobalName, string $logGlobalName, ?string $runtimeGlobalName = null): string
    {
        foreach ([$homeGlobalName, $logGlobalName, $runtimeGlobalName] as $globalName) {
            if ($globalName !== null) $this->assertMatches('/^[A-Z0-9_]+$/', $globalName, 'Invalid inline PHP global key: '.$globalName);
        }

        $lines = [
            'function pmssRequireCli(string $message, $exitCode = null): bool { return true; }',
            'function pmssTrafficLimitResolveCliUserHome($rawUserName, string $usage, ?int &$exitCode = null): ?array {',
            '    $exitCode = null; $userName = strtolower(trim((string) $rawUserName));',
            '    if ($userName === \'\') { fwrite(STDERR, "Error: missing username.\n".$usage."\n"); $exitCode = 2; return null; }',
            "    return ['user' => \$userName, 'home' => \$GLOBALS[".var_export($homeGlobalName, true).']];',
            '}',
        ];
        if ($runtimeGlobalName !== null) {
            $lines[] = sprintf(
                'function pmssTrafficLimitCliTargetModes(string $userName, string $homeDir): array { return [$GLOBALS[%s].\'/\'.$userName => 0600, $homeDir.\'/.trafficLimit\' => 0664]; }',
                var_export($runtimeGlobalName, true)
            );
        }
        $lines[] = $this->pmssInlinePhpUserLogShim($logGlobalName);
        return implode("\n", $lines)."\n";
    }

    /** Return an inline PHP user-log shim that appends log calls to a global array. */
    protected function pmssInlinePhpUserLogShim(string $logGlobalName): string
    {
        $this->assertMatches('/^[A-Z0-9_]+$/', $logGlobalName, 'Invalid inline PHP global key: '.$logGlobalName);
        return 'function pmssUserLog(string $userName, string $message): void { $GLOBALS['.var_export($logGlobalName, true).'][] = [$userName, $message]; }';
    }

    /** Decode JSON output that is expected to produce an array payload. */
    protected function pmssDecodeJsonArray(string $output, string $message = ''): array
    {
        $decoded = json_decode($output, true);
        $this->assertTrue(is_array($decoded), $message !== '' ? $message : 'Expected JSON output, got: '.trim($output));
        return $decoded;
    }

    /** Build a PHP CLI command with safely escaped arguments. */
    private function pmssBuildPhpCommand(string $scriptPath, array $arguments = []): string
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptPath);
        foreach ($arguments as $argument) {
            $command .= ' '.escapeshellarg((string) $argument);
        }

        return $command;
    }

    protected function pmssRunPhpScript(string $scriptPath, array $arguments = [], array $environment = [], string $stderrRedirect = '2>&1'): string
    {
        return $this->pmssRunShellCommand(
            $this->pmssBuildPhpCommand($scriptPath, $arguments),
            $environment,
            $stderrRedirect
        );
    }

    /** Run a repository PHP entry point by relative path. */
    protected function pmssRunRepoPhpScript(string $relativePath, array $arguments = [], array $environment = [], string $stderrRedirect = '2>&1'): string
    {
        return $this->pmssRunPhpScript($this->pmssRepoPath($relativePath), $arguments, $environment, $stderrRedirect);
    }

    /** @return array{rc:int, output:string, lines:array<int, string>} */
    protected function pmssRunRepoPhpScriptCommand(string $relativePath, array $arguments = [], array $environment = [], string $stderrRedirect = '2>&1'): array
    {
        return $this->pmssExecShellCommand(
            $this->pmssBuildPhpCommand($this->pmssRepoPath($relativePath), $arguments),
            $environment,
            $stderrRedirect
        );
    }

    /** Run a repository PHP entry point and assert successful output fragments. */
    protected function pmssAssertRepoPhpScriptOutputContains(string $relativePath, array $arguments, array $needles, array $environment = [], string $stderrRedirect = '2>&1'): array
    {
        $result = $this->pmssRunRepoPhpScriptCommand($relativePath, $arguments, $environment, $stderrRedirect);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsAllStrings($needles, $result['output']);

        return $result;
    }

    /** @return array{result:array{rc:int, output:string, lines:array<int, string>},stderrPath:string} */
    protected function pmssExecShellCommandWithTempStderr(string $command, array $environment = [], string $stderrPrefix = 'pmss-stderr-'): array
    {
        $stderrPath = $this->pmssMakeTempPath($stderrPrefix);
        return ['result' => $this->pmssExecShellCommand($command, $environment, '2>'.escapeshellarg($stderrPath)), 'stderrPath' => $stderrPath];
    }

    /** @return array{result:array{rc:int, output:string, lines:array<int, string>},stderrPath:string} */
    protected function pmssRunRepoPhpScriptCommandWithTempStderr(string $relativePath, array $arguments = [], array $environment = [], string $stderrPrefix = 'pmss-stderr-'): array
    {
        return $this->pmssExecShellCommandWithTempStderr($this->pmssBuildPhpCommand($this->pmssRepoPath($relativePath), $arguments), $environment, $stderrPrefix);
    }

    protected function pmssAssertCommandFailsToStderr(array $result, string $stderrPath, string $expectedMessage): void
    {
        $this->assertEquals(1, $result['rc']);
        $this->assertEquals('', $result['output']);
        $this->assertEquals($expectedMessage, (string) @file_get_contents($stderrPath));
    }

    protected function pmssAssertCommandSucceedsWithoutStderr(array $result, string $stderrPath): void
    {
        $this->assertEquals(0, $result['rc']);
        $this->assertEquals('', (string) @file_get_contents($stderrPath));
    }

    protected function pmssAssertEnvResolvedPath(string $envKey, ?string $envValue, string $expected, callable $resolver): void
    {
        $this->pmssWithEnv([$envKey => $envValue], function () use ($expected, $resolver): void {
            $this->assertEquals($expected, $resolver());
        });
    }

    /** Execute `storageBenchmark.php --show-last` for the provided JSONL log. */
    protected function pmssRunStorageBenchmarkShowLast(string $logPath, array $arguments = []): string
    {
        return $this->pmssRunRepoPhpScript(
            'scripts/util/storageBenchmark.php',
            array_merge(['--show-last'], $arguments, ['--json', $logPath])
        );
    }

    /** Load the customer-panel render harness only for tests that need it. */
    protected function pmssLoadCustomerPanelRenderHarness(): void
    {
        require_once dirname(__DIR__, 2).'/testing/customerPanelRenderEnvironment.php';
        require_once dirname(__DIR__, 2).'/testing/customerPanelRenderProcess.php';
    }

    /** Render a customer-panel page in the shared synthetic customer tree. */
    protected function pmssRenderCustomerPanelPage(string $page, array $homeFlags = [], array $expectation = []): string
    {
        $this->pmssLoadCustomerPanelRenderHarness();
        $runRoot = \pmssCustomerPanelRenderTempRoot();
        $homeRoot = $runRoot.'/home';
        $home = $homeRoot.'/renderuser';
        $www = $home.'/www';
        $bootstrap = $runRoot.'/php-cli-bootstrap.php';

        try {
            $setup = \pmssCustomerPanelRenderPrepare($this->pmssRepoPath('etc/skel/www'), $home, $www, $bootstrap);
            $this->assertTrue($setup['ok'], $setup['error']);
            foreach ($homeFlags as $flag) {
                @touch($home.'/'.ltrim((string) $flag, '/'));
            }

            $result = \pmssCustomerPanelRenderPage(
                $www,
                $bootstrap,
                $homeRoot,
                $home,
                $page,
                array_merge(['minBytes' => 5000, 'query' => ''], $expectation)
            );
            $this->assertEquals([], $result['errors'], implode('; ', $result['errors']));
            return $result['stdout'];
        } finally {
            \pmssCustomerPanelRenderCleanup($runRoot);
        }
    }

    /** Render a copied user-panel index fixture from its customer www directory. */
    protected function pmssRenderCopiedUserPanelIndex(array $homeFlags = [], array $configDirs = []): string
    {
        $home = $this->pmssMakeUserWebHome('panel-home-');
        $sourceWww = $this->pmssRepoPath('etc/skel/www');
        foreach (['index.php', 'pmssTabs.js', 'jquery.tabs.css', 'welcome.php'] as $file) {
            if (is_file($sourceWww.'/'.$file)) {
                $this->assertTrue(@copy($sourceWww.'/'.$file, $home.'/www/'.$file), 'Expected copied panel fixture: '.$file);
            }
        }
        foreach ($configDirs as $dir) {
            $this->pmssEnsureDir($home.'/'.ltrim((string) $dir, '/'));
        }
        foreach ($homeFlags as $flag) {
            @touch($home.'/'.ltrim((string) $flag, '/'));
        }

        return $this->pmssRunShellCommand(
            'cd '.escapeshellarg($home.'/www').' && '.escapeshellarg(PHP_BINARY).' index.php',
            [],
            '2>/dev/null'
        );
    }

    /** Render the repository index.php while using the supplied customer home as cwd. */
    protected function pmssRenderUserPanelIndexFromHome(string $home, array $environment = [], bool $displayErrors = false): string
    {
        $script = ($displayErrors ? 'error_reporting(E_ALL); ini_set("display_errors", "1"); ' : '')
            .'chdir('.var_export($home.'/www', true).'); '
            .'ob_start(); include '.var_export($this->pmssRepoPath('etc/skel/www/index.php'), true).'; echo ob_get_clean();';

        return $this->pmssRunInlinePhp($script, $environment, '2>&1');
    }

    private function pmssBuildCommandEnvironmentPrefix(array $environment): string
    {
        $prefix = '';
        foreach ($environment as $key => $value) {
            $this->assertMatches('/^[A-Z0-9_]+$/', (string) $key, 'Invalid inline PHP env key: '.$key);
            $prefix .= $key.'='.escapeshellarg((string) $value).' ';
        }
        return $prefix;
    }

    /** Create a symlink or skip the current test when the platform blocks it. */
    protected function pmssCreateSymlinkOrSkip(string $target, string $link): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation failed');
        }
    }

    /** Create a symlinked file fixture and return [target, link]. */
    protected function pmssCreateSymlinkedFileOrSkip(string $target, string $link, string $content = '', int $dirMode = 0755): array
    {
        $this->pmssWriteFile($target, $content, $dirMode);
        $this->pmssEnsureDir(dirname($link), $dirMode);
        $this->pmssCreateSymlinkOrSkip($target, $link);
        return [$target, $link];
    }

    /** Create a symlinked directory fixture and return [target, link]. */
    protected function pmssCreateSymlinkedDirectoryOrSkip(string $target, string $link, int $mode = 0755): array
    {
        $this->pmssEnsureDir($target, $mode);
        $this->pmssEnsureDir(dirname($link), $mode);
        $this->pmssCreateSymlinkOrSkip($target, $link);
        return [$target, $link];
    }

    /** Resolve the repository root from the development test tree. */
    protected function pmssRepoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /** Resolve a repository-relative path from the development test tree. */
    protected function pmssRepoPath(string $relativePath): string
    {
        return $this->pmssRepoRoot().'/'.ltrim($relativePath, '/');
    }

    /** Read a repository file and fail the test when it is unavailable. */
    protected function pmssReadRepoFile(string $relativePath): string
    {
        $path = $this->pmssRepoPath($relativePath);
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    /** Assert update app source requirements while sharing the repo-file reader. */
    protected function pmssAssertUpdateAppFileContainsAndOmitsStrings(string $relativePath, array $required = [], array $forbidden = []): string
    {
        return $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/'.ltrim($relativePath, '/'), $required, $forbidden);
    }

    /** Read a repository file and assert that it contains a substring. */
    protected function pmssAssertRepoFileContainsString(string $relativePath, string $needle, string $message = ''): void
    {
        $this->assertStringContainsString($needle, $this->pmssReadRepoFile($relativePath), $message);
    }

    /** Read a repository file and assert that it omits a substring. */
    protected function pmssAssertRepoFileNotContainsString(string $relativePath, string $needle, string $message = ''): void
    {
        $this->pmssAssertStringNotContainsString($needle, $this->pmssReadRepoFile($relativePath), $message);
    }

    /** Read a repository file and assert that it contains multiple substrings. */
    protected function pmssAssertRepoFileContainsAllStrings(string $relativePath, array $needles, string $messagePrefix = ''): void
    {
        $this->assertStringContainsAllStrings($needles, $this->pmssReadRepoFile($relativePath), $messagePrefix);
    }

    /** Read a repository file and assert ordered substrings. */
    protected function pmssAssertRepoFileContainsOrderedStrings(string $relativePath, array $needles, string $missingPrefix = '', string $orderPrefix = ''): void
    {
        $this->assertOrderedStrings($needles, $this->pmssReadRepoFile($relativePath), $missingPrefix, $orderPrefix);
    }

    /** Read a repository file and assert that it matches a regex. */
    protected function pmssAssertRepoFileMatches(string $relativePath, string $pattern, string $message = ''): void
    {
        $this->assertMatches($pattern, $this->pmssReadRepoFile($relativePath), $message);
    }

    /** Read a repository file and assert that it omits multiple substrings. */
    protected function pmssAssertRepoFileNotContainsStrings(string $relativePath, array $needles, string $messagePrefix = ''): void
    {
        foreach ($needles as $needle) {
            $this->pmssAssertRepoFileNotContainsString($relativePath, $needle, $messagePrefix !== '' ? $messagePrefix.$needle : '');
        }
    }

    /** Read a repository file and assert that it does not declare a named function. */
    protected function pmssAssertRepoFileNotContainsFunction(string $relativePath, string $symbol, string $message): void
    {
        $this->pmssAssertStringNotContainsString('function '.$symbol.'(', $this->pmssReadRepoFile($relativePath), $message);
    }

    /** Read a repository file and assert required and forbidden substrings. */
    protected function pmssAssertRepoFileContainsAndOmitsStrings(string $relativePath, array $required = [], array $forbidden = []): string
    {
        $source = $this->pmssReadRepoFile($relativePath);
        $this->assertStringContainsAndOmitsStrings($required, $forbidden, $source);

        return $source;
    }

    /** Assert repeated source-contract tables without per-test boilerplate. */
    protected function pmssAssertRepoFileContractCases(array $cases, string $pathPrefix = ''): void
    {
        foreach ($cases as $relativePath => $case) {
            $source = $this->pmssAssertRepoFileContainsAndOmitsStrings(
                $pathPrefix.ltrim((string) $relativePath, '/'),
                $case['required'] ?? [],
                $case['forbidden'] ?? []
            );
            foreach ($case['matches'] ?? [] as $pattern) {
                $this->assertMatches((string) $pattern, $source);
            }
        }
    }

    /** Read a repository file and assert a fixed substring count. */
    protected function pmssAssertRepoFileSubstringCount(string $relativePath, string $needle, int $expectedCount, string $message = ''): void
    {
        $this->assertEquals($expectedCount, substr_count($this->pmssReadRepoFile($relativePath), $needle), $message);
    }

    /** Read a repository file and assert a minimum substring count. */
    protected function pmssAssertRepoFileSubstringCountAtLeast(string $relativePath, string $needle, int $minimumCount, string $message = ''): void
    {
        $this->assertTrue(substr_count($this->pmssReadRepoFile($relativePath), $needle) >= $minimumCount, $message);
    }

    /** Find the recorded command for a JSON step log entry matching a description substring. */
    protected function pmssFindJsonStepCommand(string $jsonLog, string $needle): ?string
    {
        if (!function_exists('pmssJsonLineFileRead')) {
            require_once __DIR__.'/../../log.php';
        }
        foreach (pmssJsonLineFileRead($jsonLog) as $decoded) {
            if (($decoded['event'] ?? '') !== 'step') {
                continue;
            }

            $entry = $decoded['data'] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $description = (string) ($entry['description'] ?? '');
            if (strpos($description, $needle) === false) {
                continue;
            }

            return isset($entry['command']) ? (string) $entry['command'] : null;
        }

        return null;
    }

}
}
