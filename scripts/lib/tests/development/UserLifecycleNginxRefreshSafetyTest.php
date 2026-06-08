<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class UserLifecycleNginxRefreshSafetyTest extends TestCase
{
    public function testRefreshNginxConfigSkipsRestartAfterFailedRegeneration(): void
    {
        $calls = array();

        $result = \pmssUserLifecycleRefreshNginxConfig(
            'terminate',
            'alice',
            false,
            'regen_nginx_user_configs',
            '/scripts/util/createNginxConfig.php',
            array('systemctlStep' => 'restart_nginx', 'initStep' => 'restart_nginx_init'),
            static function (string $action, string $username, string $step, string $command, bool $dryRun) use (&$calls): int {
                $calls[] = array('action' => $action, 'username' => $username, 'step' => $step, 'command' => $command, 'dryRun' => $dryRun);
                return $step === 'regen_nginx_user_configs' ? 42 : 0;
            }
        );

        $this->assertSame(42, $result);
        $this->assertSame(array('regen_nginx_user_configs'), array_column($calls, 'step'));
        $this->assertSame('alice', $calls[0]['username']);
        $this->assertFalse($calls[0]['dryRun']);
    }
}
