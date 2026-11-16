<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise the real installer with recorded paths and no package or network operations. */
class IprangeInstallerTest extends TestCase
{
    public function testExistingDistroAndLocalBinariesPreventSourceBuilds(): void
    {
        foreach ([['/usr/bin/iprange'], ['/usr/local/bin/iprange'],
            ['/usr/bin/iprange', '/usr/local/bin/iprange']] as $paths) {
            $result = $this->fixture($paths);
            $this->assertSame([], $result['builds']);
            $this->assertSame([], $result['packages']);
            $this->assertSame([], $result['logs']);
        }
    }

    public function testPackagePhaseGateStillPrecedesAllProbes(): void
    {
        $result = $this->fixture(['/usr/bin/iprange'], false);
        $this->assertSame([], $result['paths']);
        $this->assertSame([], $result['packages']);
        $this->assertSame([], $result['builds']);
        $this->assertSame(['[WARN] Skipping iprange build: package phase not complete'], $result['logs']);
    }

    public function testMissingToolchainStillPreventsSourceBuilds(): void
    {
        foreach (['build-essential', 'gcc', 'make', 'gawk'] as $missing) {
            $result = $this->fixture([], true, $missing);
            $this->assertSame([], $result['builds']);
            $this->assertSame(['[WARN] Skipping iprange build: missing toolchain packages '.$missing], $result['logs']);
        }
    }

    public function testAbsentBinariesStillUseThePinnedSourceInstaller(): void
    {
        $result = $this->fixture([]);
        $this->assertSame(['/usr/local/bin/iprange', '/usr/bin/iprange'], $result['paths']);
        $this->assertSame(['build-essential', 'gcc', 'make', 'gawk'], $result['packages']);
        $this->assertSame([[
            'iprange 1.0.4 source',
            'https://github.com/firehol/iprange/releases/download/v1.0.4/iprange-1.0.4.tar.xz',
            'e138d18974fb65674e5ea53da1ffbdde19704208e5114cc6b32d01db3ce1d567',
            'iprange-1.0.4.tar.xz', 'iprange-1.0.4', 'Building iprange from source',
            ["cd 'iprange-1.0.4'", './configure', 'make -j6', 'make install'],
        ]], $result['builds']);
        $this->assertSame([], $result['logs']);
    }

    /** Stub only the installer boundaries; the original top-level return flow still runs. */
    private function fixture(array $paths, bool $ready = true, string $missing = ''): array
    {
        $script = <<<'PHP'
namespace IprangeInstallerFixture;
function file_exists($path) {
    $GLOBALS['result']['paths'][] = $path;
    return in_array($path, $GLOBALS['paths'], true);
}
function pmssPackageStatus($package) {
    $GLOBALS['result']['packages'][] = $package;
    return $package === $GLOBALS['missing'] ? '' : 'install ok installed';
}
function logmsg($message) { $GLOBALS['result']['logs'][] = $message; }
function pmssRunPinnedRemoteArchiveStep(...$arguments) {
    $GLOBALS['result']['builds'][] = $arguments;
    return true;
}
PHP;
        $script .= '$GLOBALS["paths"] = '.var_export($paths, true).';';
        $script .= '$GLOBALS["missing"] = '.var_export($missing, true).';';
        $script .= '$GLOBALS["PMSS_PACKAGES_READY"] = '.var_export($ready, true).';';
        $script .= '$GLOBALS["result"] = ["paths" => [], "packages" => [], "logs" => [], "builds" => []];';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apps/iprange.php', 'IprangeInstallerFixture');
        return $this->pmssRunInlinePhpJson($script.'echo json_encode($GLOBALS["result"]);');
    }
}
