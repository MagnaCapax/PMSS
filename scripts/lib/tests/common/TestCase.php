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

require_once __DIR__.'/FilesystemCleanupTrait.php';

class SkipTest extends \Exception {}

abstract class TestCase
{
    use FilesystemCleanupTrait {
        pmssMakeNamedTempDir as private pmssTraitMakeNamedTempDir;
        cleanup as private pmssTraitCleanup;
    }

    /**
     * @var array<int, array{0:bool|string,1:string,2:?string}>
     */
    private $results = [];

    /**
     * @var array<int, string>
     */
    private $tempPaths = [];

    /**
     * @var array<string, string>
     */
    private $tempDirProperties = [];

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

    protected function pmssAssertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (strpos($haystack, $needle) !== false) {
            throw new \AssertionError($message !== '' ? $message : sprintf('Expected string to not contain %s, but it did', var_export($needle, true)));
        }
    }

    protected function assertStringContainsAllStrings(array $needles, string $haystack, string $messagePrefix = ''): void
    {
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $haystack, $messagePrefix !== '' ? $messagePrefix.$needle : '');
        }
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

    /** Return parsed mount options for one fstab mount point. */
    protected function pmssFstabOptionsForMount(string $fstab, string $mountPoint): array
    {
        $lines = file($fstab, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (!is_array($columns) || count($columns) < 4 || $columns[1] !== $mountPoint) continue;
            return array_values(array_filter(explode(',', $columns[3]), 'strlen'));
        }
        return [];
    }

    protected function isSandbox(): bool
    {
        if (getenv('PMSS_SANDBOX') === '1') return true;
        // Not root or no systemd bus → assume sandbox/CI
        $notRoot = function_exists('posix_geteuid') ? (posix_geteuid() !== 0) : true;
        $noSystemd = !is_dir('/run/systemd/system');
        return $notRoot || $noSystemd;
    }

    /** Create a unique temporary directory for hermetic tests. */
    protected function pmssMakeTempDir(string $prefix, int $mode = 0755): string
    {
        $base = getenv('PMSS_TEST_TEMP_ROOT');
        if (!is_string($base) || $base === '') {
            $base = sys_get_temp_dir();
        }

        $path = rtrim($base, '/').'/'.$prefix.bin2hex(random_bytes(6));
        @mkdir($path, $mode, true);
        $this->tempPaths[] = $path;
        return $path;
    }

    /** Create and track a unique temporary file for hermetic tests. */
    protected function pmssMakeTempFile(string $prefix = 'pmss'): string
    {
        $base = getenv('PMSS_TEST_TEMP_ROOT');
        if (!is_string($base) || $base === '') {
            $base = sys_get_temp_dir();
        }

        $path = @tempnam($base, $prefix);
        if ($path === false) {
            $path = rtrim($base, '/').'/'.$prefix.bin2hex(random_bytes(6));
            touch($path);
        }

        $this->tempPaths[] = $path;
        return $path;
    }

    /** Reserve and track a unique temporary filesystem path for hermetic tests. */
    protected function pmssMakeTempPath(string $prefix, string $suffix = ''): string
    {
        $base = getenv('PMSS_TEST_TEMP_ROOT');
        if (!is_string($base) || $base === '') {
            $base = sys_get_temp_dir();
        }

        $path = rtrim($base, '/').'/'.$prefix.bin2hex(random_bytes(6)).$suffix;
        $this->tempPaths[] = $path;
        return $path;
    }

    /** Create a tracked temporary PHP fixture path keyed by a readable suffix. */
    protected function pmssMakeTempPhpPath(string $prefix, string $suffix): string
    {
        return $this->pmssMakeTempPath($prefix.$suffix.'-', '.php');
    }

    /** Create a temporary home fixture and seed one relative directory tree. */
    protected function pmssMakeUserHomeTree(string $prefix, string $relativeDir = ''): string
    {
        $home = $this->pmssMakeTempDir($prefix);
        if ($relativeDir !== '') {
            @mkdir($home.'/'.ltrim($relativeDir, '/'), 0755, true);
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

    /** Create a fixture directory tree and assert it exists afterwards. */
    protected function pmssEnsureFixtureDirectory(string $path, int $mode = 0755): void { $this->assertTrue(@mkdir($path, $mode, true) || is_dir($path), 'Expected fixture directory to exist: '.$path); }

    /** Write a PHP array fixture that can be loaded with include/require. */
    protected function pmssWritePhpArrayFixture(array $value, string $prefix = 'pmss-fixture-'): string
    {
        $path = $this->pmssMakeTempPath($prefix, '.php');
        file_put_contents($path, "<?php return ".var_export($value, true).";\n");
        return $path;
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
    /** Persist serialized fixture data while ensuring the parent path exists. */
    protected function pmssWriteSerializedFixture(string $path, $value): void
    {
        @mkdir(dirname($path), 0755, true);
        @file_put_contents($path, serialize($value));
    }

    /** Build the serialized resource stats shape used by report-oriented tests. */
    protected function pmssBuildResourceStatsPayloadFromValues(array $values): array
    {
        return [
            'io_read' => ['raw' => $values['io_read']],
            'io_write' => ['raw' => $values['io_write']],
            'io_read_ops' => ['raw' => $values['io_read_ops'] ?? []],
            'io_write_ops' => ['raw' => $values['io_write_ops'] ?? []],
            'cpu' => ['raw' => $values['cpu']],
            'memory' => [
                'current' => $values['memory_current'],
                'raw' => ['month' => $values['memory_avg_month']],
            ],
            'ram_hours' => ['raw' => $values['ram_hours']],
            'tasks' => ['current' => $values['tasks_current']],
        ];
    }

    /** Keep shared source-based listUsers consumer assertions in one place. */
    protected function pmssListUsersConsumerMap(): array
    {
        return [
            "userFilesystem::listManagedUsersWithAdditionalUsers(['www-data'])" => [
                'scripts/cron/resourceLog.php',
                'scripts/cron/resourceSnapshot.php',
                'scripts/cron/trafficLog.php',
                'scripts/cron/trafficIngressLog.php',
                'scripts/util/makeMonitoringRules.php',
            ],
            "pmssListManagedUsers('/scripts/listUsers.php')" => [
                'scripts/cron/trafficLimits.php',
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
                'scripts/lib/resources/show.php',
                'scripts/showTraffic.php',
                'scripts/userTorrents.php',
            ],
        ];
    }

    /** Append fixture lines, encoding arrays as JSON and preserving raw strings. */
    protected function pmssAppendFixtureLines(string $path, array $lines): void
    {
        foreach ($lines as $line) {
            $payload = is_array($line) ? json_encode($line) : (string) $line;
            file_put_contents($path, $payload."\n", FILE_APPEND);
        }
    }

    /** Create a tracked readable file under a fresh temporary directory. */
    protected function pmssMakeReadableTempPath(string $dirPrefix, string $filePrefix = 'pmss'): string
    {
        $path = tempnam($this->pmssMakeTempDir($dirPrefix), $filePrefix);
        $this->assertTrue($path !== false, 'Expected a temporary readable path');
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

    /** Read the canonical systemd user-slice drop-in emitted by the fixture. */
    protected function pmssSystemdSliceDropinRead(string $dropDir, string $name = '15-pmss.conf'): string
    {
        return (string) file_get_contents(rtrim($dropDir, '/').'/'.$name);
    }

    /** @param array{dropDir:string,env:array<string,string>} $fixture */
    protected function pmssSystemdSliceDropinRender(array $fixture, string $name = '15-pmss.conf'): string
    {
        $this->pmssSystemdSliceEnsure($fixture);
        return $this->pmssSystemdSliceDropinRead($fixture['dropDir'], $name);
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

    /** Keep named temp-dir creation available to child test cases via an explicit wrapper. */
    protected function pmssMakeNamedTempDir(string $prefix, int $mode = 0755, ?string $baseDir = null): string
    {
        return $this->pmssTraitMakeNamedTempDir($prefix, $mode, $baseDir);
    }

    /** Keep recursive cleanup available to child test cases via an explicit wrapper. */
    protected function cleanup(string $path): void
    {
        $this->pmssTraitCleanup($path);
    }

    /** Create an executable test stub in a fresh PATH directory. */
    protected function pmssMakeExecutableStub(string $binaryName, string $script, string $dirPrefix): string
    {
        $binDir = $this->pmssMakeTempDir($dirPrefix);
        file_put_contents($binDir.'/'.$binaryName, $script);
        @chmod($binDir.'/'.$binaryName, 0755);
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
        $path = $this->pmssTraitMakeNamedTempDir($prefix, $mode, $baseDir);
        if (!in_array($path, $this->tempPaths, true)) {
            $this->tempPaths[] = $path;
        }
        $property = new \ReflectionProperty($this, $propertyName);
        $property->setAccessible(true);
        $property->setValue($this, $path);
        $this->tempDirProperties[$propertyName] = $propertyName;
    }

    /** Remove a temporary directory stored on a test property, including private child properties. */
    protected function pmssCleanupTempDirProperty(string $propertyName): void
    {
        $property = new \ReflectionProperty($this, $propertyName);
        $property->setAccessible(true);
        $path = (string) $property->getValue($this);
        if ($path === '') {
            return;
        }

        $this->pmssTraitCleanup($path);
        $property->setValue($this, '');
    }

    /** Build an environment array with a stub directory prepended to PATH. */
    protected function pmssPathPrefixedEnvironment(string $prefix, array $environment = []): array
    {
        $environment['PATH'] = $prefix.':'.(string) getenv('PATH');
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
        if (!file_exists($path)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }
        }

        @rmdir($path);
    }

    /** Remove tracked temporary paths created by the test harness. */
    private function pmssCleanupTempPaths(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }

            if (is_file($path) || is_link($path)) {
                @unlink($path);
                continue;
            }

            $this->pmssRemoveTree($path);
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
     * Apply temporary environment variable overrides for the duration of a callback.
     *
     * @param array<string, string|null> $values
     */
    protected function pmssWithEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            $this->pmssRestoreEnv($key, $value);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                $this->pmssRestoreEnv($key, $value);
            }
        }
    }

    /** Temporarily prepend a directory to PATH for a callback. */
    protected function pmssWithPathPrefix(string $prefix, callable $callback): void
    {
        $originalPath = getenv('PATH');
        $path = $prefix.(($originalPath !== false && $originalPath !== '') ? ':'.$originalPath : '');

        $this->pmssWithEnv(['PATH' => $path], $callback);
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

    protected function pmssRunInlinePhp(string $script, array $environment = [], string $stderrRedirect = '2>/dev/null'): string
    {
        return $this->pmssRunShellCommand(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script), $environment, $stderrRedirect);
    }

    protected function pmssRunInlinePhpJson(string $script, array $environment = [], string $stderrRedirect = '2>/dev/null'): array
    {
        $output = $this->pmssRunInlinePhp($script, $environment, $stderrRedirect);
        $decoded = json_decode($output, true);
        $this->assertTrue(is_array($decoded), 'Expected JSON output, got: '.trim($output));
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

    /** Execute `storageBenchmark.php --show-last` for the provided JSONL log. */
    protected function pmssRunStorageBenchmarkShowLast(string $logPath, array $arguments = []): string
    {
        return $this->pmssRunRepoPhpScript(
            'scripts/util/storageBenchmark.php',
            array_merge(['--show-last'], $arguments, ['--json', $logPath])
        );
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

    /** Skip the current test when symlinks are unavailable. */
    protected function pmssRequireSymlinkSupport(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }
    }

    /** Create a symlink or skip the current test when the platform blocks it. */
    protected function pmssCreateSymlinkOrSkip(string $target, string $link): void
    {
        $this->pmssRequireSymlinkSupport();
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation failed');
        }
    }

    /** Resolve a repository-relative path from the development test tree. */
    protected function pmssRepoPath(string $relativePath): string
    {
        return dirname(__DIR__, 4).'/'.ltrim($relativePath, '/');
    }

    /** Read a repository file and fail the test when it is unavailable. */
    protected function pmssReadRepoFile(string $relativePath): string
    {
        $path = $this->pmssRepoPath($relativePath);
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
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

    /** Read a repository file and assert that it matches a regex. */
    protected function pmssAssertRepoFileMatches(string $relativePath, string $pattern, string $message = ''): void
    {
        $this->assertMatches($pattern, $this->pmssReadRepoFile($relativePath), $message);
    }

    /** Read a repository file and assert ordered substrings. */
    protected function pmssAssertRepoFileContainsOrderedStrings(string $relativePath, array $needles, string $missingPrefix = '', string $orderPrefix = ''): void
    {
        $this->assertOrderedStrings($needles, $this->pmssReadRepoFile($relativePath), $missingPrefix, $orderPrefix);
    }

    /** Read a repository file and assert that it omits multiple substrings. */
    protected function pmssAssertRepoFileNotContainsStrings(string $relativePath, array $needles, string $messagePrefix = ''): void
    {
        foreach ($needles as $needle) {
            $this->pmssAssertRepoFileNotContainsString($relativePath, $needle, $messagePrefix !== '' ? $messagePrefix.$needle : '');
        }
    }

    /** Read a repository file and assert a fixed substring count. */
    protected function pmssAssertRepoFileSubstringCount(string $relativePath, string $needle, int $expectedCount, string $message = ''): void
    {
        $this->assertEquals($expectedCount, substr_count($this->pmssReadRepoFile($relativePath), $needle), $message);
    }

    /** Find the recorded command for a JSON step log entry matching a description substring. */
    protected function pmssFindJsonStepCommand(string $jsonLog, string $needle): ?string
    {
        $lines = @file($jsonLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return null;
        }

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded) || ($decoded['event'] ?? '') !== 'step') {
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

    /** Check whether a buffered test log contains a substring. */
    protected function pmssLogBufferContains(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

}
}
