<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/webCertFlags.php';

final class WebCertFlagsTest extends TestCase
{
    /** Use the current account so the real account-path runner executes the shell. */
    public function testFlagWriteAndClearRunAsAccount(): void
    {
        $user = posix_getpwuid(posix_geteuid())['name'];
        $home = $this->pmssMakeTempDir('pmss-cert-flags-');
        $flag = $home.'/.request-web-certs';
        $failed = $flag.'.failed';
        $this->assertFalse(\pmssWebCertFlagWrite($user, $home, '../outside', 'bad'));
        $this->assertTrue(\pmssWebCertFlagWrite($user, $home, '.request-web-certs', "default\n"));
        $this->assertSame("default\n", file_get_contents($flag));
        $this->assertSame(0644, fileperms($flag) & 0777);
        $this->assertTrue(\pmssWebCertFlagWrite($user, $home, '.request-web-certs.failed', "failed\n"));
        $this->assertSame("failed\n", file_get_contents($failed));
        $this->assertTrue(\pmssWebCertFlagsClear($user, $home));
        $this->assertFalse(file_exists($flag));
        $this->assertFalse(file_exists($failed));
        $this->assertTrue(\pmssWebCertFlagsClear($user, $home));
    }
}
