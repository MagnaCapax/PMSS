<?php
/** Characterize repository refresh order, stamp policy, and throwable boundaries. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RepositoryRefreshCharacterizationTest extends TestCase
{
    public function testExistingSourcesAlwaysRefreshWithoutPublishingSuccessStamp(): void
    {
        foreach ([0, 1, 100] as $rc) {
            $result = $this->fixture(['version' => 0, 'rc' => $rc]);
            $this->assertSame(true, $result['result']);
            $this->assertSame(null, $result['error']);
            $this->assertSame([
                ['prerequisite', 'mediaarea'], ['prerequisite', 'docker'], ['prerequisite', 'sonarr'],
                ['log', 'Repository version unresolved for debian; reusing existing sources'],
                ['step', 'Refreshing apt package index (existing sources)', 'apt-get update'],
            ], $result['events']);
        }
    }

    public function testTemplateRefreshRetainsBackupBeforeIndexAndSuccessOnlyStamp(): void
    {
        foreach ([0, 1, 100] as $rc) {
            $result = $this->fixture(['version' => 12, 'rc' => $rc]);
            $events = [
                ['prerequisite', 'mediaarea'], ['prerequisite', 'docker'], ['prerequisite', 'sonarr'],
                ['write', '/sources.list.pmss-backup', 'current', LOCK_EX],
                ['log', 'Backup for sources.list written to /sources.list.pmss-backup'],
                ['write', '/sources.list', "bookworm\n", LOCK_EX], ['log', 'Applied Debian Bookworm repository config'],
                ['step', 'Refreshing apt package index', 'apt-get update'],
            ];
            $events = array_merge($events, $rc === 0 ? [['directory', '/var/lib/apt/periodic', 0755],
                ['touch', '/var/lib/apt/periodic/update-success-stamp']]
                : [['log', '[WARN] apt-get update failed; not updating /var/lib/apt/periodic/update-success-stamp']]);
            $this->assertSame($events, $result['events']);
            $this->assertSame(true, $result['result']);
        }
    }

    public function testThrownIndexRefreshDoesNotPublishAStampOrSwallowTheFailure(): void
    {
        foreach ([0, 12] as $version) {
            $result = $this->fixture(['version' => $version, 'throw' => true]);
            $this->assertSame('index failure', $result['error']);
            $this->assertSame(null, $result['result']);
            $this->assertSame(['step', $version === 0 ? 'Refreshing apt package index (existing sources)' : 'Refreshing apt package index', 'apt-get update'], end($result['events']));
        }
    }

    private function fixture(array $state): array
    {
        $script = <<<'PHP'
namespace RepositoryRefreshFixture;
function logmsg($message) {}
function logMessage($message) { $GLOBALS['events'][] = ['log', $message]; }
function pmssEnvFlagEnabled($key) { return false; }
function pmssResolvePathFromEnv($key, $default) {
    return ['PMSS_APT_KEYRING_DIR' => '/keys', 'PMSS_APT_MEDIAAREA_KEY_PATH' => '/keys/mediaarea.asc',
        'PMSS_APT_SONARR_KEY_PATH' => '/keys/sonarr.gpg', 'PMSS_APT_SONARR_LEGACY_KEY_PATH' => '/keys/legacy.gpg',
        'PMSS_APT_SOURCES_PATH' => '/sources.list', 'PMSS_CONFIG_DIR' => '/config'][$key] ?? $default;
}
function is_file($path) {
    foreach (['mediaarea' => '/keys/mediaarea.asc', 'docker' => '/keys/docker.gpg', 'sonarr' => '/keys/sonarr.gpg'] as $name => $key) {
        if ($path !== $key) continue;
        if (empty($GLOBALS['started'][$name])) $GLOBALS['events'][] = ['prerequisite', $name];
        $GLOBALS['started'][$name] = true;
        return true;
    }
    return array_key_exists($path, $GLOBALS['files']);
}
function is_dir($path) { return $path === '/keys'; }
function glob($pattern) { return []; }
function file_get_contents($path) { return $GLOBALS['files'][$path] ?? false; }
function file_put_contents($path, $content) { return strlen($content); }
function chmod($path, $mode) { return true; }
function getenv($key) { return 'bookworm'; }
function pmssDistroVersionFromEnv() { return 12; }
function shell_exec($command) { return 'amd64'; }
function pmssDirEnsureExists($path, $mode) {
    if ($path !== '/') $GLOBALS['events'][] = ['directory', $path, $mode];
    return true;
}
function pmssUserFilePathIsSafe($path) { return true; }
function pmssFileWriteComplete($path, $content, $flags) { $GLOBALS['events'][] = ['write', $path, $content, $flags]; return true; }
function aptCmd($command) { return 'apt-get '.$command; }
function runStep($description, $command) {
    $GLOBALS['events'][] = ['step', $description, $command];
    if (!empty($GLOBALS['state']['throw'])) throw new \RuntimeException('index failure');
    return $GLOBALS['state']['rc'] ?? 0;
}
function touch($path) { $GLOBALS['events'][] = ['touch', $path]; return true; }
PHP;
        foreach (['apt.php', 'repositories.php', 'aptSources.php', 'aptMaintenance.php', 'repositoryDocker.php', 'repositorySonarr.php'] as $module) {
            if (is_file($this->pmssRepoPath('scripts/lib/update/'.$module))) {
                $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/'.$module, 'RepositoryRefreshFixture');
            }
        }
        $script .= '$GLOBALS["state"] = '.var_export($state, true).'; $GLOBALS["events"] = []; $GLOBALS["started"] = [];'
            .'$GLOBALS["files"] = ["/sources.list" => "current"];'
            .'foreach (["jessie", "buster", "bullseye", "bookworm", "trixie"] as $suite) $GLOBALS["files"]["/config/template.sources.".$suite] = $suite;'
            .'$result = $error = null; try { $result = pmssRefreshRepositories("debian", $GLOBALS["state"]["version"], __NAMESPACE__."\\\\logMessage"); }'
            .'catch (\\Throwable $failure) { $error = $failure->getMessage(); }'
            .'echo json_encode(["result" => $result, "events" => $GLOBALS["events"], "error" => $error]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
