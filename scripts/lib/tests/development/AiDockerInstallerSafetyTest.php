<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AiDockerInstallerSafetyTest extends TestCase
{
    public function testCodexFallbackConfigWriteIsChecked(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/aiToolsInstall.php', [
            'function pmssAiToolsCodexFallbackConfigEnsure(bool $dryRun, ?callable $logger = null): void',
            "is_link(\$path) || file_exists(\$path)",
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
