<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userProcHeldBlocks.php';

class UserProcHeldBlocksTest extends TestCase
{
    private function makeProcFixture(array $pids): string
    {
        $procRoot = $this->pmssMakeTempDir('pmss-held-proc-');
        foreach ($pids as $pid => $uid) {
            $pidRoot = $procRoot.'/'.$pid;
            mkdir($pidRoot.'/fd', 0777, true);
            file_put_contents($pidRoot.'/status', "Uid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\n");
            file_put_contents($pidRoot.'/fd/3', '');
        }
        return $procRoot;
    }

    private function statReader(array $stats): callable
    {
        return static function (string $path) use ($stats) {
            return $stats[basename(dirname(dirname($path))).'/'.basename($path)] ?? false;
        };
    }

    public function testAggregatesOnlyDeletedAccountBlocksAndDeduplicatesInodes(): void
    {
        $procRoot = $this->makeProcFixture([12345 => 1500, 12346 => 1500, 12347 => 1600]);
        file_put_contents($procRoot.'/12345/fd/4', '');
        file_put_contents($procRoot.'/12346/fd/4', '');
        file_put_contents($procRoot.'/12347/fd/4', '');
        $stats = [
            '12345/3' => ['dev' => 7, 'ino' => 10, 'nlink' => 0, 'uid' => 1500, 'blocks' => 8],
            '12345/4' => ['dev' => 7, 'ino' => 10, 'nlink' => 0, 'uid' => 1500, 'blocks' => 8],
            '12346/3' => ['dev' => 7, 'ino' => 11, 'nlink' => 1, 'uid' => 1500, 'blocks' => 16],
            '12346/4' => ['dev' => 8, 'ino' => 12, 'nlink' => 0, 'uid' => 1500, 'blocks' => 32],
            '12347/3' => ['dev' => 7, 'ino' => 13, 'nlink' => 0, 'uid' => 1600, 'blocks' => 64],
            '12347/4' => ['dev' => 7, 'ino' => 14, 'nlink' => 0, 'uid' => 1500, 'blocks' => 64],
        ];

        $this->assertSame(8, pmssUserProcHeldBlocks(1500, 7, $procRoot, $this->statReader($stats)));
    }

    public function testReturnsNullWhenNoAccountProcessExists(): void
    {
        $procRoot = $this->makeProcFixture([12345 => 1600]);
        $this->assertSame(null, pmssUserProcHeldBlocks(1500, 7, $procRoot, static function (): array {
            return [];
        }));
    }

    public function testReturnsNullWhenDescriptorStatIsUnreadable(): void
    {
        $procRoot = $this->makeProcFixture([12345 => 1500]);
        $this->assertSame(null, pmssUserProcHeldBlocks(1500, 7, $procRoot, static function (): bool {
            return false;
        }));
    }

    public function testReturnsNullWhenDescriptorMetadataIsIncomplete(): void
    {
        $procRoot = $this->makeProcFixture([12345 => 1500]);
        $this->assertSame(null, pmssUserProcHeldBlocks(1500, 7, $procRoot, static function (): array {
            return ['dev' => 7, 'ino' => 10, 'nlink' => 0];
        }));
    }
}
