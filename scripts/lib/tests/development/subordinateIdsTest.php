<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/subordinateIds.php';

/** Hermetic tests for subordinate owner ID selection. */
class SubordinateIdsTest extends TestCase
{
    /** @var array<int, string> */
    private $paths = [];

    public function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PMSS_SUBUID_PATH', 'PMSS_SUBGID_PATH']);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function fixture(string $kind, string $contents): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pmss-subordinate-');
        if ($path === false) {
            $this->fail('Could not create subordinate ID fixture');
        }
        $this->paths[] = $path;
        file_put_contents($path, $contents);
        putenv(($kind === 'uid' ? 'PMSS_SUBUID_PATH' : 'PMSS_SUBGID_PATH').'='.$path);
    }

    public function testMatchesUsernameAndNumericPrimaryId(): void
    {
        $this->fixture('uid', "alice:100000:65536\n1001:200000:4096\nbob:300000:1024\n");
        $this->assertSame([[100000, 65536], [200000, 4096]], \pmssSubordinateIdRanges('alice', 1001, 'uid'));
    }

    public function testMultipleRangesAndSeparateGroupFile(): void
    {
        $this->fixture('gid', "alice:100000:100\nalice:200000:200\n");
        $this->fixture('uid', "alice:300000:300\n");
        $this->assertSame([[100000, 100], [200000, 200]], \pmssSubordinateIdRanges('alice', 1001, 'gid'));
        $this->assertSame([[300000, 300]], \pmssSubordinateIdRanges('alice', 1001, 'uid'));
    }

    public function testRejectsInvalidRanges(): void
    {
        $this->fixture('uid', "alice:99999:1\nalice:100000:0\nalice:abc:10\nalice:100000:2x\nalice:999999999999999999999999:1\nalice:100000:999999999999999999999999\nalice:100000:1\n");
        $this->assertSame([[100000, 1]], \pmssSubordinateIdRanges('alice', 1001, 'uid'));
    }

    public function testMissingFileReturnsNoRanges(): void
    {
        putenv('PMSS_SUBUID_PATH='.sys_get_temp_dir().'/pmss-subordinate-missing-'.bin2hex(random_bytes(6)));
        $this->assertSame([], \pmssSubordinateIdRanges('alice', 1001, 'uid'));
    }

    public function testEmptyRangePredicateKeepsLegacyTest(): void
    {
        $this->assertSame('-uid 1001', \pmssOwnerIdSetFindPredicate('-uid', 1001, []));
        $this->assertSame('-gid 1002', \pmssOwnerIdSetFindPredicate('-gid', 1002, []));
    }

    public function testOneRangePredicate(): void
    {
        $this->assertSame('\\( -uid 1001 -o \\( -uid +99999 -uid -165536 \\) \\)',
            \pmssOwnerIdSetFindPredicate('-uid', 1001, [[100000, 65536]]));
    }

    public function testTwoRangePredicate(): void
    {
        $this->assertSame('\\( -gid 1002 -o \\( -gid +99999 -gid -100010 \\) -o \\( -gid +199999 -gid -200020 \\) \\)',
            \pmssOwnerIdSetFindPredicate('-gid', 1002, [[100000, 10], [200000, 20]]));
    }
}
