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

require_once __DIR__.'/../runtime/commands.php';

/**
 * Install a global npm CLI into a dedicated prefix and link into PATH.
 */
function pmssAiToolsInstallNpmCli(string $toolLabel, string $packageName, string $binaryName, string $nodeBinary, bool $forceRefresh): void
{
    $npmBinary = dirname($nodeBinary).'/npm';
    if (!is_executable($npmBinary)) {
        $npmBinary = trim((string) @shell_exec('command -v npm 2>/dev/null'));
    }
    if ($npmBinary === '') {
        if (function_exists('logmsg')) {
            logmsg('[WARN] Skipping '.$toolLabel.' install: npm not available');
        }
        return;
    }

    $prefixDir  = '/opt/pmss/ai-tools/npm/'.$binaryName;
    $binaryPath = $prefixDir.'/bin/'.$binaryName;
    if (is_file($binaryPath) && !$forceRefresh) {
        return;
    }

    runStep('Ensuring '.$toolLabel.' install prefix exists', 'mkdir -p '.escapeshellarg($prefixDir));
    runStep('Installing '.$toolLabel, sprintf('%s install --prefix %s -g --no-audit --no-fund %s', escapeshellarg($npmBinary), escapeshellarg($prefixDir), escapeshellarg($packageName)));
    runStep('Linking '.$toolLabel.' command', sprintf('ln -sf %s %s', escapeshellarg($binaryPath), escapeshellarg('/usr/local/bin/'.$binaryName)));
}

$logger = function_exists('logmsg') ? 'logmsg' : 'logMessage';
$force  = getenv('PMSS_FORCE_AI_TOOLS_REFRESH') === '1';
$architecture = php_uname('m');

$nodeBinary = '';
$systemNode = trim((string) @shell_exec('command -v node 2>/dev/null'));
if ($systemNode !== '') {
    $systemVersion = trim((string) @shell_exec(escapeshellarg($systemNode).' --version 2>/dev/null'));
    if (preg_match('/^v?([0-9]+)/', $systemVersion, $match) && (int) $match[1] >= 20) {
        $nodeBinary = $systemNode;
    }
}

if ($nodeBinary === '') {
    if (!in_array($architecture, ['x86_64', 'amd64'], true)) {
        $logger('[WARN] Skipping Gemini/Claude install: no pinned Node.js artifact for this CPU architecture');
    } else {
        $nodeVersion  = '20.20.0';
        $nodeArchive  = 'node-v20.20.0-linux-x64.tar.xz';
        $nodeSha256   = '4f48b52acf42130844a3a75e94da0e9629009d09e4101b2304895c24f3fbe609';
        $installRoot  = '/opt/pmss/ai-tools';
        $nodeDir      = $installRoot.'/node-v20.20.0-linux-x64';
        $nodeBinary   = $nodeDir.'/bin/node';
        $downloadPath = sys_get_temp_dir().'/'.$nodeArchive;
        $downloadUrl  = 'https://nodejs.org/dist/v'.$nodeVersion.'/'.$nodeArchive;

        if (!is_executable($nodeBinary)) {
            runStep('Ensuring AI tools install root exists', 'mkdir -p '.escapeshellarg($installRoot));
            runStep('Downloading pinned Node.js runtime for AI CLI tools', sprintf('wget -q -O %s %s', escapeshellarg($downloadPath), escapeshellarg($downloadUrl)));

            if (getenv('PMSS_DRY_RUN') !== '1') {
                $actualSha = @hash_file('sha256', $downloadPath);
                if (!is_string($actualSha) || strtolower($actualSha) !== strtolower($nodeSha256)) {
                    $logger('[WARN] Node.js checksum mismatch; skipping Gemini/Claude installation');
                    $nodeBinary = '';
                } else {
                    runStep('Extracting pinned Node.js runtime for AI CLI tools', sprintf('tar -xJf %s -C %s', escapeshellarg($downloadPath), escapeshellarg($installRoot)));
                    if (!is_executable($nodeBinary)) {
                        $nodeBinary = '';
                    }
                }
            }
        }
    }
}

if ($nodeBinary !== '') {
    pmssAiToolsInstallNpmCli('Gemini CLI', '@google/gemini-cli', 'gemini', $nodeBinary, $force);
    pmssAiToolsInstallNpmCli('Claude Code', '@anthropic-ai/claude-code', 'claude', $nodeBinary, $force);
}

$destination = '/usr/local/bin/codex';
if (!is_file($destination) || $force) {
    if (!in_array($architecture, ['x86_64', 'amd64'], true)) {
        $logger('[WARN] Skipping Codex install: no pinned binary for this CPU architecture');
    } else {
        $tag         = 'rust-v0.93.0';
        $archive     = 'codex-x86_64-unknown-linux-musl.tar.gz';
        $sha256      = '3574eef71b062c17904b0761c397a97709ef28e99c616e2d1db261b2ea293d07';
        $url         = 'https://github.com/openai/codex/releases/download/'.$tag.'/'.$archive;
        $downloadDir = sys_get_temp_dir().'/pmss-ai-tools-codex';
        $archivePath = $downloadDir.'/'.$archive;

        runStep('Preparing Codex download directory', 'mkdir -p '.escapeshellarg($downloadDir));
        runStep('Downloading pinned Codex CLI archive', sprintf('wget -q -O %s %s', escapeshellarg($archivePath), escapeshellarg($url)));

        if (getenv('PMSS_DRY_RUN') !== '1') {
            $actualSha = @hash_file('sha256', $archivePath);
            if (!is_string($actualSha) || strtolower($actualSha) !== strtolower($sha256)) {
                $logger('[WARN] Codex archive checksum mismatch; refusing installation');
            }
        }

        if (getenv('PMSS_DRY_RUN') === '1' || (isset($actualSha) && is_string($actualSha) && strtolower($actualSha) === strtolower($sha256))) {
            runStep('Extracting Codex CLI archive', sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($downloadDir)));
            runStep('Installing Codex CLI binary', sprintf('install -m 0755 %s %s', escapeshellarg($downloadDir.'/codex-x86_64-unknown-linux-musl'), escapeshellarg($destination)));

            // Landlock sandbox requires kernel 5.13+; keep old kernels usable.
            if (preg_match('/^([0-9]+)\.([0-9]+)/', php_uname('r'), $kernel) && (((int) $kernel[1] < 5) || ((int) $kernel[1] === 5 && (int) $kernel[2] < 13))) {
                runStep('Ensuring /etc/codex exists', 'mkdir -p /etc/codex');
                if (getenv('PMSS_DRY_RUN') !== '1' && !is_file('/etc/codex/config.toml')) {
                    @file_put_contents('/etc/codex/config.toml', "# PMSS compatibility fallback for kernels without Landlock support.\n"."sandbox = \"danger-full-access\"\n");
                    @chmod('/etc/codex/config.toml', 0644);
                }
            }
        }
    }
}
