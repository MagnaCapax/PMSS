<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/apps/aiToolsNodeRuntime.php';

class AiDockerInstallerSafetyTest extends TestCase
{
    public function testAiToolsArchiveExtractionAndNodeRefreshOrder(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/aiToolsInstall.php', [
            "require_once __DIR__.'/aiToolsNodeRuntime.php';",
            'if (!$dryRun && is_dir($installRoot))',
            "preg_match('/\\A\\.pmss-remove-[0-9a-f]{16}\\z/', \$entry)",
            'pmssAiToolsNodeTreeRemove($staleDir)',
            "preg_match('/\\Anode-v[0-9]+(?:\\.[0-9]+)*-linux-x64\\z/', \$entry)",
            'pmssAiToolsNodeTreeRemove($oldDir)',
            'pmssAiToolsNodeTreeNeedsRefresh($nodeDir)',
            'pmssAiToolsNodeTreeRemove($nodeDir)',
            '!is_executable($nodeBinary)',
            "runStep('Extracting pinned Node.js runtime for AI CLI tools', 'umask 022 && '.pmssBuildCommand('tar', ['--no-same-owner', '--no-same-permissions'",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/aiToolsInstall.php', [
            "runStep('Extracting Codex CLI archive', 'umask 022 && '.pmssBuildCommand('tar', ['--no-same-owner', '--no-same-permissions'",
            "preg_match('/\\Anode-v[0-9]+(?:\\.[0-9]+)*-linux-x64\\z/', \$entry)",
            'pmssAiToolsNodeTreeRemove($oldDir)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/arr.php', [
            'umask 022 && tar --no-same-owner --no-same-permissions -xzf %s -C %s',
        ]);
    }

    public function testAiToolsNodeTreeOwnershipAndLinkSafeRemoval(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ai-node-');
        $tree = $root.'/node-v22.22.1-linux-x64';
        $outside = $root.'/outside';
        $this->assertTrue(@mkdir($tree.'/bin', 0700, true));
        $this->assertTrue(@mkdir($outside));
        $this->assertSame(1, @file_put_contents($tree.'/bin/node', 'n'));
        $this->assertSame(1, @file_put_contents($outside.'/keep', 'x'));
        $this->assertTrue(@symlink($outside, $tree.'/bin/outside-link'));

        $uid = @fileowner($tree);
        $this->assertTrue(is_int($uid));
        $this->assertFalse(\pmssAiToolsNodeTreeNeedsRefresh($tree, $uid));
        $this->assertTrue(\pmssAiToolsNodeTreeNeedsRefresh($tree, $uid + 1));
        $this->assertTrue(\pmssAiToolsNodeTreeNeedsRefresh($tree.'/bin/outside-link', $uid));
        $this->assertTrue(\pmssAiToolsNodeTreeRemove($tree));
        $this->assertFalse(file_exists($tree));
        $this->assertSame([], glob($root.'/.pmss-remove-*'));
        $this->assertTrue(is_file($outside.'/keep'));
        $this->assertTrue(\pmssAiToolsNodeTreeRemove($tree));

        $stale = $root.'/.pmss-remove-0123456789abcdef';
        $this->assertTrue(@mkdir($stale.'/tree/bin', 0700, true));
        $this->assertSame(1, @file_put_contents($stale.'/tree/bin/node', 'n'));
        $this->assertTrue(\pmssAiToolsNodeTreeRemove($stale));
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
            "slirp4netns-'.\$arch",
        ]);
    }

    public function testDockerSlirpTemporaryFileCleanupIsObservable(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/docker.php', [
            "function pmssDockerSlirpTemporaryPath(bool \$dryRun = false, ?callable \$logger = null): string",
            "return sys_get_temp_dir().'/pmss-slirp4netns-dry-run';",
            "@tempnam(sys_get_temp_dir(), 'pmss-slirp4netns-')",
            'unable to allocate temporary slirp4netns download path.',
            "\$dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');",
            '$downloadPath = pmssDockerSlirpTemporaryPath($dryRun);',
            "\$downloadPath !== ''",
            "runStep('[docker] Docker: downloading slirp4netns helper",
            '$downloadRc === 0 && ($dryRun || is_file($downloadPath))',
            "runStep('[docker] Docker: installing slirp4netns helper'",
            "!\$dryRun && file_exists(\$downloadPath) && !@unlink(\$downloadPath)",
            'unable to remove temporary slirp4netns helper from download directory.',
        ]);
    }

    public function testDockerSlirpInstallIsSkippedAfterFailedDownload(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/docker.php', [
            '$downloadRc = runStep',
            '$downloadRc === 0 && ($dryRun || is_file($downloadPath))',
            'slirp4netns download failed; skipping helper install.',
            "runStep('[docker] Docker: creating iptables symlink'",
        ]);
    }
}
