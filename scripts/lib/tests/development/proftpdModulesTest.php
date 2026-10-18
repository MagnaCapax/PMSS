<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/proftpdModules.php';

class ProftpdModulesTest extends TestCase
{
    public function testCommentedStockLineIsUncommented(): void
    {
        $this->assertSame("LoadModule mod_tls.c\n", \pmssProftpdTlsModuleContent("#LoadModule mod_tls.c\n"));
    }

    public function testAlreadyActiveLineRemainsUnchanged(): void
    {
        $source = "LoadModule mod_tls.c\n#LoadModule mod_tls.c\n";
        $this->assertSame($source, \pmssProftpdTlsModuleContent($source));
    }

    public function testAbsentLineIsAppendedOnce(): void
    {
        $updated = \pmssProftpdTlsModuleContent("LoadModule mod_auth.c\n");
        $this->assertSame("LoadModule mod_auth.c\nLoadModule mod_tls.c\n", $updated);
        $this->assertSame($updated, \pmssProftpdTlsModuleContent($updated));
    }

    public function testMissingFinalNewlineAndCrLfArePreserved(): void
    {
        $this->assertSame("Other on\r\nLoadModule mod_tls.c\r\n", \pmssProftpdTlsModuleContent('Other on' . "\r\n"));
        $this->assertSame("Other on\nLoadModule mod_tls.c\n", \pmssProftpdTlsModuleContent('Other on'));
    }

    public function testSimilarModuleNameIsNotTreatedAsTls(): void
    {
        $this->assertSame("LoadModule mod_tls_extra.c\nLoadModule mod_tls.c\n", \pmssProftpdTlsModuleContent("LoadModule mod_tls_extra.c\n"));
    }

    public function testIndentedCommentIsActivatedWithoutChangingOtherLines(): void
    {
        $source = "LoadModule mod_auth.c\n  #  LoadModule mod_tls.c\n";
        $this->assertSame("LoadModule mod_auth.c\n  LoadModule mod_tls.c\n", \pmssProftpdTlsModuleContent($source));
    }
}
