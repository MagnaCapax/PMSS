<?php
/**
 * Update app installer: system-wide AI CLI tools.
 *
 * Installs Gemini CLI, Codex CLI, and Claude Code under `/opt/pmss/ai-tools`
 * and links commands into `/usr/local/bin` so all users can run them without
 * consuming per-user quota. Credentials remain user-scoped in home directories.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/remoteBinary.php';

/** Write the Codex fallback config only when the target is a regular file path. */
function pmssAiToolsCodexFallbackConfigEnsure(bool $dryRun, ?callable $logger = null): void
{
    $log = $logger ?: 'logmsg';
    $path = '/etc/codex/config.toml';

    if ($dryRun || is_file($path)) {
        return;
    }
    if (is_link($path) || file_exists($path)) {
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

$logger = 'logmsg';
$force = pmssEnvFlagEnabled('PMSS_FORCE_AI_TOOLS_REFRESH');
$dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
$architecture = php_uname('m');
$supportsPinnedArtifacts = pmssPinnedRemoteAmd64ArtifactsSupported($architecture);

$nodeBinary = '';
$systemNode = pmssCommandPath('node');
if ($systemNode !== '') {
    if ((int) (pmssAppVersionProbeMatch([escapeshellarg($systemNode).' --version 2>/dev/null'], '/^v?([0-9]+)/', 1) ?? 0) >= 22) {
        $nodeBinary = $systemNode;
    }
}

if ($nodeBinary === '') {
    if (!$supportsPinnedArtifacts) {
        $logger('[WARN] Skipping Gemini/Claude install: no pinned Node.js artifact for this CPU architecture');
    } else {
        $nodeVersion  = '22.22.1';
        $nodeArchive  = 'node-v22.22.1-linux-x64.tar.xz';
        $nodeSha256   = '9a6bc82f9b491279147219f6a18add1e18424dce90d41d2a5fcd69d4924ba3aa';
        $installRoot  = '/opt/pmss/ai-tools';
        $nodeDir      = $installRoot.'/node-v22.22.1-linux-x64';
        $nodeBinary   = $nodeDir.'/bin/node';
        $downloadUrl  = 'https://nodejs.org/dist/v'.$nodeVersion.'/'.$nodeArchive;

        if (!is_executable($nodeBinary)) {
            runStep('Ensuring AI tools install root exists', pmssBuildCommand('mkdir', ['-p', $installRoot]));
            $resolvedNodeBinary = pmssPinnedRemoteTempFileUse(
                'Node.js runtime for AI CLI tools',
                $downloadUrl,
                $nodeSha256,
                'pmss-ai-node-',
                'Downloading pinned Node.js runtime for AI CLI tools',
                static function (string $downloadPath) use ($dryRun, $installRoot, $nodeBinary): string {
                    // Dry-run keeps the historical path: later npm checks
                    // still report what would be missing without extracting.
                    if ($dryRun) {
                        return $nodeBinary;
                    }

                    runStep('Extracting pinned Node.js runtime for AI CLI tools', pmssBuildCommand('tar', ['-xJf', $downloadPath, '-C', $installRoot]));
                    return is_executable($nodeBinary) ? $nodeBinary : '';
                }
            );

            $nodeBinary = is_string($resolvedNodeBinary) ? $resolvedNodeBinary : '';
        }
    }
}

if ($nodeBinary !== '') {
    $npmBinary = dirname($nodeBinary).'/npm';
    if (!is_executable($npmBinary)) {
        $npmBinary = pmssCommandPath('npm');
    }

    // npm launches child entrypoints via `/usr/bin/env node`, so keep the
    // selected Node.js directory on PATH even when npm itself runs by full path.
    $pathValue = getenv('PATH');
    putenv('PATH='.dirname($nodeBinary).($pathValue === false || $pathValue === '' ? '' : ':'.$pathValue));

    foreach ([
        ['Gemini CLI', '@google/gemini-cli', 'gemini'],
        ['Claude Code', '@anthropic-ai/claude-code', 'claude'],
    ] as $toolSpec) {
        if ($npmBinary === '') {
            $logger('[WARN] Skipping '.$toolSpec[0].' install: npm not available');
            continue;
        }

        $prefixDir = '/opt/pmss/ai-tools/npm/'.$toolSpec[2];
        $binaryPath = $prefixDir.'/bin/'.$toolSpec[2];
        if (is_file($binaryPath) && !$force) {
            continue;
        }

        runStep('Ensuring '.$toolSpec[0].' install prefix exists', pmssBuildCommand('mkdir', ['-p', $prefixDir]));
        runStep('Installing '.$toolSpec[0], pmssBuildCommand($npmBinary, ['install', '--prefix', $prefixDir, '-g', '--no-audit', '--no-fund', $toolSpec[1]]));
        runStep('Linking '.$toolSpec[0].' command', pmssBuildCommand('ln', ['-sf', $binaryPath, '/usr/local/bin/'.$toolSpec[2]]));
    }
}

$destination = '/usr/local/bin/codex';
if (!is_file($destination) || $force) {
    if (!$supportsPinnedArtifacts) {
        $logger('[WARN] Skipping Codex install: no pinned binary for this CPU architecture');
    } else {
        $tag         = 'rust-v0.93.0';
        $archive     = 'codex-x86_64-unknown-linux-musl.tar.gz';
        $sha256      = '3574eef71b062c17904b0761c397a97709ef28e99c616e2d1db261b2ea293d07';
        $url         = 'https://github.com/openai/codex/releases/download/'.$tag.'/'.$archive;
        $downloadDir = sys_get_temp_dir().'/pmss-ai-tools-codex';

        runStep('Preparing Codex download directory', pmssBuildCommand('mkdir', ['-p', $downloadDir]));
        pmssPinnedRemoteTempFileUse(
            'Codex CLI archive',
            $url,
            $sha256,
            'pmss-ai-codex-',
            'Downloading pinned Codex CLI archive',
            static function (string $archivePath) use ($downloadDir, $destination, $dryRun): void {
                runStep('Extracting Codex CLI archive', pmssBuildCommand('tar', ['-xzf', $archivePath, '-C', $downloadDir]));
                runStep('Installing Codex CLI binary', pmssBuildCommand('install', ['-m', '0755', $downloadDir.'/codex-x86_64-unknown-linux-musl', $destination]));

                // Landlock sandbox requires kernel 5.13+; keep old kernels usable.
                if (preg_match('/^([0-9]+)\.([0-9]+)/', php_uname('r'), $kernel) && (((int) $kernel[1] < 5) || ((int) $kernel[1] === 5 && (int) $kernel[2] < 13))) {
                    runStep('Ensuring /etc/codex exists', pmssBuildCommand('mkdir', ['-p', '/etc/codex']));
                    pmssAiToolsCodexFallbackConfigEnsure($dryRun);
                }
            }
        );
    }
}
