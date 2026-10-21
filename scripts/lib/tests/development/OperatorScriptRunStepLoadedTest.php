<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** A cron or util script that calls runStep() must load the library that defines it. */
final class OperatorScriptRunStepLoadedTest extends TestCase
{
    public function testEveryCronAndUtilRunStepCallerLoadsItsDefinition(): void
    {
        $root = dirname(__DIR__, 4);
        $callers = 0;
        foreach (array_merge(glob($root.'/scripts/cron/*.php') ?: [], glob($root.'/scripts/util/*.php') ?: []) as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/(?<![\w>:$])runStep\s*\(/', $source) !== 1) continue;
            $callers++;
            $this->assertMatches(
                '#update/runtime/commands\.php|update/runtime\.php|lib/update\.php#',
                $source,
                basename($file).' calls runStep() without loading scripts/lib/update/runtime/commands.php'
            );
        }
        $this->assertTrue($callers > 0, 'The scan must find runStep() callers');
    }
}
