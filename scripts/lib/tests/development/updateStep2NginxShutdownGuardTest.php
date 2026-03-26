<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2NginxShutdownGuardTest extends TestCase
{
    public function testUpdateStep2ShutdownGuardKeepsDirectNginxStartFallback(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsString('function pmssUpdateStep2StartNginxShutdownFallback(string $reason): void', $src);
        $this->assertStringContainsString("'event' => 'post_update_nginx_start_fallback'", $src);
        $this->assertStringContainsString("passthru('systemctl start nginx 2>/dev/null || /etc/init.d/nginx start 2>/dev/null', \$rc);", $src);
        $this->assertStringContainsString("pmssUpdateStep2StartNginxShutdownFallback('create_nginx_config_missing');", $src);
        $this->assertStringContainsString("pmssUpdateStep2StartNginxShutdownFallback('web_refresh_rescue_failed');", $src);
    }
}
