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
        $this->pmssWriteFiles($home, ['marker' => '', 'billing/fixtur01' => "123\n"], 0777);
        $binary = $home.'/.mcxPikkumies/pikkumies/bin/pikkumies';
        $this->pmssWriteExecutableFile($binary, "#!/bin/sh\n", 0700, 0700);
        $this->pmssEnsureDir($home.'/.mcxPikkumies/pikkumies/local', 0777);
        return $home;
    }

    private function state($home, $name = 'fixtur01')
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
        $this->pmssWriteFile($home.'/marker', '');
        unlink($home.'/billing/fixtur01');
        $this->assertSame('', $this->state($home)['origin']);
    }

    public function testInvalidBillingAndIdentityAreRejected(): void
    {
        $home = $this->fixture();
        $this->pmssWriteFile($home.'/billing/fixtur01', "0\n");
        $this->assertFalse($this->state($home)['visible']);
        $this->pmssWriteFile($home.'/billing/fixtur01', "123\n");
        $this->assertFalse($this->state($home, '../fixtur01')['visible']);
        $this->assertSame('', \pmssPikkumiesPanelStateRead($home, 'fixtur01', $home.'/marker', $home.'/billing', -1)['origin']);
    }

    public function testHermesSectionWorksWithoutMarkerOrBillingButNeedsSafeInstaller(): void
    {
        $home = $this->fixture();
        $this->pmssWriteFile($home.'/bin/install-hermes', "#!/bin/sh\n", 0777);
        $none = array('visible' => false, 'origin' => '', 'mode' => 'simplified', 'off' => false);
        $this->assertTrue(\pmssAgentsSectionVisible($none, $home));
        unlink($home.'/marker');
        $this->assertTrue(\pmssAgentsSectionVisible($none, $home));
        symlink($home.'/billing/fixtur01', $home.'/marker');
        $this->assertTrue(\pmssAgentsSectionVisible($none, $home));
        unlink($home.'/marker');
        $this->pmssWriteFile($home.'/marker', '');
        unlink($home.'/bin/install-hermes');
        $this->assertFalse(\pmssAgentsSectionVisible($none, $home));
        $this->assertTrue(\pmssAgentsSectionVisible($this->state($home), $home));
    }

    public function testOffHidesTabButKeepsConfigureState(): void
    {
        $home = $this->fixture();
        $marker = $home.'/.mcxPikkumies/pikkumies/local/eternals.off';
        $this->pmssWriteFile($marker, '');
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
        $path = $home.'/.config/pmss/ui/pikkumies-interface';
        $this->pmssWriteFile($path, "power-user\n", 0700);
        $this->assertSame('power-user', $this->state($home)['mode']);
        $this->pmssWriteFile($path, "unknown\n");
        $this->assertSame('simplified', $this->state($home)['mode']);
    }

    public function testPanelCopyAndProtocolAreBounded(): void
    {
        $index = $this->pmssReadRepoFile('etc/skel/www/index.php');
        $welcome = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $configure = $this->pmssReadRepoFile('etc/skel/www/configure.php');
        $launcher = $this->pmssReadRepoFile('etc/skel/www/pikkumiesOpen.php');
        $this->assertStringContainsString('pikkumies:rows:[1-6]', $index);
        // The frame is exactly the bar page's box: 52px for one row, +22px per extra row, no border of its own.
        $this->assertStringContainsString("bar.style.height = (52 + 22 * (Number(event.data.slice(-1)) - 1)) + 'px';", $index);
        $this->assertStringContainsString('.pmss-pikkumies-bar iframe{border:0;background:transparent}', $welcome);
        $this->assertFalse(strpos($welcome, 'height:84px') !== false);
        $this->assertStringContainsString('}, 12000)', $index);
        // The network hint waits for the Pikkumies tab to be open (a frame loaded behind another tab cannot report ready)
        // and is never armed for the panel's own launcher page, which is this origin.
        $this->assertStringContainsAllStrings([
            "try { launcher = this.contentWindow.location.origin === location.origin; } catch (e) {}",
            "if (!launcher) pmssPikkumiesHintTimer = setTimeout(function () {",
            "if (!pmssPikkumiesReady && document.getElementById('pikkumies').offsetParent !== null) document.getElementById('pmss-pikkumies-hint').hidden = false;",
            '}, 30000)', 'if (!state.visible) location.reload()',
        ], $index);
        $this->assertStringContainsAllStrings(['pikkumies:switch', 'title = \'Ask Pikkumies\''], $welcome);
        $this->assertStringContainsString('Your network may be blocking Pikkumies.', $index);
        $this->assertStringContainsAllStrings(['>Full Hermes, in its own tab</option>',
            'Only one Hermes window works at a time: opening one closes the other.'], $configure);
        // Both Hermes password reveals sit in one fallback sign-in box that shows the account name once.
        $this->assertStringContainsString('<summary>Sign in to Hermes yourself</summary>', $configure);
        $this->assertSame(1, substr_count($configure, '<span class="pmss-hermes-user">User name: <b><?= pmssCustomerHtmlAttr($username) ?></b></span>'));
        $this->assertStringContainsAllStrings([
            'onclick="pmssPikkumiesHermesShowLogin()">Show Pikkumies Hermes password</button>',
            'onclick="pmssHermesShowLogin()">Show Hermes password</button>',
        ], $configure);
        // The Hermes status poll runs only during an install; an installed Hermes must not reload Configure.
        $this->assertStringNotContainsString('setInterval(pmssHermesStatusPoll', $configure);
        $this->assertStringContainsString("if (state.state === 'installing') pmssHermesPollLater();", $configure);
        $this->assertStringContainsString('pmssPikkumiesOpenStatusEmit(true, $certificateState', $launcher);
        $this->assertStringContainsAndOmitsStrings([], [
            'Retrying in N min', 'Turn it on to use it', 'Your browser cannot reach Pikkumies',
        ], $index.$welcome.$configure.$launcher);
    }
}
