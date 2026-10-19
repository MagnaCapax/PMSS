<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/accountViewGenerator.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/accountView.php';

class AccountViewTest extends TestCase
{
    private function fixture(): array
    {
        $root = $this->pmssMakeTempDir('pmss-account-view-', 0700);
        $etc = $root.'/sources';
        mkdir($etc);
        file_put_contents($etc.'/passwd', "root:x:0:0:root:/root:/bin/sh\nalice:x:1000:1000::/home/alice:/bin/bash\nbob:x:1001:1001::/home/bob:/bin/bash\nnobody:x:65534:65534::/nonexistent:/usr/sbin/nologin\n");
        file_put_contents($etc.'/group', "root:x:0:root\naccounts:x:1000:alice,bob\nbob:x:1001:bob\nwheel:x:10:alice,bob\n");
        file_put_contents($etc.'/subuid', "alice:100000:65536\nbob:165536:65536\n");
        file_put_contents($etc.'/subgid', "alice:100000:65536\nbob:165536:65536\n");
        foreach (['passwd', 'group', 'subuid', 'subgid'] as $file) copy($etc.'/'.$file, $etc.'/'.$file.'-');
        return [$root, $etc];
    }

    public function testFilterKeepsOwnAndSystemRecords(): void
    {
        [$root, $etc] = $this->fixture();
        $view = $root.'/views/1000';
        $this->assertTrue(pmssAccountViewGenerate('alice', null, $etc, $root.'/views'));
        $passwd = file_get_contents($view.'/passwd');
        $this->assertStringContainsString('alice:x:1000:', $passwd);
        $this->assertStringContainsString('nobody:x:65534:', $passwd);
        $this->assertStringNotContainsString('bob:x:1001:', $passwd);
        $group = file_get_contents($view.'/group');
        $this->assertStringContainsString('accounts:x:1000:alice', $group);
        $this->assertStringNotContainsString('alice,bob', $group);
        $this->assertStringNotContainsString('bob:x:1001:', $group);
        $this->assertSame("alice:100000:65536\n", file_get_contents($view.'/subuid'));
        $this->assertSame("alice:100000:65536\n", file_get_contents($view.'/subgid-'));
    }

    public function testViewsKeepOwnerModesAndSourceMtime(): void
    {
        [$root, $etc] = $this->fixture();
        $this->assertTrue(pmssAccountViewGenerate(null, 1000, $etc, $root.'/views'));
        $view = $root.'/views/1000';
        clearstatcache();
        $this->assertSame(0700, fileperms($view) & 0777);
        $this->assertSame(0700, fileperms($root.'/views') & 0777);
        $this->assertSame(0644, fileperms($view.'/passwd') & 0777);
        $this->assertSame(fileowner($root), fileowner($view.'/passwd'));
        $this->assertSame(filemtime($etc.'/passwd'), filemtime($view.'/passwd'));
        $this->assertSame(trim((string) shell_exec('/usr/bin/stat -c %y -- '.escapeshellarg($etc.'/passwd'))),
            trim((string) shell_exec('/usr/bin/stat -c %y -- '.escapeshellarg($view.'/passwd'))));
        $this->assertTrue(pmssAccountViewGenerate(null, 1000, $etc, $root.'/views'));
    }

    public function testUnknownOrSystemIdentityRefused(): void
    {
        [$root, $etc] = $this->fixture();
        $this->assertFalse(pmssAccountViewGenerate('missing', null, $etc, $root.'/views'));
        $this->assertFalse(pmssAccountViewGenerate('root', null, $etc, $root.'/views'));
        $this->assertFalse(pmssAccountViewGenerate(null, 999, $etc, $root.'/views'));
    }

    public function testChangedSourceKeepsItsMtimeInView(): void
    {
        [$root, $etc] = $this->fixture();
        touch($etc.'/passwd', time() - 300);
        $this->assertTrue(pmssAccountViewGenerate('alice', null, $etc, $root.'/views'));
        $this->assertSame(filemtime($etc.'/passwd'), filemtime($root.'/views/1000/passwd'));
    }

    public function testArtifactRulesAndReadOnlyBinds(): void
    {
        $artifacts = pmssAccountViewArtifacts();
        $profile = $artifacts['/usr/share/pam-configs/pmss-account-view'][0];
        foreach (pmssAccountViewSessionRules() as $rule) $this->assertStringContainsString(substr($rule, 8), $profile);
        $this->assertStringNotContainsString('session session', $profile);
        $dropin = $artifacts['/etc/systemd/system/user@.service.d/31-pmss-account-view.conf'][0];
        $this->assertStringContainsString('BindReadOnlyPaths=-/var/lib/pmss/account-view/%i/passwd:/etc/passwd', $dropin);
        $this->assertStringContainsString('ExecStartPre=-+', $dropin);
        $this->assertSame(file_get_contents(dirname(__DIR__, 2).'/update/systemPrep/accountViewGenerator.php'),
            $artifacts['/usr/local/libexec/pmss-account-view.php'][0]);
        $init = $artifacts['/etc/security/namespace.d/pmss-account-view.init'][0];
        $this->assertStringContainsString('/bin/mount -o remount,bind,ro', $init);
    }

    public function testApplierIsInertThenInstallsAndRemoves(): void
    {
        $root = $this->pmssMakeTempDir('pmss-account-apply-', 0700);
        $log = static function (): void {};
        $this->assertTrue(pmssAccountViewApply($log, $root, true));
        $this->assertFalse(is_dir($root.'/etc/pmss-session'));
        mkdir($root.'/etc/seedbox/config', 0755, true);
        file_put_contents($root.'/etc/seedbox/config/account-view.enabled', '');
        $this->assertTrue(pmssAccountViewApply($log, $root, true));
        $this->assertTrue(pmssAccountViewApply($log, $root, true));
        $this->assertTrue(is_file($root.'/usr/share/pam-configs/pmss-account-view'));
        mkdir($root.'/var/lib/pmss/account-view/1000', 0700, true);
        file_put_contents($root.'/var/lib/pmss/account-view/1000/passwd', 'sample');
        unlink($root.'/etc/seedbox/config/account-view.enabled');
        $this->assertTrue(pmssAccountViewApply($log, $root, true));
        $this->assertFalse(is_file($root.'/usr/share/pam-configs/pmss-account-view'));
        $this->assertFalse(is_file($root.'/etc/security/namespace.d/pmss-account-view.init'));
        $this->assertFalse(is_dir($root.'/var/lib/pmss/account-view'));
        $this->assertTrue(pmssAccountViewApply($log, $root, true));
    }

    public function testApplierRefusesUnsupportedMountLayout(): void
    {
        $root = $this->pmssMakeTempDir('pmss-account-mount-', 0700);
        mkdir($root.'/etc/seedbox/config', 0755, true);
        file_put_contents($root.'/etc/seedbox/config/account-view.enabled', '');
        $this->assertFalse(pmssAccountViewApply(static function (): void {}, $root, false));
        $this->assertFalse(is_file($root.'/usr/share/pam-configs/pmss-account-view'));
    }

    public function testPamRenderRequiresBothStacksInOrder(): void
    {
        $root = $this->pmssMakeTempDir('pmss-account-pam-', 0700);
        mkdir($root.'/etc/pam.d', 0755, true);
        $rules = implode("\n", pmssAccountViewSessionRules())."\n";
        file_put_contents($root.'/etc/pam.d/common-session', $rules);
        $this->assertFalse(pmssAccountViewPamRendered($root));
        file_put_contents($root.'/etc/pam.d/common-session-noninteractive', $rules);
        $this->assertTrue(pmssAccountViewPamRendered($root));
        file_put_contents($root.'/etc/pam.d/common-session-noninteractive', implode("\n", array_reverse(pmssAccountViewSessionRules())));
        $this->assertFalse(pmssAccountViewPamRendered($root));
    }
}
