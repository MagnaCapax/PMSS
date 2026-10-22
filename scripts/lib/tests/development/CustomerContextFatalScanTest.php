<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CustomerContextFatalScanTest extends TestCase
{
    public function testDetectsOperatorTreeFunctionLeak(): void
    {
        $root = $this->pmssCustomerContextFixtureRoot([
            'etc/skel/www/welcome.php' => "<?php\npmssJsonFileReadAssoc('/tmp/example.json');\n",
            'scripts/lib/lighttpd/userFileWrite.php' => "<?php\nfunction pmssJsonFileReadAssoc(string \$path): array { return []; }\n",
        ]);

        $result = $this->pmssCustomerContextRunScan($root);

        $this->assertSame(1, $result['rc']);
        $this->assertSame("[customer-context-fatal-scan] FAIL - OPERATOR_TREE_FUNCTION_LEAK: 1 unresolved customer PHP function call(s):\n  etc/skel/www/welcome.php:2 - pmssJsonFileReadAssoc() (operator tree definition: scripts/lib/lighttpd/userFileWrite.php)\n\nPer ADR 0016 and ADR 0017, customer PHP must not depend on operator-tree functions.\nMove the customer-side subset into etc/skel/www/ or split operator-write/customer-read behavior.", $result['output']);
    }

    public function testAllowsCustomerTreeFunctionDefinitions(): void
    {
        $root = $this->pmssCustomerContextFixtureRoot([
            'etc/skel/www/helper.php' => "<?php\nfunction pmssCustomerHelper(): string { return strtoupper('ok'); }\n",
            'etc/skel/www/welcome.php' => "<?php\nrequire_once __DIR__.'/helper.php';\necho pmssCustomerHelper();\necho strlen('ok');\n",
        ]);

        $result = $this->pmssCustomerContextRunScan($root);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('[customer-context-fatal-scan] OK', $result['output']);
    }

    public function testCountsSkelBinLibrariesAsCustomerPhp(): void
    {
        $root = $this->pmssCustomerContextFixtureRoot([
            'etc/skel/www/index.php' => "<?php\necho 'ok';\n",
            'etc/skel/bin/pmssToolLib.php' => "<?php\nfunction pmssToolLibValue(): string { return 'ok'; }\n",
            'etc/skel/bin/tool' => "#!/usr/bin/env php\n<?php\nrequire_once __DIR__.'/pmssToolLib.php';\necho pmssToolLibValue();\n",
        ]);
        $this->assertSame(0, $this->pmssCustomerContextRunScan($root)['rc']);

        $leak = $this->pmssCustomerContextFixtureRoot([
            'etc/skel/www/index.php' => "<?php\necho 'ok';\n",
            'etc/skel/bin/pmssToolLib.php' => "<?php\nfunction pmssToolLibValue(): string { return pmssOperatorOnly(); }\n",
            'scripts/lib/operatorOnly.php' => "<?php\nfunction pmssOperatorOnly(): string { return 'x'; }\n",
        ]);
        $result = $this->pmssCustomerContextRunScan($leak);
        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('etc/skel/bin/pmssToolLib.php:2 - pmssOperatorOnly() (operator tree definition: scripts/lib/operatorOnly.php)', $result['output']);
    }

    public function testBracelessIfFunctionExistsIsAGuard(): void
    {
        $root = $this->pmssCustomerContextFixtureRoot([
            'etc/skel/www/index.php' => "<?php\nfunction pmssLocalPath(string \$b): string\n{\n    if (function_exists('pmssOperatorPath')) return pmssOperatorPath(\$b);\n    return '';\n}\n",
            'scripts/lib/operatorPath.php' => "<?php\nfunction pmssOperatorPath(string \$b): string { return \$b; }\n",
        ]);
        $this->assertSame(0, $this->pmssCustomerContextRunScan($root)['rc']);

        foreach (["if (!function_exists('pmssOperatorPath')) return pmssOperatorPath(\$b);", "if (function_exists('pmssOperatorPath') || \$b === '') return pmssOperatorPath(\$b);"] as $line) {
            $leak = $this->pmssCustomerContextFixtureRoot([
                'etc/skel/www/index.php' => "<?php\nfunction pmssLocalPath(string \$b): string\n{\n    $line\n    return '';\n}\n",
                'scripts/lib/operatorPath.php' => "<?php\nfunction pmssOperatorPath(string \$b): string { return \$b; }\n",
            ]);
            $this->assertSame(1, $this->pmssCustomerContextRunScan($leak)['rc'], $line);
        }
    }

    public function testIgnoresCommentsStringsMethodsAndConstructors(): void
    {
        $root = $this->pmssCustomerContextFixtureRoot([
            'etc/skel/www/welcome.php' => <<<'PHP'
<?php
// pmssJsonFileReadAssoc('/tmp/example.json');
$literal = 'pmssJsonFileReadAssoc("/tmp/example.json")';
$object = new stdClass();
$object->pmssJsonFileReadAssoc();
SomeClass::pmssJsonFileReadAssoc();
PHP
        ]);

        $result = $this->pmssCustomerContextRunScan($root);

        $this->assertSame(0, $result['rc']);
    }

    public function testAllowsFunctionExistsGuardedOptionalCalls(): void
    {
        $root = $this->pmssCustomerContextFixtureRoot([
            'etc/skel/www/welcome.php' => <<<'PHP'
<?php
if (function_exists('pmssOptionalOperator')) {
    pmssOptionalOperator();
}
function pmssGuardedReturn(): bool
{
    return function_exists('pmssOptionalOther') && pmssOptionalOther();
}
function pmssGuardedNegative(): bool
{
    if (!function_exists('pmssOptionalThird') || !pmssOptionalThird()) {
        return false;
    }
    return true;
}
function pmssGuardedTernary()
{
    return function_exists('pmssOptionalFifth') ? pmssOptionalFifth() : null;
}
function pmssGuardedEarlyReturn()
{
    if (!function_exists('pmssOptionalFourth')) {
        return null;
    }
    return pmssOptionalFourth();
}
function pmssGuardedZipExtension($path): void
{
    if (function_exists('zip_open')) {
        $archive = zip_open($path);
        if ($archive) {
            while ($entry = zip_read($archive)) {
                zip_entry_name($entry);
            }
            zip_close($archive);
        }
    }
}
PHP
        ]);

        $result = $this->pmssCustomerContextRunScan($root);

        $this->assertSame(0, $result['rc']);
    }

    public function testReportsMultipleLeaksWithOperatorSourcesInScanOrder(): void
    {
        $root = $this->pmssCustomerContextFixtureRoot([
            'etc/skel/www/alpha.php' => "<?php\npmssAlphaOperator();\npmssUnknownOperator();\n",
            'etc/skel/www/omega.php' => "<?php\npmssOmegaOperator();\n",
            'scripts/lib/alpha.php' => "<?php\nfunction pmssAlphaOperator(): void {}\n",
            'scripts/lib/nested/omega.php' => "<?php\nfunction pmssOmegaOperator(): void {}\n",
        ]);

        $result = $this->pmssCustomerContextRunScan($root);

        $this->assertSame(1, $result['rc']);
        $this->assertSame("[customer-context-fatal-scan] FAIL - OPERATOR_TREE_FUNCTION_LEAK: 3 unresolved customer PHP function call(s):\n  etc/skel/www/alpha.php:2 - pmssAlphaOperator() (operator tree definition: scripts/lib/alpha.php)\n  etc/skel/www/alpha.php:3 - pmssUnknownOperator()\n  etc/skel/www/omega.php:2 - pmssOmegaOperator() (operator tree definition: scripts/lib/nested/omega.php)\n\nPer ADR 0016 and ADR 0017, customer PHP must not depend on operator-tree functions.\nMove the customer-side subset into etc/skel/www/ or split operator-write/customer-read behavior.", $result['output']);
    }

    public function testReportsOperatorCallsToUndefinedFunctions(): void
    {
        $root = $this->pmssCustomerContextFixtureRoot([
            'scripts/lib/lighttpd/proxy.php' => "<?php\nif (!is_socket('/run/x.sock')) return;\npmssKnownHelper();\n",
            'scripts/lib/helper.php' => "<?php\nfunction pmssKnownHelper(): void { pmssSkelHelper(); }\n",
            'scripts/startDaemon' => "#!/usr/bin/env php\n<?php\npmssMissingStarter();\nif (function_exists('pmssOptionalStarter')) {\n    pmssOptionalStarter();\n}\n",
            'etc/skel/www/skelHelper.php' => "<?php\nfunction pmssSkelHelper(): void {}\n",
        ]);

        $result = $this->pmssCustomerContextRunScan($root);

        $this->assertSame(1, $result['rc']);
        $this->assertSame("[customer-context-fatal-scan] FAIL - UNDEFINED_FUNCTION_CALL: 2 operator PHP call(s) to undefined functions:\n  scripts/lib/lighttpd/proxy.php:2 - is_socket()\n  scripts/startDaemon:3 - pmssMissingStarter()", $result['output']);
    }

    private function pmssCustomerContextFixtureRoot(array $files): string
    {
        $root = $this->pmssMakeTempDir('pmss-customer-context-scan-', 0700);
        foreach ($files as $relativePath => $contents) {
            $this->pmssWriteFile($root.'/'.$relativePath, $contents);
        }
        return $root;
    }

    private function pmssCustomerContextRunScan(string $root): array
    {
        return $this->pmssRunRepoPhpScriptCommand(
            'scripts/testing/customer-context-fatal-scan.php',
            [],
            ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root],
            '2>&1'
        );
    }
}
