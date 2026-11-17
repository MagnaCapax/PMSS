<?php
/** Characterize APT planning, publication, and default cache-clean execution. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AptRepositoryCharacterizationTest extends TestCase
{
    public function testPlanRetainsTemplateOrderHashAndUnknownVersionShortCircuit(): void
    {
        $result = $this->fixture(['action' => 'plan', 'files' => ['/sources.list' => 'current',
            '/config/template.sources.bookworm' => "  bookworm-template\n\n"]]);
        $this->assertSame(['mode' => 'update', 'current_hash' => sha1('current'), 'templates' => [
            'jessie' => '', 'buster' => '', 'bullseye' => '', 'bookworm' => "bookworm-template\n", 'trixie' => '',
        ]], $result['result']);
        $reads = array_values(array_filter($result['events'], static function (array $event): bool { return $event[0] === 'read'; }));
        $this->assertSame([['read', '/sources.list'], ['read', '/config/template.sources.jessie'],
            ['read', '/config/template.sources.buster'], ['read', '/config/template.sources.bullseye'],
            ['read', '/config/template.sources.bookworm'], ['read', '/config/template.sources.trixie']], $reads);
        $result = $this->fixture(['action' => 'plan', 'version' => 0]);
        $this->assertSame(['mode' => 'reuse', 'current_hash' => '', 'templates' => []], $result['result']);
        $this->assertSame([['read', '/sources.list'], ['log', 'Repository version unresolved for debian; reusing existing sources']], $result['events']);
    }

    public function testSourceApplyRetainsEarlyReturnsAndDiagnostics(): void
    {
        foreach ([
            ['debian', 0, [], '', 'Skipping repository update for debian: unknown version'],
            ['ubuntu', 22, [], '', 'Ubuntu is not supported yet.'],
            ['other', 12, [], '', 'Unsupported distro: other'],
            ['debian', 99, [], '', 'Unsupported Debian version: 99'],
            ['debian', 12, [], '', 'Bookworm template missing, leaving sources.list untouched'],
            ['debian', 12, ['bookworm' => 'current'], sha1('current'), 'Debian Bookworm repositories already correct'],
        ] as [$distro, $version, $repos, $hash, $message]) {
            $result = $this->fixture(compact('distro', 'version', 'repos', 'hash') + ['action' => 'apply']);
            $this->assertSame([['log', $message]], $result['events']);
            $this->assertSame(null, $result['result']);
        }
    }

    public function testFailedSourceWriteRestoresBeforeAlreadyCorrectDiagnostic(): void
    {
        $result = $this->fixture(['action' => 'apply', 'repos' => ['bookworm' => 'replacement'],
            'files' => ['/sources.list' => 'current'], 'failWrite' => 'replacement']);
        $this->assertSame([
            ['directory', '/'], ['safe', '/sources.list'], ['read', '/sources.list'], ['safe', '/sources.list.pmss-backup'],
            ['write', '/sources.list.pmss-backup', 'current', LOCK_EX], ['log', 'Backup for sources.list written to /sources.list.pmss-backup'],
            ['write', '/sources.list', 'replacement', LOCK_EX], ['log', '[ERROR] Failed to write sources.list for Bookworm, attempting restore'],
            ['write', '/sources.list', 'current', LOCK_EX], ['log', 'Debian Bookworm repositories already correct'],
        ], $result['events']);
        $this->assertSame('current', $result['files']['/sources.list']);
    }

    public function testEolHousekeepingKeepsOverrideCleanAndAppliedOrder(): void
    {
        $result = $this->fixture(['action' => 'apply', 'version' => 8, 'repos' => ['jessie' => 'replacement']]);
        $this->assertSame([
            ['directory', '/etc/apt/apt.conf.d'],
            ['managed', '/etc/apt/apt.conf.d/90ignore-release-date', "Acquire::Check-Valid-Until \"false\";\n", 0644],
            ['exec', 'fixture-env apt-get clean 2>&1'], ['log', 'Applied Debian Jessie repository config'],
        ], array_slice($result['events'], -4));
    }

    public function testDefaultCleanRetainsExitOutputAndThrowableHandling(): void
    {
        foreach ([[0, [], true, null], [100, [' first ', 'second '], false, '[WARN] apt-get clean failed with rc 100 (first '."\n".'second)'],
            [1, [], false, '[WARN] apt-get clean failed with rc 1'], [0, ['ignored'], true, null],
            [0, [], false, '[WARN] apt-get clean runner failed: injected']] as $index => [$rc, $output, $expected, $message]) {
            $result = $this->fixture(['action' => 'clean', 'rc' => $rc, 'output' => $output, 'throw' => $index === 4]);
            $this->assertSame($expected, $result['result']);
            $this->assertSame($message === null ? [['exec', 'fixture-env apt-get clean 2>&1']]
                : [['exec', 'fixture-env apt-get clean 2>&1'], ['log', $message]], $result['events']);
        }
    }

    private function fixture(array $state): array
    {
        $script = <<<'PHP'
namespace AptRepositoryFixture;
use Throwable;
function logMessage($message) { $GLOBALS['events'][] = ['log', $message]; }
function pmssResolvePathFromEnv($key, $default) { return ['PMSS_APT_SOURCES_PATH' => '/sources.list', 'PMSS_CONFIG_DIR' => '/config'][$key] ?? $default; }
function file_get_contents($path) { $GLOBALS['events'][] = ['read', $path]; return $GLOBALS['files'][$path] ?? false; }
function file_exists($path) { return array_key_exists($path, $GLOBALS['files']); }
function is_dir($path) { return false; }
function pmssDirEnsureExists($path, $mode) { $GLOBALS['events'][] = ['directory', $path]; return true; }
function pmssUserFilePathIsSafe($path) { $GLOBALS['events'][] = ['safe', $path]; return true; }
function pmssFileWriteComplete($path, $content, $flags) {
    $GLOBALS['events'][] = ['write', $path, $content, $flags];
    if (($GLOBALS['state']['failWrite'] ?? null) === $content) return false;
    $GLOBALS['files'][$path] = $content;
    return true;
}
function pmssTestModeEnabled() { return false; }
function pmssWriteManagedPathFile($path, $content, $label, $log, $uid, $gid, $mode, $error) {
    $GLOBALS['events'][] = ['managed', $path, $content, $mode]; return true;
}
function pmssAptDpkgEnvPrefix() { return 'fixture-env'; }
function exec($command, &$output, &$rc) {
    $GLOBALS['events'][] = ['exec', $command];
    if (!empty($GLOBALS['state']['throw'])) throw new \RuntimeException('injected');
    $output = $GLOBALS['state']['output'] ?? []; $rc = $GLOBALS['state']['rc'] ?? 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apt.php', 'AptRepositoryFixture; use \\Throwable');
        // Planning used to share the repositories include; keep the baseline fixture identical.
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/repositories.php', 'AptRepositoryFixture');
        foreach (['scripts/lib/update/aptSources.php', 'scripts/lib/update/aptMaintenance.php'] as $path) {
            if (is_file($this->pmssRepoPath($path))) {
                $script .= $this->pmssInlinePhpLibraryInNamespace($path, 'AptRepositoryFixture; use \\Throwable');
            }
        }
        $script .= '$GLOBALS["state"] = '.var_export($state, true).'; $GLOBALS["files"] = $GLOBALS["state"]["files"] ?? []; $GLOBALS["events"] = [];'
            .'$distro = $GLOBALS["state"]["distro"] ?? "debian"; $version = $GLOBALS["state"]["version"] ?? 12;'
            .'$logger = __NAMESPACE__."\\\\logMessage";'
            .'if ($GLOBALS["state"]["action"] === "plan") $result = pmssRepositoryUpdatePlan($distro, $version, $logger);'
            .'elseif ($GLOBALS["state"]["action"] === "clean") $result = pmssAptRunClean($logger);'
            .'else $result = pmssUpdateAptSources($distro, $version, $GLOBALS["state"]["hash"] ?? "", $GLOBALS["state"]["repos"] ?? [], $logger);'
            .'echo json_encode(["result" => $result, "events" => $GLOBALS["events"], "files" => $GLOBALS["files"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
