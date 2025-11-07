<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/UserChecksum.php';

class UserChecksumTest extends TestCase
{
    public function testChecksumStableForSortedData(): void
    {
        $dataA = ['alice' => ['quota' => 10, 'rtorrentPort' => 5000]];
        $dataB = ['alice' => ['rtorrentPort' => 5000, 'quota' => 10]];
        $this->assertEquals(
            \UserChecksum::checksum($dataA),
            \UserChecksum::checksum($dataB)
        );
    }
}
