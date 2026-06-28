<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapStep2ExitCodeTest extends TestCase
{
    public function testUpdateStep2SignalDetectionRejectsExit255(): void
    {
        $this->pmssAssertRepoFileContract('scripts/update.php', [
            'required' => [
                '$rc >= 129 && $rc <= 192',
                "'exit_class' => \$details['exit_class']",
                'update_step2_exit_code',
                'general error / unhandled exception',
            ],
            'forbidden' => ['$rc >= 128 && $rc <= 255'],
        ]);
    }
}
