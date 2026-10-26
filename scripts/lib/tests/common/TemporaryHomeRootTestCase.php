<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';

/** Share the opt-in home boundary used by temporary account fixtures. */
abstract class TemporaryHomeRootTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pmssTrackTempRootAsHomeRoot();
    }
}
