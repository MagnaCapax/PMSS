<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/rootOwnedTree.php';

class AiDockerInstallerSafetyTest extends TestCase
{
    public function testAiToolsArchiveExtractionAndNodeRefreshOrder(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/aiToolsInstall.php', [
            "require_once __DIR__.'/../rootOwnedTree.php';",
            'if (!$dryRun && is_dir($installRoot))',
            "preg_match('/\\A\\.pmss-remove-[0-9a-f]{16}\\z/', \$entry)",
            'pmssRootOwnedTreeRemove($staleDir)',
            "preg_match('/\\Anode-v[0-9]+(?:\\.[0-9]+)*-linux-x64\\z/', \$entry)",
            'pmssRootOwnedTreeRemove($oldDir)',
            'pmssRootOwnedTreeNeedsRefresh($nodeDir)',
            'pmssRootOwnedTreeRemove($nodeDir)',
            '!is_executable($nodeBinary)',
            "runStep('Extracting pinned Node.js runtime for AI CLI tools', 'umask 022 && '.pmssBuildCommand('tar', ['--no-same-owner', '--no-same-permissions'",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/aiToolsInstall.php', [
            "runStep('Extracting Codex CLI archive', 'umask 022 && '.pmssBuildCommand('tar', ['--no-same-owner', '--no-same-permissions'",
            "preg_match('/\\Anode-v[0-9]+(?:\\.[0-9]+)*-linux-x64\\z/', \$entry)",
            'pmssRootOwnedTreeRemove($oldDir)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/arr.php', [
            'umask 022 && tar --no-same-owner --no-same-permissions -xzf %s -C %s',
        ]);
    }

    public function testAiToolsNodeTreeOwnershipAndLinkSafeRemoval(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ai-node-');
        $tree = $root.'/node-v22.23.4-linux-x64';
        $outside = $root.'/outside';
        $this->assertTrue(@mkdir($tree.'/bin', 0700, true));
        $this->assertTrue(@mkdir($outside));
        $this->assertSame(1, @file_put_contents($tree.'/bin/node', 'n'));
        $this->assertSame(1, @file_put_contents($outside.'/keep', 'x'));
        $this->assertTrue(@symlink($outside, $tree.'/bin/outside-link'));

        $uid = @fileowner($tree);
        $this->assertTrue(is_int($uid));
        $this->assertFalse(\pmssRootOwnedTreeNeedsRefresh($tree, $uid));
        $this->assertTrue(\pmssRootOwnedTreeNeedsRefresh($tree, $uid + 1));
        $this->assertTrue(\pmssRootOwnedTreeNeedsRefresh($tree.'/bin/outside-link', $uid));
        $this->assertTrue(\pmssRootOwnedTreeRemove($tree));
        $this->assertFalse(file_exists($tree));
        $this->assertSame([], glob($root.'/.pmss-remove-*'));
        $this->assertTrue(is_file($outside.'/keep'));
        $this->assertTrue(\pmssRootOwnedTreeRemove($tree));

        $stale = $root.'/.pmss-remove-0123456789abcdef';
        $this->assertTrue(@mkdir($stale.'/tree/bin', 0700, true));
        $this->assertSame(1, @file_put_contents($stale.'/tree/bin/node', 'n'));
        $this->assertTrue(\pmssRootOwnedTreeRemove($stale));
        $this->assertSame([], glob($root.'/.pmss-remove-*'));
    }

    public function testCodexFallbackConfigWriteIsChecked(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/aiToolsInstall.php', [
            'function pmssAiToolsCodexFallbackConfigEnsure(bool $dryRun, ?callable $logger = null): void',
            "pmssPathExistsOrLink(\$path)",
            'Refusing to write Codex fallback config: unsafe target exists.',
            '@file_put_contents($path, $content) === false',
            'Unable to write Codex fallback config.',
            '!@chmod($path, 0644)',
            'Unable to set Codex fallback config mode.',
            'pmssAiToolsCodexFallbackConfigEnsure($dryRun);',
        ]);
    }

    public function testDockerSlirpArchitectureIsValidatedBeforeUrlUse(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/docker.php', [
            'function pmssDockerSlirpArchitectureFromOutput($archOutput, ?callable $logger = null): string',
            "preg_match('/\\A[A-Za-z0-9._-]+\\z/', \$arch) !== 1",
            "substr(\$arch, 0, 1) === '-'",
            'unsafe uname architecture output; falling back to x86_64.',
            '$arch = pmssDockerSlirpArchitectureFromOutput($archOutput);',
            '$slirpSha256 = PMSS_DOCKER_SLIRP_SHA256[$arch] ?? \'\';',
        ]);
    }

    public function testDockerSlirpInstallIsPinnedUnderItsOwnName(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/docker.php', [
            "require_once __DIR__.'/remoteBinary.php';",
            'const PMSS_DOCKER_SLIRP_SHA256 = [',
            '$slirpSha256 = PMSS_DOCKER_SLIRP_SHA256[$arch] ?? \'\';',
            'no pinned slirp4netns build for',
            "'https://github.com/rootless-containers/slirp4netns/releases/download/v1.3.6/slirp4netns-'.\$arch",
            "pmssInstallPinnedRemoteBinary('slirp4netns v1.3.6', \$slirpUrl, \$slirpSha256, '/usr/local/bin/slirp4netns', true);",
            "pmssDockerSlirpHelperMatchesPin('/usr/local/bin/slirp4netns', \$slirpSha256)",
            "pmssDockerRemoveStraySlirpCopies('/usr/local/bin', \$dryRun);",
            "runStep('[docker] Docker: creating iptables symlink'",
            "} elseif (\$version >= 12) {\n    pmssDockerRemoveStraySlirpCopies('/usr/local/bin', \$dryRun);",
        ]);
        $source = $this->pmssReadRepoFile('scripts/lib/update/apps/docker.php');
        foreach (["'/usr/local/bin/']", '@tempnam(', 'download/v1.3.2/', '--version'] as $forbidden) {
            $this->assertTrue(strpos($source, $forbidden) === false, 'docker.php must not contain '.$forbidden);
        }
        foreach (['x86_64', 'aarch64', 'armv7l', 'ppc64le', 'riscv64', 's390x'] as $arch) {
            $this->assertSame(1, preg_match("/'".$arch."' => '[a-f0-9]{64}',/", $source), $arch);
        }
    }
}
