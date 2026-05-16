<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2NginxShutdownGuardTest extends TestCase
{
    public function testUpdateStep2ShutdownGuardKeepsDirectNginxStartFallback(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            'function pmssUpdateStep2StartNginxShutdownFallback(string $reason): void',
            "'event' => 'post_update_nginx_start_fallback'",
            "passthru('systemctl start nginx 2>/dev/null || /etc/init.d/nginx start 2>/dev/null', \$rc);",
            "pmssUpdateStep2StartNginxShutdownFallback('create_nginx_config_missing');",
            "pmssUpdateStep2StartNginxShutdownFallback('web_refresh_rescue_failed');",
        ]);
    }
}
