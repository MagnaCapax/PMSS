<?php
/** Characterize Docker repository bytes and failure gates using in-memory I/O. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RepositoryDockerCharacterizationTest extends TestCase
{
    public function testLegacySourceDisablingPreservesBytesAndTrailingLinePolicy(): void
    {
        foreach ([
            ["deb https://docker.invalid stable main\n", "# PMSS(disable, switched to deb822): deb https://docker.invalid stable main\n\n"],
            ["\tdeb https://docker.invalid stable main\r\n# keep\r\n", "# PMSS(disable, switched to deb822): \tdeb https://docker.invalid stable main\n# keep\n\n"],
            ['deb https://docker.invalid stable main', "# PMSS(disable, switched to deb822): deb https://docker.invalid stable main\n"],
            ["  # keep\r\n\t\r\n", null], ['', null], [false, null],
        ] as [$source, $updated]) {
            $result = $this->fixture(['source' => $source]);
            $this->assertSame($updated ?? $source, $result['files']['/etc/apt/sources.list.d/docker.list']);
            $deb822 = "Types: deb\nURIs: https://download.docker.com/linux/debian\nSuites: bookworm\nComponents: stable\nArchitectures: amd64\nSigned-By: /keys/docker.gpg\n";
            $events = [['architecture'], ['write', '/etc/apt/sources.list.d/docker.sources', $deb822],
                ['chmod', '/etc/apt/sources.list.d/docker.sources', 0644],
                ['log', 'Docker deb822 source written: /etc/apt/sources.list.d/docker.sources']];
            if ($updated !== null) $events = array_merge($events, [
                ['write', '/etc/apt/sources.list.d/docker.list', $updated], ['log', 'Disabled legacy Docker entry in docker.list'],
            ]);
            $this->assertSame($events, $result['events']);
        }
    }

    public function testUnknownSuiteAndKeyFetchFailureStopBeforeSourcePublication(): void
    {
        $result = $this->fixture(['version' => 10]);
        $this->assertSame([['log', '[WARN] Skipping Docker repository setup: unknown suite']], $result['events']);
        $result = $this->fixture(['missingKey' => true, 'fetchRc' => 1]);
        $this->assertSame([
            ['step', 'Fetching Docker repository key', "curl -fsSL --connect-timeout 60 --speed-time 60 --speed-limit 1 'https://download.docker.com/linux/debian/gpg' | gpg --dearmor -o '/keys/docker.gpg'"],
            ['unlink', '/keys/docker.gpg'], ['log', '[WARN] Docker key fetch failed; proceeding without writing docker.sources'],
        ], $result['events']);
    }

    public function testExplicitSuiteArchitectureAndFailedSourceWriteKeepLegacyOrdering(): void
    {
        $result = $this->fixture(['codename' => 'bullseye', 'architecture' => " arm64\n", 'writeFailure' => true,
            'source' => 'deb https://docker.invalid stable main']);
        $this->assertSame([
            ['architecture'], ['write', '/etc/apt/sources.list.d/docker.sources',
                "Types: deb\nURIs: https://download.docker.com/linux/debian\nSuites: bullseye\nComponents: stable\nArchitectures: arm64\nSigned-By: /keys/docker.gpg\n"],
            ['write', '/etc/apt/sources.list.d/docker.list', "# PMSS(disable, switched to deb822): deb https://docker.invalid stable main\n"],
            ['log', 'Disabled legacy Docker entry in docker.list'],
        ], $result['events']);
    }

    public function testSuccessfulFetchKeepsPermissionStepBeforeArchitectureProbe(): void
    {
        $result = $this->fixture(['missingKey' => true, 'fetchRc' => 0]);
        $this->assertSame([
            ['step', 'Fetching Docker repository key', "curl -fsSL --connect-timeout 60 --speed-time 60 --speed-limit 1 'https://download.docker.com/linux/debian/gpg' | gpg --dearmor -o '/keys/docker.gpg'"],
            ['step', 'Setting permissions on Docker repository key', "chmod 0644 '/keys/docker.gpg'"],
            ['architecture'],
        ], array_slice($result['events'], 0, 3));
    }

    private function fixture(array $state): array
    {
        $script = <<<'PHP'
namespace RepositoryDockerFixture;
function logmsg($message) { $GLOBALS['events'][] = ['log', $message]; }
function pmssResolvePathFromEnv($key, $default) { return $key === 'PMSS_APT_KEYRING_DIR' ? '/keys' : $default; }
function is_file($path) { return isset($GLOBALS['files'][$path]); }
function is_dir($path) { return true; }
function getenv($key) { return $GLOBALS['state']['codename'] ?? ''; }
function pmssDistroVersionFromEnv() { return $GLOBALS['state']['version'] ?? 12; }
function pmssDebianCodenameFromMajor($version) { return \pmssDebianCodenameFromMajor($version); }
function shell_exec($command) { $GLOBALS['events'][] = ['architecture']; return $GLOBALS['state']['architecture'] ?? ''; }
function runStep($description, $command) {
    $GLOBALS['events'][] = ['step', $description, $command];
    return $GLOBALS['state']['fetchRc'] ?? 0;
}
function file_get_contents($path) { return $GLOBALS['files'][$path] ?? false; }
function file_put_contents($path, $content) {
    $GLOBALS['events'][] = ['write', $path, $content];
    if (!empty($GLOBALS['state']['writeFailure'])) return false;
    $GLOBALS['files'][$path] = $content;
    return strlen($content);
}
function chmod($path, $mode) { $GLOBALS['events'][] = ['chmod', $path, $mode]; return true; }
function unlink($path) { $GLOBALS['events'][] = ['unlink', $path]; unset($GLOBALS['files'][$path]); return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/repositories.php', 'RepositoryDockerFixture');
        if (is_file($this->pmssRepoPath('scripts/lib/update/repositoryDocker.php'))) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/repositoryDocker.php', 'RepositoryDockerFixture');
        }
        $script .= '$GLOBALS["state"] = '.var_export($state, true).'; $GLOBALS["events"] = [];'
            .'$GLOBALS["files"] = ["/etc/apt/sources.list.d/docker.list" => $GLOBALS["state"]["source"] ?? false];'
            .'if (empty($GLOBALS["state"]["missingKey"])) $GLOBALS["files"]["/keys/docker.gpg"] = "public-key";'
            .'pmssEnsureDockerRepository(); echo json_encode(["files" => $GLOBALS["files"], "events" => $GLOBALS["events"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
