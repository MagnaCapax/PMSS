<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrent/process.php';

class rtorrentProcessStablePidsTest extends TestCase
{
    /**
     * Test stable PID helper succeeds when the same PID survives.
     */
    public function testWaitForStablePidsReturnsTrueWhenSamePidSurvives(): void
    {
        $calls = 0;
        $result = rtorrentProcessWaitForStablePids(function () use (&$calls): array {
            $calls++;
            return $calls === 1 ? [] : [1234];
        }, 0.03, 0.02, 5000);

        $this->assertTrue($result);
    }

    /**
     * Test stable PID helper fails when nothing appears in time.
     */
    public function testWaitForStablePidsReturnsFalseWhenProcessNeverAppears(): void
    {
        $result = rtorrentProcessWaitForStablePids(function (): array {
            return [];
        }, 0.02, 0.01, 5000);

        $this->assertTrue(!$result);
    }

    /**
     * Test stable PID helper fails when the observed PID exits early.
     */
    public function testWaitForStablePidsReturnsFalseWhenPidDisappears(): void
    {
        $calls = 0;
        $result = rtorrentProcessWaitForStablePids(function () use (&$calls): array {
            $calls++;
            return $calls === 1 ? [1234] : [];
        }, 0.0, 0.02, 5000);

        $this->assertTrue(!$result);
    }

    /**
     * Test stable PID helper fails when a different PID replaces the original.
     */
    public function testWaitForStablePidsReturnsFalseWhenPidChanges(): void
    {
        $calls = 0;
        $result = rtorrentProcessWaitForStablePids(function () use (&$calls): array {
            $calls++;
            return $calls === 1 ? [1234] : [5678];
        }, 0.0, 0.02, 5000);

        $this->assertTrue(!$result);
    }

    /**
     * Test stable PID helper ignores invalid PID values.
     */
    public function testWaitForStablePidsIgnoresInvalidPidValues(): void
    {
        $calls = 0;
        $result = rtorrentProcessWaitForStablePids(function () use (&$calls): array {
            $calls++;
            return $calls === 1 ? [0, -5, '1234', '1234'] : ['1234', '0'];
        }, 0.0, 0.02, 5000);

        $this->assertTrue($result);
    }
}
