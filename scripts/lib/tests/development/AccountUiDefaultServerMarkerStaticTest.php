<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** All accounts use the existing panel listener; release state is written only by update. */
final class AccountUiDefaultServerMarkerStaticTest extends TestCase
{
    public function testPanelReleaseHasNoSeparateListenersMountsOrActivationReaders(): void
    {
        $listeners = $mounts = $readers = [];
        foreach (['scripts', 'etc'] as $directory) {
            foreach ($this->pmssRepoFiles($directory) as $path => $entry) {
                if ($entry->isLink() || strpos($path, 'scripts/lib/tests/') === 0) continue;
                $source = (string) file_get_contents($entry->getPathname());
                if (preg_match('/\blisten\s+(?:\[[^\]]+\]:|[0-9.]+:)?(?:844|845|846)\b/', $source)) $listeners[] = $path;
                if (preg_match('~\^/__pmss-ui-[ab]/~', $source)) $mounts[] = $path;
                if (strpos($source, 'pikkumiesEternals.enabled') !== false
                    && strpos($path, 'scripts/lib/userTransfer/') !== 0 && $path !== 'scripts/lib/update/apps/pikkumiesAgentboard.php') $readers[] = $path;
            }
        }
        $this->assertSame([], $listeners);
        $this->assertSame([], $mounts);
        $this->assertSame([], $readers);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/pikkumiesAgentboard.php', [
            "string \$path = '/etc/seedbox/config/pikkumiesEternals.enabled'",
            "pmssWriteManagedFile(\$path, \"enabled\\n\", 'root', 'root', 0644)",
            "{\n    pmssPikkumiesEternalsReleaseStatePublish();\n", 'Pikkumies Eternals release state published',
        ], ["is_file('/etc/seedbox/config/pikkumiesEternals.enabled')"]);
    }

    public function testAllFormerDefaultFilesAreRemovedAndPagesAreNoLongerInstalled(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/nginxConfig/setupAccountUi.php', [
            'pmss-pikkumies-default.conf', 'pmss-pikkumies-hermes-default.conf', 'pmss-hermes-default.conf',
            'pmssCreateNginxConfigSetupRemoveDefault', "'/var/www/pikkumies'", '@rmdir($directory)',
        ], ['pmssWriteManagedFile', 'pmssPikkumiesUiPagesInstall', 'pikkumiesEternals.enabled']);
    }
}
