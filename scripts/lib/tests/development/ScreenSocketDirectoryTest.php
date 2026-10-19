<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ScreenSocketDirectoryTest extends TestCase
{
    public function testNewBashSessionUsesAccountDirectory(): void
    {
        if (!function_exists('posix_getuid') || posix_getuid() < 1000) return;
        $home = $this->pmssMakeTempDir('pmss-screen-', 0700);
        $bashrc = dirname(__DIR__, 4).'/etc/skel/.bashrc';
        $command = 'env HOME='.escapeshellarg($home).' BASH_ENV='.escapeshellarg($bashrc)
            .' /bin/bash -c '.escapeshellarg('printf "%s" "$SCREENDIR"');
        $actual = (string) shell_exec($command);
        $this->assertSame($home.'/.screen', $actual);
        $this->assertSame(0700, fileperms($home.'/.screen') & 0777);
        if (!is_executable('/usr/bin/screen')) return;
        $session = 'pmss-test-'.getmypid();
        $script = 'screen -dmS '.$session.' /bin/sleep 10; /bin/sleep 1; /bin/ls -A "$SCREENDIR"; screen -S '.$session.' -X quit';
        $output = (string) shell_exec('env HOME='.escapeshellarg($home).' BASH_ENV='.escapeshellarg($bashrc)
            .' /bin/bash -c '.escapeshellarg($script));
        $this->assertStringContainsString('.'.$session, $output);
    }

    public function testLoginShSessionUsesAccountDirectory(): void
    {
        if (!function_exists('posix_getuid') || posix_getuid() < 1000) return;
        $home = $this->pmssMakeTempDir('pmss-screen-sh-', 0700);
        $profile = dirname(__DIR__, 4).'/etc/skel/.profile';
        $script = '. '.escapeshellarg($profile).'; printf "%s" "$SCREENDIR"';
        $actual = (string) shell_exec('env HOME='.escapeshellarg($home).' /bin/sh -c '.escapeshellarg($script));
        $this->assertSame($home.'/.screen', $actual);
    }
}
