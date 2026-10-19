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
require_once __DIR__.'/aiToolsCodex.php';
require_once __DIR__.'/aiToolsClaude.php';
require_once __DIR__.'/aiToolsGemini.php';
require_once __DIR__.'/aiToolsNodeRuntime.php';

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

$logger = 'logmsg';
$force = pmssEnvFlagEnabled('PMSS_FORCE_AI_TOOLS_REFRESH');
$dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
$architecture = php_uname('m');
$supportsPinnedArtifacts = pmssPinnedRemoteAmd64ArtifactsSupported($architecture);
$installRoot = '/opt/pmss/ai-tools';
$nodeDir = $installRoot.'/node-v22.22.1-linux-x64';

if (!$dryRun && is_dir($installRoot)) {
    foreach (@scandir($installRoot) ?: [] as $entry) {
        if (preg_match('/\A\.pmss-remove-[0-9a-f]{16}\z/', $entry) === 1) {
            $staleDir = $installRoot.'/'.$entry;
            $logger('[INFO] Removing staged AI tools Node.js runtime: '.$staleDir);
            if (!pmssAiToolsNodeTreeRemove($staleDir)) {
                $logger('[WARN] Unable to remove staged AI tools Node.js runtime: '.$staleDir);
            }
            continue;
        }
        if (preg_match('/\Anode-v[0-9]+(?:\.[0-9]+)*-linux-x64\z/', $entry) !== 1) {
            continue;
        }
        $oldDir = $installRoot.'/'.$entry;
        if ($oldDir !== $nodeDir) {
            $logger('[INFO] Removing unused AI tools Node.js runtime: '.$oldDir);
            if (!pmssAiToolsNodeTreeRemove($oldDir)) {
                $logger('[WARN] Unable to remove unused AI tools Node.js runtime: '.$oldDir);
            }
        }
    }
}

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
        $nodeBinary   = $nodeDir.'/bin/node';
        $downloadUrl  = 'https://nodejs.org/dist/v'.$nodeVersion.'/'.$nodeArchive;

        $nodeTreeReady = true;
        if (!$dryRun && pmssAiToolsNodeTreeNeedsRefresh($nodeDir)) {
            $logger('[INFO] Refreshing AI tools Node.js runtime with non-root-owned entries: '.$nodeDir);
            $nodeTreeReady = pmssAiToolsNodeTreeRemove($nodeDir);
            if (!$nodeTreeReady) {
                $logger('[WARN] Unable to remove AI tools Node.js runtime; skipping its installation');
            }
        }
        if (!$nodeTreeReady) {
            $nodeBinary = '';
        } elseif (!is_executable($nodeBinary)) {
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

                    // Keep shared binaries readable under a restrictive updater umask.
                    runStep('Extracting pinned Node.js runtime for AI CLI tools', 'umask 022 && '.pmssBuildCommand('tar', ['--no-same-owner', '--no-same-permissions', '-xJf', $downloadPath, '-C', $installRoot]));
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
        ['Gemini CLI', '@google/gemini-cli', '', 'gemini'],
        ['Claude Code', '@anthropic-ai/claude-code', '2.1.112', 'claude'],
    ] as $toolSpec) {
        $installTarget = $toolSpec[1].($toolSpec[2] === '' ? '' : '@'.$toolSpec[2]);
        $prefixDir = '/opt/pmss/ai-tools/npm/'.$toolSpec[3];
        $binaryPath = $prefixDir.'/bin/'.$toolSpec[3];
        $installNeeded = !is_file($binaryPath) || $force;
        if (!$installNeeded) {
            if ($toolSpec[2] !== '') {
                // Versioned npm tools must converge after upstream publishes a bad latest.
                $packageJsonPath = $prefixDir.'/lib/node_modules/'.$toolSpec[1].'/package.json';
                $packageJson = pmssJsonDecodeAssoc((string) @file_get_contents($packageJsonPath));
                $installNeeded = !is_array($packageJson) || (string) ($packageJson['version'] ?? '') !== $toolSpec[2];
            }
        }

        if ($installNeeded) {
            if ($npmBinary === '') {
                $logger('[WARN] Skipping '.$toolSpec[0].' install: npm not available');
                continue;
            }
            runStep('Ensuring '.$toolSpec[0].' install prefix exists', pmssBuildCommand('mkdir', ['-p', $prefixDir]));
            if (runStep('Installing '.$toolSpec[0], pmssBuildCommand($npmBinary, ['install', '--prefix', $prefixDir, '-g', '--no-audit', '--no-fund', $installTarget])) !== 0) {
                continue;
            }
        }
        if ($dryRun || !is_file($binaryPath) || !is_executable($binaryPath)) {
            continue;
        }
        $launcherSource = __DIR__.'/templates/pmss-ai-launch.php';
        $launcher = '/opt/pmss/ai-tools/pmss-ai-launch.php';
        $gemini = $toolSpec[3] === 'gemini';
        $stub = '/usr/local/bin/'.$toolSpec[3];
        $defaults = '/opt/pmss/ai-tools/gemini-virsilipas-defaults.json';
        $needed = $gemini
            ? pmssAiToolsGeminiInstallNeeded($launcher, $stub, $launcherSource, $defaults)
            : pmssAiToolsClaudeInstallNeeded($launcher, $stub, $launcherSource);
        if ($needed) {
            if ($gemini && runStep('Ensuring Gemini defaults directory is root-owned',
                pmssBuildCommand('install', ['-d', '-o', 'root', '-g', 'root', '-m', '0755', '/opt/pmss/ai-tools'])) !== 0) {
                $logger('[WARN] Unable to prepare Gemini defaults directory; keeping existing command');
                continue;
            }
            $content = @file_get_contents($launcherSource);
            if (!is_string($content) || !pmssAiToolsCodexAtomicWrite($launcher, $content, 0644)) {
                $logger('[WARN] Unable to install '.$toolSpec[0].' launcher; keeping existing command');
            } elseif ($gemini && !pmssAiToolsCodexAtomicWrite($defaults, pmssAiToolsGeminiDefaults(), 0644)) {
                $logger('[WARN] Unable to install Gemini defaults; keeping existing command');
            } elseif (!pmssAiToolsCodexAtomicWrite($stub, $gemini ? pmssAiToolsGeminiStub() : pmssAiToolsClaudeStub(), 0755)) {
                $logger('[WARN] Unable to install '.$toolSpec[0].' command stub; keeping existing command');
            }
        }
    }
}

$codexRoot = '/opt/pmss/ai-tools/codex';
$destination = $codexRoot.'/codex-'.PMSS_AI_CODEX_TAG;
$launcherSource = __DIR__.'/templates/pmss-ai-launch.php';
$launcher = '/opt/pmss/ai-tools/pmss-ai-launch.php';
$stub = '/usr/local/bin/codex';
if (pmssAiToolsCodexInstallNeeded($destination, $launcher, $stub, $launcherSource, $force)) {
    if (!$supportsPinnedArtifacts) {
        $logger('[WARN] Skipping Codex install: no pinned binary for this CPU architecture');
    } elseif (!in_array(PMSS_AI_CODEX_TAG, PMSS_AI_CODEX_CHAT_TAGS, true)) {
        $logger('[WARN] Skipping Codex install: pinned tag lacks a live Virsilipas chat check');
    } else {
        $tag         = PMSS_AI_CODEX_TAG;
        $archive     = 'codex-x86_64-unknown-linux-musl.tar.gz';
        $sha256      = '3574eef71b062c17904b0761c397a97709ef28e99c616e2d1db261b2ea293d07';
        $url         = 'https://github.com/openai/codex/releases/download/'.$tag.'/'.$archive;
        $binaryReady = is_file($destination) && is_executable($destination) && !is_link($destination) && !$force;
        if (!$binaryReady) {
            runStep('Ensuring Codex binary directory exists', pmssBuildCommand('install', ['-d', '-o', 'root', '-g', 'root', '-m', '0755', $codexRoot]));
            $downloadDir = $dryRun ? '' : pmssCreatePrivateTempDir('pmss-ai-codex-');
            if ($dryRun || $downloadDir !== null) {
                try {
                    $binaryReady = pmssPinnedRemoteTempFileUse(
                        'Codex CLI archive', $url, $sha256, 'pmss-ai-codex-',
                        'Downloading pinned Codex CLI archive',
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
                        }
                    ) === true;
                } finally {
                    if ($downloadDir !== '') {
                        @unlink($downloadDir.'/codex-x86_64-unknown-linux-musl');
                        @rmdir($downloadDir);
                    }
                }
            }
        }
        if ($binaryReady && !$dryRun) {
            $content = @file_get_contents($launcherSource);
            if (!is_string($content) || !pmssAiToolsCodexAtomicWrite($launcher, $content, 0644)) {
                $logger('[WARN] Unable to install Codex launcher; keeping existing command');
            } elseif (!pmssAiToolsCodexAtomicWrite($stub, pmssAiToolsCodexStub(), 0755)) {
                $logger('[WARN] Unable to install Codex command stub; keeping existing command');
            }
        }
        // Landlock sandbox requires kernel 5.13+; keep old kernels usable.
        if ($binaryReady && preg_match('/^([0-9]+)\.([0-9]+)/', php_uname('r'), $kernel) && (((int) $kernel[1] < 5) || ((int) $kernel[1] === 5 && (int) $kernel[2] < 13))) {
            runStep('Ensuring /etc/codex exists', pmssBuildCommand('mkdir', ['-p', '/etc/codex']));
            pmssAiToolsCodexFallbackConfigEnsure($dryRun);
        }
    }
}
