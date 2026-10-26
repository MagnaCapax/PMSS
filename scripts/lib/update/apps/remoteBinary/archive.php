<?php
/** Archive installation policy; verified download lifetime belongs to artifact.php. */

/** Pair tar names and types only after both caller-supplied listing commands succeed. */
function pmssPinnedRemoteArchiveMembersRead(string $pathsCommand, string $detailsCommand): array
{
    $paths = $details = [];
    $rc = 1;
    exec($pathsCommand, $paths, $rc);
    if ($rc !== 0 || $paths === []) return [];
    exec($detailsCommand, $details, $rc);
    if ($rc !== 0 || count($paths) !== count($details)) return [];
    $members = [];
    foreach ($paths as $index => $path) $members[] = ['path' => $path, 'type' => $details[$index][0] ?? ''];
    return $members;
}

/** Install a marked portable release; tar and zip retain their original extraction policy. */
function pmssPinnedRemotePortableInstall(string $label, string $url, string $sha256, string $root, string $binary, string $version, bool $zipArchive = false): void
{
    if (!pmssPinnedRemoteAmd64OrSkip($label)) return;
    $marker = $root.'/pmss-version';
    if (pmssPinnedRemoteVersionMatches($root.'/'.$binary, $marker, $version)) return;
    pmssPinnedRemoteArtifactTempFileUse($label.' '.$version, $url, $sha256, static function (string $archivePath) use ($label, $root, $binary, $marker, $version, $zipArchive): bool {
        // Zip keeps its root/marker guards; tar also checks the extracted binary and temporary file.
        if (!pmssPathTargetIsSafe($root, true)
            || (!$zipArchive && (!pmssPathTargetIsSafe($root.'/'.$binary, false) || !pmssPathTargetIsSafe($root.'/'.$binary.'.tmp', false)))
            || !pmssPathTargetIsSafe($marker, false)) return false;
        $command = 'mkdir -p '.escapeshellarg($root).($zipArchive
            ? ' && unzip -oq '.escapeshellarg($archivePath).' -d '.escapeshellarg($root)
                .' && chmod 0755 '.escapeshellarg($root.'/'.$binary)
            : ' && tar -xzOf '.escapeshellarg($archivePath).' '.escapeshellarg($binary).' > '.escapeshellarg($root.'/'.$binary.'.tmp')
                .' && install -o root -g root -m 0755 '.escapeshellarg($root.'/'.$binary.'.tmp').' '.escapeshellarg($root.'/'.$binary)
                .' && rm -f '.escapeshellarg($root.'/'.$binary.'.tmp'));
        if (runStep('Installing '.$label.' '.$version, $command) !== 0) return false;
        return pmssPinnedRemoteVersionMarkerWrite($marker, $version);
    });
}

/** Reject archive basenames that could become shell options, path escapes, or log controls. */
function pmssPinnedRemoteArchiveComponentIsSafe(string $component): bool
{
    return $component !== ''
        && $component !== '.'
        && $component !== '..'
        && substr($component, 0, 1) !== '-'
        && strpos($component, '/') === false
        && strpos($component, '\\') === false
        && preg_match('/[\x00-\x1F\x7F]/', $component) !== 1;
}

/** Download a verified archive, unpack it in the compile workspace, then run caller steps. */
function pmssRunPinnedRemoteArchiveStep(string $label, string $url, string $expectedSha256, string $archiveName, string $sourceDir, string $description, array $postExtractCommands, string $workDir = '/root/compile'): bool
{
    $trimmedWorkDir = rtrim($workDir, '/');
    if (!pmssPinnedRemoteArchiveComponentIsSafe($archiveName)
        || (substr($archiveName, -7) !== '.tar.gz' && substr($archiveName, -7) !== '.tar.xz')
        || !pmssPinnedRemoteArchiveComponentIsSafe($sourceDir)
        || $workDir === ''
        || $trimmedWorkDir === ''
        || preg_match('/[\x00-\x1F\x7F]/', $workDir) === 1
        || !pmssPathTargetIsSafe($trimmedWorkDir, true, false, false)
    ) {
        logmsg("[WARN] Refusing unsafe archive extraction path for {$label}");
        return false;
    }
    foreach ($postExtractCommands as $command) {
        // Reject shell fragments that would break the generated command chain.
        if (!is_string($command) || trim($command) === ''
            || strpos($command, "\0") !== false
            || preg_match('/[\r\n]/', $command) === 1
            || strpos($command, ';') !== false
            || strpos($command, '&&') !== false
            || strpos($command, '||') !== false) {
            logmsg("[WARN] Refusing unsafe archive post-extract command for {$label}");
            return false;
        }
    }

    return pmssPinnedRemoteArtifactTempFileUse($label, $url, $expectedSha256, static function (string $archivePath) use ($archiveName, $sourceDir, $description, $postExtractCommands, $workDir): bool {
        $tarMode = substr($archiveName, -7) === '.tar.xz' ? '-xJf' : '-xzf';
        $commands = ['set -e', 'mkdir -p '.escapeshellarg($workDir), 'cd '.escapeshellarg($workDir),
            'rm -rf '.escapeshellarg($sourceDir).' '.escapeshellarg($archiveName),
            'cp '.escapeshellarg($archivePath).' '.escapeshellarg($archiveName), 'tar '.$tarMode.' '.escapeshellarg($archiveName)];
        foreach ($postExtractCommands as $command) {
            $commands[] = $command;
        }
        return runStep($description, implode(' && ', $commands)) === 0;
    }) === true;
}
