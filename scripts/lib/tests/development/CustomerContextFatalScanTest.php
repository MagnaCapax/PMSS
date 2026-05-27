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
        $this->assertStringContainsString('OPERATOR_TREE_FUNCTION_LEAK', $result['output']);
        $this->assertStringContainsString('etc/skel/www/welcome.php:2 - pmssJsonFileReadAssoc()', $result['output']);
        $this->assertStringContainsString('scripts/lib/lighttpd/userFileWrite.php', $result['output']);
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
