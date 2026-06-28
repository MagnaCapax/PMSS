<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/KernelHardeningTestCase.php';
require_once dirname(__DIR__, 2).'/update/kernelHardening.php';

class KernelHardeningSshKeysignTest extends KernelHardeningTestCase
{
    public function testStripsSuidWhenPresent(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sshkeysign-strip-');
        $target = $dir.'/ssh-keysign';
        file_put_contents($target, '#!/bin/sh'.PHP_EOL);
        chmod($target, 04755);
        clearstatcache(true, $target);
        if ((fileperms($target) & 04000) === 0) {
            throw new SkipTest('filesystem rejects SUID bit on regular files (nosuid mount); cannot exercise strip path');
        }
        $logs = [];

        $this->pmssRunKernelHardeningSshKeysign(['PMSS_SSH_KEYSIGN_PATH' => $target], $logs);

        clearstatcache(true, $target);
        $this->assertEquals(0755, fileperms($target) & 07777, 'expected SUID bit cleared');
        $this->assertTrue($this->pmssMessagesContain($logs, 'Stripped SUID from '.$target), 'expected strip log');
    }

    public function testSkipsWhenAlreadyNonSuid(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sshkeysign-already-');
        $target = $dir.'/ssh-keysign';
        file_put_contents($target, '#!/bin/sh'.PHP_EOL);
        chmod($target, 0755);
        $logs = [];

        $this->pmssRunKernelHardeningSshKeysign(['PMSS_SSH_KEYSIGN_PATH' => $target], $logs);

        $this->assertEquals(0755, fileperms($target) & 07777, 'expected mode unchanged');
        $this->assertTrue($this->pmssMessagesContain($logs, 'ssh-keysign already non-SUID'), 'expected skip log');
    }

    public function testSkipsWhenBinaryAbsent(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sshkeysign-missing-');
        $target = $dir.'/ssh-keysign-not-there';
        $logs = [];

        $this->pmssRunKernelHardeningSshKeysign(['PMSS_SSH_KEYSIGN_PATH' => $target], $logs);

        $this->assertTrue($this->pmssMessagesContain($logs, 'ssh-keysign not installed'), 'expected absence log');
    }

    public function testDryRunSkipsMutation(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sshkeysign-dry-');
        $target = $dir.'/ssh-keysign';
        file_put_contents($target, '#!/bin/sh'.PHP_EOL);
        chmod($target, 04755);
        $logs = [];

        $this->pmssRunKernelHardeningSshKeysign(['PMSS_SSH_KEYSIGN_PATH' => $target, 'PMSS_DRY_RUN' => '1'], $logs);

        $this->assertEquals(04755, fileperms($target) & 07777, 'expected SUID preserved in dry-run');
        $this->assertTrue($this->pmssMessagesContain($logs, 'ssh-keysign SUID strip: skipping mutation'), 'expected dry-run log');
    }

}
