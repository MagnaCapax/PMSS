<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootGuardCliTest extends TestCase
{
    private function makeSystemctlStub(array $responses): string
    {
        $dir = sys_get_temp_dir().'/pmss-stub-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0755, true);
        $bin = $dir.'/systemctl';
        $script = "#!/usr/bin/env bash\nset -e\nif [[ \"$1\" == show && \"$2\" == user-0.slice ]]; then\n  shift 2\n  while [[ $# -gt 1 ]]; do\n    if [[ \"$1\" == -p ]]; then\n      key=\"$2\"\n      case \"\$key\" in\n        MemoryHigh) echo 'MemoryHigh=".$responses['MemoryHigh']."' ;;
        MemoryMax) echo 'MemoryMax=".$responses['MemoryMax']."' ;;
        TasksMax) echo 'TasksMax=".$responses['TasksMax']."' ;;
      esac\n      shift 2\n      continue\n    fi\n    shift\n  done\n  exit 0\nfi\nif [[ \"$1\" == set-property ]]; then exit 0; fi\nexit 0\n";
        file_put_contents($bin, $script);
        @chmod($bin, 0755);
        return $dir;
    }

    private function runCheck(array $responses): string
    {
        $stubPath = $this->makeSystemctlStub($responses);
        $env = 'PATH='.escapeshellarg($stubPath.':'.getenv('PATH'));
        $cmd = $env.' php '.escapeshellarg(getcwd().'/scripts/cron/cgroupRootCheck.php');
        return (string)@shell_exec($cmd.' 2>&1');
    }

    public function testRootAlreadyUnlimited(): void
    {
        $out = $this->runCheck(['MemoryHigh'=>'infinity','MemoryMax'=>'infinity','TasksMax'=>'infinity']);
        $this->assertStringContainsString('[OK] Root slice already unlimited', $out);
    }

    public function testRootNeedsFixing(): void
    {
        $out = $this->runCheck(['MemoryHigh'=>'100M','MemoryMax'=>'200M','TasksMax'=>'512']);
        $this->assertStringContainsString('Unlimiting root user slice', $out);
    }
}
