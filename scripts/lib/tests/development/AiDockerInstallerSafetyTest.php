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
            "runStep('[docker] Docker: installing slirp4netns helper'",
            "file_exists('slirp4netns') && !@unlink('slirp4netns')",
            'unable to remove temporary slirp4netns helper from working directory.',
        ]);
    }
}
