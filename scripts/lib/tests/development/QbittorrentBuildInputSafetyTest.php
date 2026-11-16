<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentBuildProbe.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentBuildProbe.php';

/** Check both executable boundaries without creating profiles or starting children. */
class QbittorrentBuildInputSafetyTest extends TestCase
{
    public function testMalformedBinaryPathsStopBeforeAnyProfileOrProcessOperation(): void
    {
        $paths = ['', "\0", "\0/fixture/qbittorrent-nox", "/fixture/qbit\0torrent-nox",
            "/fixture/qbittorrent-nox\0", " \0/fixture/qbittorrent-nox\0 "];
        $rows = $this->observeProbes($paths);
        $this->assertSame(2 * count($paths), count($rows));
        foreach ($rows as [$probe, $path, $result, $events]) {
            $this->assertSame([null, []], [$result, $events], $probe.':'.bin2hex($path));
        }
    }

    public function testValidPathsKeepExactLaunchArgumentsAndFailedLaunchProfileCleanup(): void
    {
        // Shell quoting, relative names and surrounding whitespace remain caller policy.
        $paths = ['/fixture/qbittorrent-nox', 'relative build', ' /fixture/qbittorrent-nox ',
            "/fixture/qbt'build", '/fixture/qbt;echo fixture', '0'];
        $rows = $this->observeProbes($paths);
        $this->assertSame(2 * count($paths), count($rows));
        $profile = '/fixture/pmss-qbt-probe-'.str_repeat('78', 8);
        foreach ($rows as [$probe, $path, $result, $events]) {
            $env = ['PATH' => '/fixture/bin:/usr/bin', 'HOME' => $profile,
                'XDG_CONFIG_HOME' => $profile.'/.config', 'XDG_DATA_HOME' => $profile.'/.local/share',
                'XDG_CACHE_HOME' => $profile.'/.cache'];
            $this->assertSame([null, [
                ['temp'], ['random', 8], ['mkdir', $profile, 0700], ['env', 'PATH'],
                ['launch', 'exec setsid '.escapeshellarg($path).' --version',
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                    $profile, $env],
                ['clean', $profile],
            ]], [$result, $events], $probe.':'.$path);
        }
    }

    /** Export each implementation into intercepted profile and process operations. */
    private function observeProbes(array $paths): array
    {
        $script = <<<'PHP'
namespace BuildProbeInputFixture;
function sys_get_temp_dir() { $GLOBALS['events'][] = ['temp']; return '/fixture'; }
function random_bytes($length) { $GLOBALS['events'][] = ['random', $length]; return str_repeat('x', $length); }
function mkdir($path, $mode) { $GLOBALS['events'][] = ['mkdir', $path, $mode]; return true; }
function getenv($key) { $GLOBALS['events'][] = ['env', $key]; return '/fixture/bin:/usr/bin'; }
function proc_open($command, $descriptors, &$pipes, $cwd, $env) {
    $GLOBALS['events'][] = ['launch', $command, $descriptors, $cwd, $env];
    return false;
}
function profileClean($path) { $GLOBALS['events'][] = ['clean', $path]; }
PHP;
        foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe) {
            $script .= \pmssTestingFunctionExport($probe, 'BuildProbeInputFixture', [$probe.'Clean' => 'profileClean']);
        }
        $script .= <<<'PHP'
$rows = [];
foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe) {
    foreach (json_decode(\getenv('PMSS_TEST_QBT_BINARY_PATHS'), true) as $path) {
        $GLOBALS['events'] = [];
        $result = (__NAMESPACE__.'\\'.$probe)($path);
        $rows[] = [$probe, $path, $result, $GLOBALS['events']];
    }
}
echo json_encode($rows);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_QBT_BINARY_PATHS' => json_encode($paths)]);
    }
}
