<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/motd/Generator.php';

class MotdTest extends TestCase
{
    public function testGenerateMotdWritesToOutputWithTemplate(): void
    {
        $dir = sys_get_temp_dir().'/pmss-motd-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        $template = $dir.'/template.motd';
        $output   = $dir.'/motd.txt';
        $runtime  = $dir.'/run';
        @mkdir($runtime, 0700, true);

        file_put_contents($template, "Host: %HOSTNAME%\nVersion: %PMSS_VERSION%\n");

        putenv('PMSS_MOTD_TEMPLATE_PATH='.$template);
        putenv('PMSS_MOTD_OUTPUT_PATH='.$output);
        putenv('PMSS_RUNTIME_DIR='.$runtime);

        \Motd::motdGenerate();

        $this->assertTrue(file_exists($output), 'MOTD output file missing');
        $content = (string)file_get_contents($output);
        $this->assertTrue(strpos($content, '%HOSTNAME%') === false, 'HOSTNAME placeholder should be replaced');
        $this->assertTrue(strpos($content, '%PMSS_VERSION%') === false, 'PMSS_VERSION placeholder should be replaced');
    }

    public function testUpdateStep2CallsGenerateMotdNearEnd(): void
    {
        $path = dirname(__DIR__, 3).'/util/update-step2.php';
        $this->assertTrue(file_exists($path), 'update-step2.php missing');
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines) && count($lines) > 0, 'unable to read update-step2.php');
        $lastIdx = -1;
        foreach ($lines as $i => $line) {
            if (strpos($line, 'Motd::motdGenerate(') !== false) {
                $lastIdx = $i;
            }
        }
        $this->assertTrue($lastIdx >= 0, 'Motd::motdGenerate() not referenced in update-step2.php');
        $total = count($lines);
        // Expect the last call to appear within the last 50 lines of the script.
        $this->assertTrue(($total - $lastIdx) <= 50, 'Motd::motdGenerate() should be near the end of update-step2.php');
    }
}
