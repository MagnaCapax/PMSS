<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/traffic.php';
require_once __DIR__.'/../../user/trafficLimit.php';

class UserTrafficStateHelpersTest extends TestCase
{
    /** @var string */
    private $tempDir;

    public function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-traffic-state-');
    }

    public function tearDown(): void
    {
        $this->pmssRemoveTree($this->tempDir);
    }

    public function testReadUserTrafficMonthReturnsZeroForMissingFile(): void
    {
        $this->assertEquals(0, \pmssReadUserTrafficMonth($this->tempDir.'/missing'));
    }

    public function testReadUserTrafficMonthRejectsSymlinkedFile(): void
    {
        $target = $this->tempDir.'/traffic-data-target';
        file_put_contents($target, serialize(['raw' => ['month' => 2048]]));
        $link = $this->tempDir.'/traffic-data-link';
        symlink($target, $link);

        $this->assertEquals(0, \pmssReadUserTrafficMonth($link));
    }

    public function testReadUserTrafficMonthRejectsInvalidPayload(): void
    {
        $path = $this->tempDir.'/traffic-data-invalid';
        file_put_contents($path, 'not serialized');

        $this->assertEquals(0, \pmssReadUserTrafficMonth($path));
    }

    public function testReadUserTrafficMonthRejectsMissingMonthField(): void
    {
        $path = $this->tempDir.'/traffic-data-missing-month';
        file_put_contents($path, serialize(['raw' => ['week' => 128]]));

        $this->assertEquals(0, \pmssReadUserTrafficMonth($path));
    }

    public function testReadUserTrafficMonthRoundsNumericMonthTotals(): void
    {
        $path = $this->tempDir.'/traffic-data-valid';
        file_put_contents($path, serialize(['raw' => ['month' => 1536.4]]));

        $this->assertEquals(1536, \pmssReadUserTrafficMonth($path));
    }

    public function testTrafficLimitReadGiBFileReturnsZeroForMissingFile(): void
    {
        $this->assertEquals(0, \pmssTrafficLimitReadGiBFile($this->tempDir.'/missing-limit'));
    }

    public function testTrafficLimitReadGiBFileRejectsSymlinkedFile(): void
    {
        $target = $this->tempDir.'/limit-target';
        file_put_contents($target, "500\n");
        $link = $this->tempDir.'/limit-link';
        symlink($target, $link);

        $this->assertEquals(0, \pmssTrafficLimitReadGiBFile($link));
    }

    public function testTrafficLimitReadGiBFileAcceptsPlainIntegerGiB(): void
    {
        $path = $this->tempDir.'/limit-plain';
        file_put_contents($path, "500\n");

        $this->assertEquals(500, \pmssTrafficLimitReadGiBFile($path));
    }

    public function testTrafficLimitReadGiBFileAcceptsGibSuffix(): void
    {
        $path = $this->tempDir.'/limit-suffixed';
        file_put_contents($path, "750GiB\n");

        $this->assertEquals(750, \pmssTrafficLimitReadGiBFile($path));
    }

    public function testTrafficLimitReadGiBFileRejectsInvalidContent(): void
    {
        $path = $this->tempDir.'/limit-invalid';
        file_put_contents($path, "five hundred\n");

        $this->assertEquals(0, \pmssTrafficLimitReadGiBFile($path));
    }
}
