<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;

require_once __DIR__.'/../common/TestCase.php';

/** Read-only checks for the two host assumptions behind account UI Eternals. */
final class PikkumiesUiHostTest extends TestCase
{
    public function testAccountCannotBindUiPorts(): void
    {
        $raw = @file_get_contents('/proc/sys/net/ipv4/ip_unprivileged_port_start');
        $this->assertTrue(is_string($raw) && ctype_digit(trim($raw)));
        $this->assertTrue((int) trim($raw) > 846);
    }

    public function testCronOpensLogindSession(): void
    {
        $raw = @file_get_contents('/etc/pam.d/cron');
        $this->assertTrue(is_string($raw));
        $this->assertTrue(preg_match('/^\s*session\s+optional\s+pam_systemd\.so\s*$/m', $raw) === 1);
    }
}
