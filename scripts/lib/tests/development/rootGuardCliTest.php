<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootGuardCliTest extends TestCase
{
    private function makeSystemctlStub(array $responses): string
    {
        $script = "#!/usr/bin/env bash\nset -e\nif [[ \"$1\" == show && \"$2\" == user-0.slice ]]; then\n  shift 2\n  while [[ $# -gt 1 ]]; do\n    if [[ \"$1\" == -p ]]; then\n      key=\"$2\"\n      case \"\$key\" in\n        MemoryHigh) echo 'MemoryHigh=".$responses['MemoryHigh']."' ;;
        MemoryMax) echo 'MemoryMax=".$responses['MemoryMax']."' ;;
        TasksMax) echo 'TasksMax=".$responses['TasksMax']."' ;;
      esac\n      shift 2\n      continue\n    fi\n    shift\n  done\n  exit 0\nfi\nif [[ \"$1\" == set-property ]]; then exit 0; fi\nexit 0\n";

        return $this->pmssMakeExecutableStub('systemctl', $script, 'pmss-root-systemctl-');
    }

    private function runCheck(array $responses): string
    {
        $stubPath = $this->makeSystemctlStub($responses);
        return $this->pmssRunRepoPhpScript(
            'scripts/cron/cgroupRootCheck.php',
            [],
            $this->pmssPathPrefixedEnvironment($stubPath)
        );
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
