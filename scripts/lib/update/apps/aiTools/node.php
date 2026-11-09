<?php
/** Node runtime selection and obsolete-tree cleanup. @license GPL-3.0-only */

function pmssAiToolsNodeRuntimeResolve(bool $dryRun, bool $supportsPinnedArtifacts, callable $logger): string
{
    $installRoot = '/opt/pmss/ai-tools';
    $nodeVersion = '22.23.4';
    $nodeDir = $installRoot.'/node-v'.$nodeVersion.'-linux-x64';
    if (!$dryRun && is_dir($installRoot)) {
        foreach (pmssDirectoryEntriesRead($installRoot) ?: [] as $entry) {
            $oldDir = $installRoot.'/'.$entry;
            // Both obsolete tree classes share removal, retaining their distinct diagnostics.
            if (preg_match('/\A\.pmss-remove-[0-9a-f]{16}\z/', $entry) === 1) {
                $kind = 'staged';
            } elseif (preg_match('/\Anode-v[0-9]+(?:\.[0-9]+)*-linux-x64\z/', $entry) === 1 && $oldDir !== $nodeDir) {
                $kind = 'unused';
            } else {
                continue;
            }
            $logger('[INFO] Removing '.$kind.' AI tools Node.js runtime: '.$oldDir);
            if (!pmssRootOwnedTreeRemove($oldDir)) {
                $logger('[WARN] Unable to remove '.$kind.' AI tools Node.js runtime: '.$oldDir);
            }
        }
    }

    $systemNode = pmssCommandPath('node');
    if ($systemNode !== '' && (int) (pmssAppVersionProbeMatch([escapeshellarg($systemNode).' --version 2>/dev/null'], '/^v?([0-9]+)/', 1) ?? 0) >= 22) {
        return $systemNode;
    }
    if (!$supportsPinnedArtifacts) {
        $logger('[WARN] Skipping Gemini/Claude install: no pinned Node.js artifact for this CPU architecture');
        return '';
    }

    $nodeArchive = basename($nodeDir).'.tar.xz';
    $nodeSha256 = '1805bcd35e6815559bd2eb97db438d3fdb0eb3a5e6a977b7c1badca76337e9ce';
    $nodeBinary = $nodeDir.'/bin/node';
    $downloadUrl = 'https://nodejs.org/dist/v'.$nodeVersion.'/'.$nodeArchive;
    if (!$dryRun && pmssRootOwnedTreeNeedsRefresh($nodeDir)) {
        $logger('[INFO] Refreshing AI tools Node.js runtime with non-root-owned entries: '.$nodeDir);
        if (!pmssRootOwnedTreeRemove($nodeDir)) {
            $logger('[WARN] Unable to remove AI tools Node.js runtime; skipping its installation');
            return '';
        }
    }
    if (is_executable($nodeBinary)) return $nodeBinary;

    runStep('Ensuring AI tools install root exists', pmssBuildCommand('mkdir', ['-p', $installRoot]));
    $resolvedNodeBinary = pmssPinnedRemoteArtifactTempFileUse(
        'Node.js runtime for AI CLI tools', $downloadUrl, $nodeSha256,
        static function (string $downloadPath) use ($dryRun, $installRoot, $nodeBinary): string {
            // Dry-run retains the selected path for the subsequent npm availability checks.
            if ($dryRun) return $nodeBinary;
            // Keep shared binaries readable under a restrictive updater umask.
            runStep('Extracting pinned Node.js runtime for AI CLI tools', 'umask 022 && '.pmssBuildCommand('tar', ['--no-same-owner', '--no-same-permissions', '-xJf', $downloadPath, '-C', $installRoot]));
            return is_executable($nodeBinary) ? $nodeBinary : '';
        },
        true,
        ['tempPrefix' => 'pmss-ai-node-', 'downloadDescription' => 'Downloading pinned Node.js runtime for AI CLI tools']
    );
    return is_string($resolvedNodeBinary) ? $resolvedNodeBinary : '';
}
