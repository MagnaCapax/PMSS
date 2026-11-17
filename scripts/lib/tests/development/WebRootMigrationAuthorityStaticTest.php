<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock account authority at each web-root migration and recovery mutation site. */
final class WebRootMigrationAuthorityStaticTest extends TestCase
{
    public function testWebRootDirectoryMovesRunUnderAccount(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/webRootAccount.php', [
            'pmssPathTargetIsSafe($source, true, true)',
            'pmssPathTargetIsSafe($target, true, true)',
            'pmssAccountPathRun($user, $home, $paths, $command, $input)',
            'if ($input !== null) return false;',
            '&& @rename($source, $target)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/webRootMisfile.php', [
            'pmssUserWebRootMigrationRenameAsAccount($user, $home, $temporary, $target)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/webRootMove.php', [
            'pmssUserWebRootMigrationRenameAsAccount($user, $home, $source, $target)',
            'pmssUserWebRootMigrationRenameAsAccount($user, $home, $target, $source)',
            "pmssUserWebRootMigrationRun(\$user, \$home, [\$source],",
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users/webRootReconcile.php', [
            'pmssUserWebRootMigrationRenameAsAccount($user, $home, $stage, $www)',
            'pmssUserWebRootMigrationRun($user, $home, [$target]',
            'pmssReadRegularFileContents($source)',
            'strlen($content)',
        ], ['@rename($stage, $www)']);
    }
}
