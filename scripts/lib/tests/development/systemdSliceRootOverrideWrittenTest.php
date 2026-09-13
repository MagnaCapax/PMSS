<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceRootOverrideWrittenTest extends TestCase
{
    public function testRootUnlimitedDropinCreated(): void
    {
        $base = $this->pmssMakeTempDir('pmss-cg-basedir-');
        $drop = $base.'/user-.slice.d';
        @mkdir($drop, 0755, true);

        $fixture = $this->pmssSystemdSliceFixturePrepare([
            'dropDir' => $drop,
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'totalMemMiB' => 1024,
        ]);

        $this->pmssSystemdSliceEnsure($fixture);

        $rootDrop = $base.'/user-0.slice.d/99-zz-pmss-unlimited.conf';
        $this->pmssAssertFileContainsAllStrings($rootDrop, [
            'TasksMax=infinity',
            'MemoryHigh=infinity',
            'MemoryMax=infinity',
        ], 'Root override missing');
    }

    public function testLegacyRootDropinRemovedOnlyAfterReplacementSucceeds(): void
    {
        // Real filesystem failures keep this test independent of writer internals.
        foreach (['missing', 'regular', 'directory', 'symlink', 'dangling'] as $kind) {
            $base = $this->pmssMakeTempDir('pmss-root-slice-migration-');
            $drop = $base.'/user-.slice.d';
            $rootDir = $base.'/user-0.slice.d';
            mkdir($drop, 0755);
            mkdir($rootDir, 0755);
            $legacy = $rootDir.'/99-pmss-unlimited.conf';
            $target = $rootDir.'/99-zz-pmss-unlimited.conf';
            $body = "[Slice]\nMemoryHigh=infinity\nMemoryMax=infinity\nTasksMax=infinity\n";
            file_put_contents($legacy, $body);
            $referent = $base.'/referent';
            if ($kind === 'regular') {
                file_put_contents($target, 'old replacement');
            } elseif ($kind === 'directory') {
                mkdir($target, 0755);
            } elseif ($kind === 'symlink' || $kind === 'dangling') {
                if ($kind === 'symlink') {
                    file_put_contents($referent, 'preserved referent');
                }
                symlink($referent, $target);
            }
            $fixture = $this->pmssSystemdSliceFixturePrepare([
                'dropDir' => $drop,
                'mode' => 'v1',
                'v1Template' => $this->pmssSystemdSliceTasksTemplate(),
                'env' => ['PMSS_SYSTEMD_USER_AT_SERVICE_DIR' => $base.'/user@.service.d'],
            ]);

            // Both success and failure must remain stable when the updater reruns.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->pmssSystemdSliceEnsure($fixture);
                if ($kind === 'missing' || $kind === 'regular') {
                    $this->assertFalse(file_exists($legacy), $kind);
                    $this->assertSame($body, file_get_contents($target), $kind);
                    $this->assertSame(0644, fileperms($target) & 0777, $kind);
                } else {
                    $this->assertSame($body, file_get_contents($legacy), $kind);
                    $this->assertTrue($kind === 'directory' ? is_dir($target) : is_link($target), $kind);
                }
                if ($kind === 'symlink') {
                    $this->assertSame('preserved referent', file_get_contents($referent));
                } elseif ($kind === 'dangling') {
                    $this->assertFalse(file_exists($referent));
                }
            }
        }
    }
}
