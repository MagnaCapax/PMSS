<?php
/** npm tool convergence and launcher publication. @license GPL-3.0-only */

function pmssAiToolsNpmInstall(string $nodeBinary, bool $force, bool $dryRun, callable $logger): void
{
    if ($nodeBinary === '') return;
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
        if (!$installNeeded && $toolSpec[2] !== '') {
            // Versioned npm tools must converge after upstream publishes a bad latest.
            $packageJsonPath = $prefixDir.'/lib/node_modules/'.$toolSpec[1].'/package.json';
            $packageJson = pmssJsonDecodeAssoc((string) @file_get_contents($packageJsonPath));
            $installNeeded = !is_array($packageJson) || (string) ($packageJson['version'] ?? '') !== $toolSpec[2];
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
        $launcherSource = dirname(__DIR__).'/templates/pmss-ai-launch.php';
        $launcher = '/opt/pmss/ai-tools/pmss-ai-launch.php';
        $gemini = $toolSpec[3] === 'gemini';
        $stub = '/usr/local/bin/'.$toolSpec[3];
        $defaults = '/opt/pmss/ai-tools/gemini-virsilipas-defaults.json';
        if (!($gemini ? pmssAiToolsGeminiInstallNeeded($launcher, $stub, $launcherSource, $defaults)
            : pmssAiToolsClaudeInstallNeeded($launcher, $stub, $launcherSource))) continue;
        if ($gemini && runStep('Ensuring Gemini defaults directory is root-owned',
            pmssBuildCommand('install', ['-d', '-o', 'root', '-g', 'root', '-m', '0755', '/opt/pmss/ai-tools'])) !== 0) {
            $logger('[WARN] Unable to prepare Gemini defaults directory; keeping existing command');
            continue;
        }
        $files = [[$launcher, @file_get_contents($launcherSource), 0644, 'Unable to install '.$toolSpec[0].' launcher']];
        if ($gemini) $files[] = [$defaults, pmssAiToolsGeminiDefaults(), 0644, 'Unable to install Gemini defaults'];
        $files[] = [$stub, $gemini ? pmssAiToolsGeminiStub() : pmssAiToolsClaudeStub(), 0755, 'Unable to install '.$toolSpec[0].' command stub'];
        pmssAiToolsFilesPublish($files, $logger);
    }
}
