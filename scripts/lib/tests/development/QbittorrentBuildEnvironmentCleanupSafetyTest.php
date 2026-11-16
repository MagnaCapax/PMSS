<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentBuildProbe.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentBuildProbe.php';

/** Environment setup faults never create real profiles or start real children. */
class QbittorrentBuildEnvironmentCleanupSafetyTest extends TestCase
{
    public function testBothProbesOwnProfilesDuringEnvironmentSetup(): void
    {
        $script = <<<'PHP'
namespace BuildProbeEnvironmentFixture;
function sys_get_temp_dir() { return '/fixture'; }
function random_bytes($length) { return str_repeat('x', $length); }
function mkdir($path, $mode) {
    $GLOBALS['events'][] = ['mkdir', $path, $mode];
    return $GLOBALS['mkdirOk'];
}
function getenv($name) {
    $GLOBALS['events'][] = ['environment', $name];
    if ($GLOBALS['environmentFault'] !== null) throw $GLOBALS['environmentFault'];
    return $GLOBALS['path'];
}
function proc_open($command, $descriptors, &$pipes, $cwd, $env) {
    $GLOBALS['events'][] = ['launch', $command, $descriptors, $cwd, $env];
    return false;
}
function profileClean($profile) {
    $GLOBALS['events'][] = ['clean', $profile];
    if ($GLOBALS['cleanupFault'] !== null) throw $GLOBALS['cleanupFault'];
}
PHP;
        $probes = ['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'];
        foreach ($probes as $probe) {
            $script .= \pmssTestingFunctionExport($probe, 'BuildProbeEnvironmentFixture', [$probe.'Clean' => 'profileClean']);
        }
        $script .= <<<'PHP'
$rows = [];
foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe) {
    foreach ([
        ['mkdir-false', false, '/fixture/bin', null, null],
        ['unset', true, false, null, null],
        ['empty', true, '', null, null],
        ['zero', true, '0', null, null],
        ['inherited', true, '/fixture/bin:/usr/bin', null, null],
        ['literal', true, ' /fixture/bin ', null, null],
        ['exception', true, false, new \RuntimeException('environment'), null],
        ['error', true, false, new \Error('environment'), null],
        ['combined-error', true, false, new \RuntimeException('environment'), new \Error('cleanup')],
        ['combined-exception', true, false, new \Error('environment'), new \RuntimeException('cleanup')],
    ] as [$case, $mkdirOk, $path, $environmentFault, $cleanupFault]) {
        $GLOBALS['events'] = [];
        $GLOBALS['mkdirOk'] = $mkdirOk; $GLOBALS['path'] = $path;
        $GLOBALS['environmentFault'] = $environmentFault; $GLOBALS['cleanupFault'] = $cleanupFault;
        $caught = $result = null;
        try { $result = (__NAMESPACE__.'\\'.$probe)('/fixture/qbittorrent-nox'); }
        catch (\Throwable $error) { $caught = $error; }
        $rows[] = [$probe, $case, $mkdirOk, $path, $environmentFault !== null, $result,
            $caught === ($cleanupFault ?? $environmentFault), $GLOBALS['events']];
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(20, count($rows));
        $profile = '/fixture/pmss-qbt-probe-'.str_repeat('78', 8);
        foreach ($rows as [$probe, $case, $mkdirOk, $path, $environmentFailed, $result, $sameFault, $events]) {
            $expectedEvents = [['mkdir', $profile, 0700]];
            if ($mkdirOk) {
                $expectedEvents[] = ['environment', 'PATH'];
                if (!$environmentFailed) {
                    $expectedEvents[] = ['launch', 'exec setsid '.escapeshellarg('/fixture/qbittorrent-nox').' --version',
                        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                        $profile, ['PATH' => $path ?: '/usr/bin:/bin', 'HOME' => $profile,
                            'XDG_CONFIG_HOME' => $profile.'/.config', 'XDG_DATA_HOME' => $profile.'/.local/share',
                            'XDG_CACHE_HOME' => $profile.'/.cache']];
                }
                // Only a successfully acquired profile authorizes cleanup, exactly once.
                $expectedEvents[] = ['clean', $profile];
            }
            $this->assertSame([null, true, $expectedEvents], [$result, $sameFault, $events], $probe.':'.$case);
        }
    }
}
