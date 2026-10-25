<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Freeze portable installers at their command and publication boundaries, without host writes. */
class PortableInstallerCharacterizationTest extends TestCase
{
    private const APPS = [
        'qui' => ['qui', '1.32.0', 'https://github.com/autobrr/qui/releases/download/v1.32.0/qui_1.32.0_linux_x86_64.tar.gz', 'acead415955af1387e8939392cca3978799c01022904858a2156d8c3f7fcbf93'],
        'scryer' => ['Scryer', '0.21.17', 'https://github.com/scryer-media/scryer/releases/download/scryer-v0.21.17/scryer-linux-x86_64-portable.tar.gz', '97a3b93e27189b5c42458c25f6c5704d307e7bcc102ae8da44c303ef230de0f5'],
        'slskd' => ['slskd', '0.26.1', 'https://github.com/slskd/slskd/releases/download/0.26.1/slskd-0.26.1-linux-x64.zip', '26c862173c593142db3fcfcd78f0784cfc29987de40a5bd9c2e662ed3b84cce6'],
    ];

    public function testSuccessfulInstallsKeepPinsCommandsGuardOrderAndMarkerBytes(): void
    {
        foreach (self::APPS as $app => [$label, $version, $url, $sha]) {
            $root = '/opt/'.$app;
            $marker = $root.'/pmss-version';
            $guards = [[$root, true]];
            if ($app !== 'slskd') {
                $guards[] = [$root.'/'.$app, false];
                $guards[] = [$root.'/'.$app.'.tmp', false];
            }
            $guards[] = [$marker, false];
            $guards[] = [$marker, false, true];
            $command = "mkdir -p '{$root}'".($app === 'slskd'
                ? " && unzip -oq '/fixture/archive' -d '{$root}' && chmod 0755 '{$root}/slskd'"
                : " && tar -xzOf '/fixture/archive' '{$app}' > '{$root}/{$app}.tmp'"
                    ." && install -o root -g root -m 0755 '{$root}/{$app}.tmp' '{$root}/{$app}'"
                    ." && rm -f '{$root}/{$app}.tmp'");
            $result = $this->fixture($app);
            $this->assertSame([[$label.' '.$version, $url, $sha]], $result['downloads']);
            $this->assertSame([['Installing '.$label.' '.$version, $command]], $result['commands']);
            $this->assertSame($guards, $result['guards']);
            $this->assertSame([[$marker, $version."\n"]], $result['writes']);
            $this->assertSame(true, $result['callback']);
            $this->assertSame('', $result['stdout']);
        }
    }

    public function testArchitectureAndMatchingMarkersSkipBeforeDownload(): void
    {
        foreach (self::APPS as $app => [$label, $version]) {
            foreach (['unsupported', 'current'] as $case) {
                $result = $this->fixture($app, $case === 'unsupported'
                    ? ['architecture' => 'aarch64'] : ['binary' => true, 'version' => " {$version}\n"]);
                $this->assertSame([], $result['downloads']);
                $this->assertSame([], $result['commands']);
                $this->assertSame([], $result['writes']);
                $this->assertSame($case === 'unsupported'
                    ? ['[SKIP] '.$label.' bootstrap skipped on unsupported architecture: aarch64'] : [], $result['logs']);
            }
        }
    }

    public function testMissingStaleOrLinkedMarkersStillInstall(): void
    {
        foreach (self::APPS as $app => [$label, $version]) {
            foreach ([[], ['binary' => true], ['binary' => true, 'version' => 'old'],
                ['binary' => true, 'version' => $version, 'markerLink' => true]] as $state) {
                $result = $this->fixture($app, $state);
                $this->assertSame(1, count($result['downloads']));
                $this->assertSame(1, count($result['writes']));
            }
        }
    }

    public function testUnsafeTargetsRetainShortCircuitingBeforeCommands(): void
    {
        foreach (self::APPS as $app => $spec) {
            $paths = ['/opt/'.$app];
            if ($app !== 'slskd') array_push($paths, '/opt/'.$app.'/'.$app, '/opt/'.$app.'/'.$app.'.tmp');
            $paths[] = '/opt/'.$app.'/pmss-version';
            foreach ($paths as $index => $unsafe) {
                $result = $this->fixture($app, ['unsafe' => $unsafe]);
                $this->assertSame(array_slice($paths, 0, $index + 1), array_column($result['guards'], 0));
                $this->assertSame([], $result['commands']);
                $this->assertSame([], $result['writes']);
                $this->assertSame(false, $result['callback']);
            }
        }
    }

    public function testDownloadDryRunInstallAndMarkerFailuresRetainPublicationBoundaries(): void
    {
        foreach (self::APPS as $app => $spec) {
            foreach (['download' => false, 'dryRun' => true, 'rc' => 7, 'write' => false] as $key => $value) {
                $result = $this->fixture($app, [$key => $value]);
                $this->assertSame(1, count($result['downloads']));
                $this->assertSame($key === 'rc' || $key === 'write' ? 1 : 0, count($result['commands']));
                $this->assertSame($key === 'write' ? 1 : 0, count($result['writes']));
                $this->assertSame($key === 'download' || $key === 'dryRun' ? null : false, $result['callback']);
            }
        }
    }

    /** Inline real entrypoints with recording dependencies; nothing downloads or touches /opt. */
    private function fixture(string $app, array $state = []): array
    {
        $script = <<<'PHP'
namespace PortableInstallerFixture;
function php_uname($mode) { return $GLOBALS['state']['architecture']; }
function is_file($path) { return $GLOBALS['state']['binary']; }
function is_link($path) { return $GLOBALS['state']['markerLink']; }
function file_get_contents($path) { return $GLOBALS['state']['version']; }
function logmsg($message) { $GLOBALS['result']['logs'][] = $message; }
function pmssPathTargetIsSafe(...$arguments) {
    $GLOBALS['result']['guards'][] = $arguments;
    return $arguments[0] !== $GLOBALS['state']['unsafe'];
}
function pmssFileWriteComplete($path, $bytes) {
    $GLOBALS['result']['writes'][] = [$path, $bytes];
    return $GLOBALS['state']['write'];
}
function runStep($label, $command) {
    $GLOBALS['result']['commands'][] = [$label, $command];
    return $GLOBALS['state']['rc'];
}
function pmssPinnedRemoteArtifactTempFileUse($label, $url, $sha, $callback) {
    $GLOBALS['result']['downloads'][] = [$label, $url, $sha];
    if ($GLOBALS['state']['download'] && !$GLOBALS['state']['dryRun']) {
        $GLOBALS['result']['callback'] = $callback('/fixture/archive');
    }
}
PHP;
        $script .= '$GLOBALS["state"] = '.var_export($state + [
            'architecture' => 'x86_64', 'binary' => false, 'markerLink' => false, 'version' => '',
            'unsafe' => '', 'download' => true, 'dryRun' => false, 'rc' => 0, 'write' => true,
        ], true).';';
        $script .= '$GLOBALS["result"] = ["downloads" => [], "commands" => [], "guards" => [], "writes" => [], "logs" => [], "callback" => null];';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apps/remoteBinary.php', 'PortableInstallerFixture', ['remoteBinary/archive.php']);
        $script .= 'ob_start();';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apps/'.$app.'.php', 'PortableInstallerFixture');
        $script .= '$GLOBALS["result"]["stdout"] = ob_get_clean(); echo json_encode($GLOBALS["result"]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
