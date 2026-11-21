<?php
/** Web-root streamed file installation and full-tree publication. @license GPL-3.0-only */

/** Install a copied file without replacing an entry created concurrently. */
function pmssUserWebRootReconcileInstallFile(
    string $source,
    string $target,
    int $mode,
    bool $noReplace,
    ?array &$stats = null,
    ?string $user = null,
    string $home = ''
): bool
{
    if ($user === null) return false;
    // The account cannot read the root-only skeleton; send trusted bytes over stdin.
    $content = pmssReadRegularFileContents($source);
    if ($content === null) return false;
    $template = dirname($target).'/.pmss-web-root-XXXXXXXX';
    $targetArg = escapeshellarg($target);
    $command = 'tmp=$(mktemp -- '.escapeshellarg($template).') || exit 1; '
        .'trap \'rm -f -- "$tmp"\' EXIT; '
        .'cat > "$tmp" && test "$(wc -c < "$tmp")" -eq '.strlen($content)
        .' && chmod '.sprintf('%04o', $mode).' -- "$tmp"'
        .' && test ! -L '.$targetArg
        .' && '.($noReplace ? 'ln -- "$tmp" '.$targetArg : 'mv -T -- "$tmp" '.$targetArg);
    $installed = pmssUserWebRootMigrationRun($user, $home, [$target], $command, $content);
    if ($installed && $stats !== null) {
        $stats['files_restored'] = (int) ($stats['files_restored'] ?? 0) + 1;
    }
    return $installed;
}

/** Restore a complete tree only when the current root contains no entries. */
function pmssUserWebRootReconcileFull(
    string $www,
    string $skeleton,
    string $home,
    string $user,
    ?callable $logger,
    ?array &$stats = null
): bool
{
    try {
        $stage = $home.'/.pmss-web-root-reconcile-'.bin2hex(random_bytes(6));
    } catch (\Throwable $exception) {
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to create web-root staging name');
        return false;
    }
    if (!pmssPathAbsoluteStringIsSafe($stage, ['allowRoot' => false])
        || !pmssUserWebRootMigrationRun($user, $home, [$stage],
            'mkdir -m 0700 -- '.escapeshellarg($stage))) {
        return false;
    }

    $success = false;
    try {
        if (pmssUserWebRootReconcileTreeIsSafe($skeleton, $home, false)
            && pmssUserWebRootReconcileCopyEntry($skeleton, $stage, $home, '', $stats, $user)
            && pmssUserWebRootReconcileTreeIsSafe($stage, $home)
            && pmssUserWebRootReconcileApplyOwnership($stage, $user)) {
            $entries = pmssDirectoryEntriesRead($www);
            $currentRootIsSafe = !pmssPathExistsOrLink($www) || (!is_link($www) && $entries === []);
            $success = $currentRootIsSafe
                && pmssUserWebRootMigrationRenameAsAccount($user, $home, $stage, $www);
        }
    } finally {
        // Until publication succeeds, ordinary failures and faults own the stage.
        if (!$success) pmssUserWebRootMigrationRemoveCopy($user, $home, $stage);
    }
    pmssUserWebRootMigrationLog($user, $logger, $success
        ? 'Restored complete web root from skeleton' : 'Full web-root restore refused or failed');
    return $success;
}
