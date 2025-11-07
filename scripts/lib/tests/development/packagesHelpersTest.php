<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/apps/packages/helpers.php';

class PackagesHelpersTest extends TestCase
{
    public function testBackportSuite(): void
    {
        $this->assertEquals('buster-backports', \pmssBackportSuite(10));
        $this->assertEquals('bullseye-backports', \pmssBackportSuite(11));
        $this->assertEquals('bookworm-backports', \pmssBackportSuite(12));
        $this->assertEquals(null, \pmssBackportSuite(13));
    }
}
