<?php
/** Characterize Sonarr source scoping and key retirement without host I/O. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RepositoryPrerequisitesCharacterizationTest extends TestCase
{
    public function testSonarrSourceBytesAndPublicationOrder(): void
    {
        foreach ([
            ["deb https://sonarr.invalid stable main\n", "deb [signed-by=/keys/sonarr.gpg] https://sonarr.invalid stable main\n"],
            ['deb [arch=amd64] https://nzbdrone.invalid stable main', 'deb [arch=amd64 signed-by=/keys/sonarr.gpg] https://nzbdrone.invalid stable main'],
            ["# sonarr\r\ndeb https://sonarr.invalid stable main\r\n\r\n", "# sonarr\ndeb [signed-by=/keys/sonarr.gpg] https://sonarr.invalid stable main\n\n"],
            ["# deb https://sonarr.invalid stable main\r\n", null],
            ["deb [signed-by=/keys/existing.gpg] https://sonarr.invalid stable main\n", null],
            ["deb-src https://sonarr.invalid stable main\n", null],
            ["deb https://mirror.invalid stable main\n", null], ['', null],
        ] as [$source, $updated]) {
            $result = $this->fixture(['source' => $source]);
            $this->assertSame($updated ?? $source, $result['files']['/sources.list']);
            $events = [['chmod', '/keys/sonarr.gpg', 0644], ['log', 'Sonarr key installed at /keys/sonarr.gpg (fingerprint EBFF6B99D9B78493)']];
            if ($updated !== null) $events = array_merge($events, [
                ['write', '/sources.list', $updated, LOCK_EX], ['chmod', '/sources.list', 0644],
                ['log', 'Scoped Sonarr apt source with signed-by in /sources.list'],
            ]);
            $this->assertSame(array_merge($events, [
                ['unlink', '/trusted/sonarr.gpg'], ['log', 'Removed legacy globally trusted Sonarr key: /trusted/sonarr.gpg'],
                ['log', 'Sonarr key available at /keys/sonarr.gpg for scoped apt sources'],
            ]), $result['events']);
            $this->assertSame(null, $result['error']);
        }
    }

    public function testFailedPublicationRetainsGlobalKeyAndThrowablesStopRetirement(): void
    {
        foreach (['false', 'throw', 'error'] as $failure) {
            $result = $this->fixture(['source' => 'deb https://sonarr.invalid stable main', 'failure' => $failure]);
            $this->assertSame('legacy-key', $result['files']['/trusted/sonarr.gpg']);
            $this->assertSame('deb https://sonarr.invalid stable main', $result['files']['/sources.list']);
            $this->assertSame($failure === 'false' ? null : $failure, $result['error']);
            $this->assertSame($failure === 'false'
                ? ['log', '[WARN] Failed to scope Sonarr apt source with signed-by in /sources.list']
                : ['write', '/sources.list', 'deb [signed-by=/keys/sonarr.gpg] https://sonarr.invalid stable main', LOCK_EX],
                end($result['events']));
        }
    }

    public function testDryRunRetainsSourceAndLegacyKeyWithOrderedDiagnostics(): void
    {
        $source = "deb https://sonarr.invalid stable main\n";
        $result = $this->fixture(['source' => $source, 'dry' => true]);
        $this->assertSame($source, $result['files']['/sources.list']);
        $this->assertSame('legacy-key', $result['files']['/trusted/sonarr.gpg']);
        $this->assertSame([
            ['chmod', '/keys/sonarr.gpg', 0644], ['log', 'Sonarr key installed at /keys/sonarr.gpg (fingerprint EBFF6B99D9B78493)'],
            ['log', '[SKIP] Would scope Sonarr apt source with signed-by in /sources.list'],
            ['log', '[SKIP] Would remove legacy globally trusted Sonarr key: /trusted/sonarr.gpg'],
            ['log', 'Sonarr key available at /keys/sonarr.gpg for scoped apt sources'],
        ], $result['events']);
    }

    public function testDuplicateTargetsAndUnchangedListsDoNotCauseExtraWrites(): void
    {
        $result = $this->fixture(['source' => 'deb https://sonarr.invalid stable main',
            'lists' => ['/sources.list', '/sources.list', '/lists/empty.list', '/lists/directory.list'],
            'files' => ['/lists/empty.list' => '']]);
        $writes = array_values(array_filter($result['events'], static function (array $event): bool { return $event[0] === 'write'; }));
        $this->assertSame([['write', '/sources.list', 'deb [signed-by=/keys/sonarr.gpg] https://sonarr.invalid stable main', LOCK_EX]], $writes);
    }

    private function fixture(array $state): array
    {
        $script = <<<'PHP'
namespace RepositoryPrerequisitesFixture;
function logmsg($message) { $GLOBALS['events'][] = ['log', $message]; }
function pmssEnvFlagEnabled($key) { return $GLOBALS['state']['dry'] ?? false; }
function pmssResolvePathFromEnv($key, $default) {
    return ['PMSS_APT_KEYRING_DIR' => '/keys', 'PMSS_APT_SONARR_KEY_PATH' => '/keys/sonarr.gpg',
        'PMSS_APT_SONARR_LEGACY_KEY_PATH' => '/trusted/sonarr.gpg', 'PMSS_APT_SOURCES_PATH' => '/sources.list',
        'PMSS_APT_SOURCES_LIST_D_PATH' => '/lists'][$key] ?? $default;
}
function is_file($path) { return array_key_exists($path, $GLOBALS['files']); }
function is_dir($path) { return true; }
function runStep($description, $command) { throw new \RuntimeException('unexpected command: '.$description); }
function rename($from, $to) { throw new \RuntimeException('unexpected rename'); }
function copy($from, $to) { throw new \RuntimeException('unexpected copy'); }
function glob($pattern) { return $GLOBALS['state']['lists'] ?? []; }
function file_get_contents($path) { return $GLOBALS['files'][$path] ?? false; }
function file_put_contents($path, $content, $flags = 0) {
    $GLOBALS['events'][] = ['write', $path, $content, $flags];
    $failure = $GLOBALS['state']['failure'] ?? '';
    if ($failure === 'throw') throw new \RuntimeException('throw');
    if ($failure === 'error') throw new \Error('error');
    if ($failure === 'false') return false;
    $GLOBALS['files'][$path] = $content;
    return strlen($content);
}
function chmod($path, $mode) { $GLOBALS['events'][] = ['chmod', $path, $mode]; return true; }
function unlink($path) { $GLOBALS['events'][] = ['unlink', $path]; unset($GLOBALS['files'][$path]); return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/repositories.php', 'RepositoryPrerequisitesFixture');
        // The optional module lets this same behavior lock characterize the unsplit baseline.
        if (is_file($this->pmssRepoPath('scripts/lib/update/repositorySonarr.php'))) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/repositorySonarr.php', 'RepositoryPrerequisitesFixture');
        }
        $script .= '$GLOBALS["state"] = '.var_export($state, true).'; $GLOBALS["events"] = [];'
            .'$GLOBALS["files"] = ["/keys/sonarr.gpg" => "scoped-key", "/trusted/sonarr.gpg" => "legacy-key", '
            .'"/sources.list" => $GLOBALS["state"]["source"]] + ($GLOBALS["state"]["files"] ?? []);'
            .'$error = null; try { pmssEnsureSonarrKey(); } catch (\\Throwable $failure) { $error = $failure->getMessage(); }'
            .'echo json_encode(["files" => $GLOBALS["files"], "events" => $GLOBALS["events"], "error" => $error]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
