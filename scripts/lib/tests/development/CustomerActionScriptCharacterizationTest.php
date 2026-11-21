<?php
/** Freeze the shared customer action script before changing its delivery boundaries. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';

class CustomerActionScriptCharacterizationTest extends TestCase
{
    public function testGeneratedBrowserBodyRetainsRequestsPollingAndCallbacks(): void
    {
        // Fingerprint the returned browser bytes, including its final newline.
        // Endpoint maps precede this body and have their own catalog assertions.
        $script = \pmssActionScriptJs();
        $start = strpos($script, '/* Shared customer-panel action helpers. Loaded after jQuery. */');
        $this->assertTrue($start !== false, 'Generated browser body is missing.');
        $this->assertSame('22b5c96ac797e87f54929fbf27deb383086cc0857c30e08f71a399ca4879166e',
            hash('sha256', substr($script, $start)));
    }
}
