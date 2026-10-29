<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

/**
 * The failed-unbound purge also covers a unit stuck in its Restart=on-failure loop,
 * which never reports "failed" (Debian 10 installs from the old baseline).
 */
class UnboundPurgeDecisionTest extends TestCase
{
    public function testFailingStatesArePurged(): void
    {
        foreach ([
            "ActiveState=failed\nNRestarts=0\nResult=exit-code\n",
            "ActiveState=activating\nNRestarts=25\nResult=exit-code\n",
            "Result=exit-code\nNRestarts=3\nActiveState=inactive\n",
        ] as $show) {
            $this->assertTrue(\pmssUnboundShowIndicatesFailure($show), $show);
        }
    }

    public function testHealthyOrAbsentUnitsAreKept(): void
    {
        foreach ([
            "ActiveState=active\nNRestarts=0\nResult=success\n",
            "ActiveState=active\nNRestarts=2\nResult=success\n",
            "ActiveState=inactive\nNRestarts=0\nResult=success\n",
            "ActiveState=activating\nNRestarts=0\nResult=success\n",
            "ActiveState=inactive\nNRestarts=4\nResult=success\n",
            "",
        ] as $show) {
            $this->assertTrue(!\pmssUnboundShowIndicatesFailure($show), $show === '' ? '(empty: not installed or no systemd)' : $show);
        }
    }
}
