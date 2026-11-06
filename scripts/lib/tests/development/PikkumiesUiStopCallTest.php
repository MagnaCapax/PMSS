<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * The Pikkumies launcher has no `ui stop` verb: words it does not route become a one-shot prompt, which starts a
 * Hermes build (and an agent run where a key exists) in the account. The panel password change (also run by
 * addUser) and the panel logout used to call it; pikkumiesHermesUi.php stop-simplified does the stopping.
 */
class PikkumiesUiStopCallTest extends TestCase
{
    public function testPasswordChangeAndLogoutNeverPassUiStopToTheLauncher(): void
    {
        foreach (['scripts/changePw.php', 'etc/skel/www/panelSessionPage.php'] as $file) {
            $source = $this->pmssReadRepoFile($file);
            $this->pmssAssertStringNotContainsString('ui stop', $source, $file.' passes `ui stop` to the Pikkumies launcher');
            $this->assertStringContainsAllStrings(['pikkumiesHermesUi.php', 'stop-simplified', 'rotate-session'], $source);
        }
    }
}
