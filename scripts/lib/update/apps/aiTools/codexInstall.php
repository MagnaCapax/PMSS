<?php
/** Pinned Codex installation and old-kernel fallback. @license GPL-3.0-only */

/** Write the Codex fallback config only when the target is a regular file path. */
function pmssAiToolsCodexFallbackConfigEnsure(bool $dryRun, ?callable $logger = null): void
{
    $log = $logger ?: 'logmsg';
    $path = '/etc/codex/config.toml';

    if ($dryRun || is_file($path)) {
        return;
    }
    if (pmssPathExistsOrLink($path)) {
        $log('[WARN] Refusing to write Codex fallback config: unsafe target exists.');
        return;
    }

    $content = "# PMSS compatibility fallback for kernels without Landlock support.\n"."sandbox = \"danger-full-access\"\n";
    if (@file_put_contents($path, $content) === false) {
        $log('[WARN] Unable to write Codex fallback config.');
        return;
    }
    if (!@chmod($path, 0644)) {
        $log('[WARN] Unable to set Codex fallback config mode.');
    }
}

/** Installation failures return only after private download and staging files are cleaned. */
function pmssAiToolsCodexInstall(bool $force, bool $dryRun, bool $supportsPinnedArtifacts, callable $logger): void
{
    $codexRoot = '/opt/pmss/ai-tools/codex';
    $destination = $codexRoot.'/codex-'.PMSS_AI_CODEX_TAG;
    $launcherSource = dirname(__DIR__).'/templates/pmss-ai-launch.php';
    $launcher = '/opt/pmss/ai-tools/pmss-ai-launch.php';
    $stub = '/usr/local/bin/codex';
    if (!pmssAiToolsCodexInstallNeeded($destination, $launcher, $stub, $launcherSource, $force)) return;
    $skip = !$supportsPinnedArtifacts ? 'no pinned binary for this CPU architecture'
        : (!in_array(PMSS_AI_CODEX_TAG, PMSS_AI_CODEX_CHAT_TAGS, true) ? 'pinned tag lacks a live Virsilipas chat check' : null);
    if ($skip !== null) {
        $logger('[WARN] Skipping Codex install: '.$skip);
        return;
    }
    $archive     = 'codex-x86_64-unknown-linux-musl.tar.gz';
    $sha256      = '3574eef71b062c17904b0761c397a97709ef28e99c616e2d1db261b2ea293d07';
    $url         = 'https://github.com/openai/codex/releases/download/'.PMSS_AI_CODEX_TAG.'/'.$archive;
    if (!(is_file($destination) && is_executable($destination) && !is_link($destination) && !$force)) {
        runStep('Ensuring Codex binary directory exists', pmssBuildCommand('install', ['-d', '-o', 'root', '-g', 'root', '-m', '0755', $codexRoot]));
        $downloadDir = $dryRun ? '' : pmssCreatePrivateTempDir('pmss-ai-codex-');
        if ($downloadDir === null) return;
        try {
            if (pmssPinnedRemoteArtifactTempFileUse(
                'Codex CLI archive', $url, $sha256,
                static function (string $archivePath) use ($downloadDir, $destination, $codexRoot, $dryRun): bool {
                    if (runStep('Extracting Codex CLI archive', 'umask 022 && '.pmssBuildCommand('tar', ['--no-same-owner', '--no-same-permissions', '-xzf', $archivePath, '-C', $downloadDir ?: '/tmp'])) !== 0) {
                        return false;
                    }
                    if ($dryRun) {
                        return true;
                    }
                    $temporary = @tempnam($codexRoot, '.pmss-codex-');
                    if (!is_string($temporary)) {
                        return false;
                    }
                    try {
                        if (dirname($temporary) !== $codexRoot
                            || runStep('Installing Codex CLI binary', pmssBuildCommand('install', ['-o', 'root', '-g', 'root', '-m', '0755', $downloadDir.'/codex-x86_64-unknown-linux-musl', $temporary])) !== 0) {
                            return false;
                        }
                        return @rename($temporary, $destination);
                    } finally {
                        if (is_file($temporary)) {
                            @unlink($temporary);
                        }
                    }
                },
                true,
                ['tempPrefix' => 'pmss-ai-codex-', 'downloadDescription' => 'Downloading pinned Codex CLI archive']
            ) !== true) return;
        } finally {
            if ($downloadDir !== '') {
                try {
                    @unlink($downloadDir.'/codex-x86_64-unknown-linux-musl');
                } finally {
                    // A file cleanup throwable must not skip the empty-directory attempt.
                    @rmdir($downloadDir);
                }
            }
        }
    }
    if (!$dryRun) {
        pmssAiToolsFilesPublish([
            [$launcher, @file_get_contents($launcherSource), 0644, 'Unable to install Codex launcher'],
            [$stub, pmssAiToolsCodexStub(), 0755, 'Unable to install Codex command stub'],
        ], $logger);
    }
    // Landlock sandbox requires kernel 5.13+; keep old kernels usable.
    if (preg_match('/^([0-9]+)\.([0-9]+)/', php_uname('r'), $kernel) && (((int) $kernel[1] < 5) || ((int) $kernel[1] === 5 && (int) $kernel[2] < 13))) {
        runStep('Ensuring /etc/codex exists', pmssBuildCommand('mkdir', ['-p', '/etc/codex']));
        pmssAiToolsCodexFallbackConfigEnsure($dryRun);
    }
}
