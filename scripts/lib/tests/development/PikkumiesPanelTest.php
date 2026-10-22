<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/pikkumiesPanel.php';

final class PikkumiesPanelTest extends TestCase
{
    /** A synthetic customer tree keeps eligibility checks off the real host. */
    private function fixture()
    {
        $home = $this->pmssMakeTempDir('pmss-pikkumies-panel-');
        mkdir($home.'/billing');
        mkdir($home.'/.mcxPikkumies/pikkumies/bin', 0700, true);
        mkdir($home.'/.mcxPikkumies/pikkumies/local');
        file_put_contents($home.'/marker', '');
        file_put_contents($home.'/billing/alice', "123\n");
        $binary = $home.'/.mcxPikkumies/pikkumies/bin/pikkumies';
        file_put_contents($binary, "#!/bin/sh\n");
        chmod($binary, 0700);
        return $home;
    }

    private function state($home, $name = 'alice')
    {
        return \pmssPikkumiesPanelStateRead($home, $name, $home.'/marker', $home.'/billing', posix_geteuid());
    }

    public function testEligibleAccountHasServiceOriginAndSimplifiedDefault(): void
    {
        $home = $this->fixture();
        $state = $this->state($home);
        $this->assertTrue($state['visible']);
        $this->assertSame('simplified', $state['mode']);
        $this->assertSame('https://'.substr(hash('sha256', 'mcx.fi:service:123'), 0, 16).'.mcx.fi:844', $state['origin']);
    }

    public function testMissingMarkerOrBillingHidesTheTab(): void
    {
        $home = $this->fixture();
        unlink($home.'/marker');
        $this->assertFalse($this->state($home)['visible']);
        file_put_contents($home.'/marker', '');
        unlink($home.'/billing/alice');
        $this->assertSame('', $this->state($home)['origin']);
    }

    public function testInvalidBillingAndIdentityAreRejected(): void
    {
        $home = $this->fixture();
        file_put_contents($home.'/billing/alice', "0\n");
        $this->assertFalse($this->state($home)['visible']);
        file_put_contents($home.'/billing/alice', "123\n");
        $this->assertFalse($this->state($home, '../alice')['visible']);
        $this->assertSame('', \pmssPikkumiesPanelStateRead($home, 'alice', $home.'/marker', $home.'/billing', -1)['origin']);
    }

    public function testOffHidesTabButKeepsConfigureState(): void
    {
        $home = $this->fixture();
        $marker = $home.'/.mcxPikkumies/pikkumies/local/eternals.off';
        file_put_contents($marker, '');
        $state = $this->state($home);
        $this->assertFalse($state['visible']);
        $this->assertTrue($state['off']);
        $this->assertTrue($state['origin'] !== '');
        unlink($marker);
        symlink($home.'/missing', $marker);
        $this->assertFalse($this->state($home)['visible']);
    }

    public function testOnlyTheKnownPreferenceEnablesPowerUser(): void
    {
        $home = $this->fixture();
        mkdir($home.'/.config/pmss/ui', 0700, true);
        $path = $home.'/.config/pmss/ui/pikkumies-interface';
        file_put_contents($path, "power-user\n");
        $this->assertSame('power-user', $this->state($home)['mode']);
        file_put_contents($path, "unknown\n");
        $this->assertSame('simplified', $this->state($home)['mode']);
    }

    public function testPanelCopyAndProtocolAreBounded(): void
    {
        $index = $this->pmssReadRepoFile('etc/skel/www/index.php');
        $welcome = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $configure = $this->pmssReadRepoFile('etc/skel/www/configure.php');
        $launcher = $this->pmssReadRepoFile('etc/skel/www/pikkumiesOpen.php');
        $this->assertStringContainsString('pikkumies:rows:[1-6]', $index);
        $this->assertStringContainsString('}, 12000)', $index);
        $this->assertStringContainsString('}, 30000)', $index);
        $this->assertStringContainsString('if (!state.visible) location.reload()', $index);
        $this->assertStringContainsString('pikkumies:switch', $welcome);
        $this->assertStringContainsString('title = \'Ask Pikkumies\'', $welcome);
        $this->assertStringContainsString('Your network may be blocking Pikkumies.', $index);
        $this->assertStringContainsString('Power user (opens full Hermes in its own tab)', $configure);
        $this->assertStringContainsString('Signing in to one Hermes window signs you out of the other.', $configure);
        // Both Hermes sign-in forms ask for a user name too: the account name, shown next to each password reveal.
        $this->assertSame(2, substr_count($configure, 'password</button><span class="pmss-hermes-user">User name: <b><?= pmssCustomerHtmlAttr($username) ?></b></span>'));
        $this->assertStringContainsString('pmssPikkumiesOpenStatusEmit(true, $certificateState', $launcher);
        foreach (array('Retrying in N min', 'Turn it on to use it', 'Your browser cannot reach Pikkumies') as $removed) {
            $this->assertFalse(strpos($index.$welcome.$configure.$launcher, $removed) !== false);
        }
    }
}
