<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * A Transmission start from the panel runs under php-cgi. When lighttpd restarts, the php-cgi process
 * group is terminated, so a foreground daemon that stayed in it died with the panel. The launcher's
 * setsid prefix puts the daemon in its own session.
 */
final class TransmissionLaunchSessionTest extends TestCase
{
    public function testSetsidLaunchedBackgroundProcessLeavesTheCallerSession(): void
    {
        foreach (['plain' => 'nohup sleep 5', 'setsid' => 'nohup /usr/bin/setsid sleep 5'] as $label => $prefix) {
            $pid = (int) trim((string) shell_exec($prefix.' >/dev/null 2>&1 </dev/null & echo $!'));
            $this->assertTrue($pid > 1, $label);
            usleep(200000);
            $stat = (string) @file_get_contents('/proc/'.$pid.'/stat');
            $fields = explode(' ', trim(substr($stat, (int) strrpos($stat, ')') + 2)));
            $session = (int) ($fields[3] ?? 0);
            posix_kill($pid, SIGKILL);
            $this->assertTrue($session > 0, $label.': session read from /proc');
            if ($label === 'setsid') $this->assertSame($pid, $session, 'setsid daemon leads its own session');
            else $this->assertSame(posix_getsid(0), $session, 'plain background child stays in the caller session');
        }
    }
}
