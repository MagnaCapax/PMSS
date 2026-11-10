<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/testing/customerContextFatalScan.php';

/**
 * Behavior lock for scripts/lib/testing/customerContextFatalScan.php and its internals:
 * scripts/lib/testing/customerContext/scanner.php
 * scripts/lib/testing/customerContext/files.php
 * scripts/lib/testing/customerContext/tokens.php
 * scripts/lib/testing/customerContext/guards.php
 */

class CustomerContextFatalScanTest extends TestCase
{
    public function testDetectsOperatorTreeFunctionLeak(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
            'etc/skel/www/welcome.php' => "<?php\npmssJsonFileReadAssoc('/tmp/example.json');\n",
            'scripts/lib/lighttpd/userFileWrite.php' => "<?php\nfunction pmssJsonFileReadAssoc(string \$path): array { return []; }\n",
        ]);

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root]);

        $this->assertSame(1, $result['rc']);
        $this->assertSame("[customer-context-fatal-scan] FAIL - OPERATOR_TREE_FUNCTION_LEAK: 1 unresolved customer PHP function call(s):\n  etc/skel/www/welcome.php:2 - pmssJsonFileReadAssoc() (operator tree definition: scripts/lib/lighttpd/userFileWrite.php)\n\nPer ADR 0016 and ADR 0017, customer PHP must not depend on operator-tree functions.\nMove the customer-side subset into etc/skel/www/ or split operator-write/customer-read behavior.", $result['output']);
    }

    public function testAllowsCustomerTreeFunctionDefinitions(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
            'etc/skel/www/helper.php' => "<?php\nfunction pmssCustomerHelper(): string { return strtoupper('ok'); }\n",
            'etc/skel/www/welcome.php' => "<?php\nrequire_once __DIR__.'/helper.php';\necho pmssCustomerHelper();\necho strlen('ok');\n",
        ]);

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root]);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('[customer-context-fatal-scan] OK', $result['output']);
    }

    public function testCountsSkelBinLibrariesAsCustomerPhp(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
            'etc/skel/www/index.php' => "<?php\necho 'ok';\n",
            'etc/skel/bin/pmssToolLib.php' => "<?php\nfunction pmssToolLibValue(): string { return 'ok'; }\n",
            'etc/skel/bin/tool' => "#!/usr/bin/env php\n<?php\nrequire_once __DIR__.'/pmssToolLib.php';\necho pmssToolLibValue();\n",
        ]);
        $this->assertSame(0, $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root])['rc']);

        $leak = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
            'etc/skel/www/index.php' => "<?php\necho 'ok';\n",
            'etc/skel/bin/pmssToolLib.php' => "<?php\nfunction pmssToolLibValue(): string { return pmssOperatorOnly(); }\n",
            'scripts/lib/operatorOnly.php' => "<?php\nfunction pmssOperatorOnly(): string { return 'x'; }\n",
        ]);
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $leak]);
        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('etc/skel/bin/pmssToolLib.php:2 - pmssOperatorOnly() (operator tree definition: scripts/lib/operatorOnly.php)', $result['output']);
    }

    public function testBracelessIfFunctionExistsIsAGuard(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
            'etc/skel/www/index.php' => "<?php\nfunction pmssLocalPath(string \$b): string\n{\n    if (function_exists('pmssOperatorPath')) return pmssOperatorPath(\$b);\n    return '';\n}\n",
            'scripts/lib/operatorPath.php' => "<?php\nfunction pmssOperatorPath(string \$b): string { return \$b; }\n",
        ]);
        $this->assertSame(0, $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root])['rc']);

        foreach (["if (!function_exists('pmssOperatorPath')) return pmssOperatorPath(\$b);", "if (function_exists('pmssOperatorPath') || \$b === '') return pmssOperatorPath(\$b);"] as $line) {
            $leak = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
                'etc/skel/www/index.php' => "<?php\nfunction pmssLocalPath(string \$b): string\n{\n    $line\n    return '';\n}\n",
                'scripts/lib/operatorPath.php' => "<?php\nfunction pmssOperatorPath(string \$b): string { return \$b; }\n",
            ]);
            $this->assertSame(1, $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $leak])['rc'], $line);
        }
    }

    public function testIgnoresCommentsStringsMethodsAndConstructors(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
            'etc/skel/www/welcome.php' => <<<'PHP'
<?php
// pmssJsonFileReadAssoc('/tmp/example.json');
$literal = 'pmssJsonFileReadAssoc("/tmp/example.json")';
$object = new stdClass();
$object->pmssJsonFileReadAssoc();
SomeClass::pmssJsonFileReadAssoc();
PHP
        ]);

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root]);

        $this->assertSame(0, $result['rc']);
    }

    public function testAllowsFunctionExistsGuardedOptionalCalls(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
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

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root]);

        $this->assertSame(0, $result['rc']);
    }

    public function testReportsMultipleLeaksWithOperatorSourcesInScanOrder(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
            'etc/skel/www/alpha.php' => "<?php\npmssAlphaOperator();\npmssUnknownOperator();\n",
            'etc/skel/www/omega.php' => "<?php\npmssOmegaOperator();\n",
            'scripts/lib/alpha.php' => "<?php\nfunction pmssAlphaOperator(): void {}\n",
            'scripts/lib/nested/omega.php' => "<?php\nfunction pmssOmegaOperator(): void {}\n",
        ]);

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root]);

        $this->assertSame(1, $result['rc']);
        $this->assertSame("[customer-context-fatal-scan] FAIL - OPERATOR_TREE_FUNCTION_LEAK: 3 unresolved customer PHP function call(s):\n  etc/skel/www/alpha.php:2 - pmssAlphaOperator() (operator tree definition: scripts/lib/alpha.php)\n  etc/skel/www/alpha.php:3 - pmssUnknownOperator()\n  etc/skel/www/omega.php:2 - pmssOmegaOperator() (operator tree definition: scripts/lib/nested/omega.php)\n\nPer ADR 0016 and ADR 0017, customer PHP must not depend on operator-tree functions.\nMove the customer-side subset into etc/skel/www/ or split operator-write/customer-read behavior.", $result['output']);
    }

    public function testReportsOperatorCallsToUndefinedFunctions(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-customer-context-scan-', 0700), [
            'scripts/lib/lighttpd/proxy.php' => "<?php\nif (!is_socket('/run/x.sock')) return;\npmssKnownHelper();\n",
            'scripts/lib/helper.php' => "<?php\nfunction pmssKnownHelper(): void { pmssSkelHelper(); }\n",
            'scripts/startDaemon' => "#!/usr/bin/env php\n<?php\npmssMissingStarter();\nif (function_exists('pmssOptionalStarter')) {\n    pmssOptionalStarter();\n}\n",
            'etc/skel/www/skelHelper.php' => "<?php\nfunction pmssSkelHelper(): void {}\n",
        ]);

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root]);

        $this->assertSame(1, $result['rc']);
        $this->assertSame("[customer-context-fatal-scan] FAIL - UNDEFINED_FUNCTION_CALL: 2 operator PHP call(s) to undefined functions:\n  scripts/lib/lighttpd/proxy.php:2 - is_socket()\n  scripts/startDaemon:3 - pmssMissingStarter()", $result['output']);
    }

    public function testScanScopesRetainDiscoveryOrderDuplicatesAndDefinitionPrecedence(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-fatal-scan-order-', 0700), [
            'etc/skel/helper.php' => "<?php\nfunction pmssFixtureHomeHelper() {}\n",
            'etc/skel/www/first.php' => "<?php\nPmssFixtureMissing();\npmssFixtureHomeHelper();\npmssFixtureNested();\n",
            'etc/skel/www/nested/ignored.php' => "<?php\nfunction pmssFixtureNested() {}\npmssFixtureIgnored();\n",
            // A PHP command appears in both the extension and interpreter inventories.
            'etc/skel/bin/first.php' => "#!/usr/bin/env php\n<?php\npmssFixtureBinMissing();\n",
            'etc/skel/bin/second' => "#!/usr/bin/php\n<?php\npmssFixtureCommandMissing();\n",
            'etc/skel/bin/not-php' => "#!/bin/sh\npmssFixtureIgnored\n",
            'scripts/lib/first.php' => "<?php\nfunction pmssFixtureMissing() {}\n",
            'scripts/lib/last.php' => "<?php\nfunction pmssFixtureMissing() {}\n",
            'scripts/lib/tests/ignored.php' => "<?php\npmssFixtureIgnored();\n",
            'scripts/lib/devristo/ignored.php' => "<?php\npmssFixtureIgnored();\n",
            'scripts/run' => "#!/usr/bin/env php\n<?php\npmssFixtureOperatorMissing();\n",
        ]);
        $scanner = new \PmssCustomerContextFatalScanner($root);
        $this->assertSame([
            ['file' => 'etc/skel/www/first.php', 'line' => 2, 'function' => 'PmssFixtureMissing', 'operator_tree_source' => 'scripts/lib/last.php'],
            ['file' => 'etc/skel/www/first.php', 'line' => 4, 'function' => 'pmssFixtureNested', 'operator_tree_source' => ''],
            ['file' => 'etc/skel/bin/first.php', 'line' => 3, 'function' => 'pmssFixtureBinMissing', 'operator_tree_source' => ''],
            ['file' => 'etc/skel/bin/first.php', 'line' => 3, 'function' => 'pmssFixtureBinMissing', 'operator_tree_source' => ''],
            ['file' => 'etc/skel/bin/second', 'line' => 3, 'function' => 'pmssFixtureCommandMissing', 'operator_tree_source' => ''],
        ], $scanner->scan());
        $this->assertSame([
            ['file' => 'scripts/run', 'line' => 3, 'function' => 'pmssFixtureOperatorMissing'],
        ], $scanner->scanOperatorTree());
    }

    public function testBothScanScopesRetainGuardAndTokenDecisions(): void
    {
        // These are token fixtures, never executed PHP. Include the existing heuristic's
        // permissive and restrictive decisions so this refactor cannot change policy.
        $cases = [
            'bare' => ['pmssFixtureAbsent();', true],
            'braced' => ["if (function_exists('pmssFixtureAbsent')) { pmssFixtureAbsent(); }", false],
            'nested sibling' => ["if (function_exists('pmssFixtureAbsent')) { if (true) { strlen('x'); } pmssFixtureAbsent(); }", false],
            'closed nested braces' => ["if (function_exists('pmssFixtureAbsent')) { while (false) { if (true) {} } pmssFixtureAbsent(); }", false],
            'unrelated preceding block' => ["if (true) { strlen('x'); } pmssFixtureAbsent();", true],
            'wrong guard' => ["if (function_exists('pmssFixtureOther')) { pmssFixtureAbsent(); }", true],
            'braceless' => ["if (function_exists('pmssFixtureAbsent')) pmssFixtureAbsent();", false],
            'braceless disjunction' => ["if (function_exists('pmssFixtureAbsent') || true) pmssFixtureAbsent();", true],
            'short circuit and' => ["function_exists('pmssFixtureAbsent') && pmssFixtureAbsent();", false],
            'negative or' => ["!function_exists('pmssFixtureAbsent') || pmssFixtureAbsent();", false],
            'ternary' => ["function_exists('pmssFixtureAbsent') ? pmssFixtureAbsent() : null;", false],
            'early return' => ["if (!function_exists('pmssFixtureAbsent')) { return; } pmssFixtureAbsent();", false],
            'early exit' => ["if (!function_exists('pmssFixtureAbsent')) { exit; } pmssFixtureAbsent();", false],
            'no early return' => ["if (!function_exists('pmssFixtureAbsent')) { strlen('x'); } pmssFixtureAbsent();", true],
            'positive early return' => ["if (function_exists('pmssFixtureAbsent')) { return; } pmssFixtureAbsent();", true],
            'case and trivia' => ["if (FUNCTION_EXISTS /* comment */ ('PMSSfixtureABSENT')) { pmssFixtureAbsent /* call */ (); }", false],
            'reference declaration' => ['function &pmssFixtureAbsent() {} pmssFixtureAbsent();', false],
            'method definition index' => ['class FixtureType { function pmssFixtureAbsent() {} } pmssFixtureAbsent();', false],
            'member calls' => ['$object->pmssFixtureAbsent(); FixtureType::pmssFixtureAbsent(); new pmssFixtureAbsent();', false],
            'qualified call' => ['\\pmssFixtureAbsent();', false],
            'literal and comment' => ["'pmssFixtureAbsent()'; /* pmssFixtureAbsent(); */", false],
            'unmatched delimiter' => ['} pmssFixtureAbsent();', true],
        ];
        foreach ($cases as $label => [$fragment, $unresolved]) {
            $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-fatal-scan-guards-', 0700), [
                'etc/skel/www/probe.php' => "<?php\n".$fragment."\n",
                'scripts/probe.php' => "<?php\n".$fragment."\n",
            ]);
            $scanner = new \PmssCustomerContextFatalScanner($root);
            $expected = $unresolved ? ['pmssFixtureAbsent'] : [];
            $this->assertSame($expected, array_column($scanner->scan(), 'function'), 'customer: '.$label);
            $this->assertSame($expected, array_column($scanner->scanOperatorTree(), 'function'), 'operator: '.$label);
        }
    }

    public function testAbsentScanScopesReturnEmptyResults(): void
    {
        $root = $this->pmssMakeTempDir('pmss-fatal-scan-empty-', 0700);
        $scanner = new \PmssCustomerContextFatalScanner($root);
        $this->assertSame([], $scanner->scan());
        $this->assertSame([], $scanner->scanOperatorTree());
        $this->assertSame([], \pmssCustomerContextFatalScan($root));
    }

    public function testMixedCliDiagnosticsRetainOperatorFirstSnapshot(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-fatal-scan-cli-', 0700), [
            'etc/skel/www/probe.php' => "<?php\npmssFixtureOperatorOnly();\n",
            'scripts/lib/probe.php' => "<?php\nfunction pmssFixtureOperatorOnly() { pmssFixtureMissing(); }\n",
        ]);
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/testing/customer-context-fatal-scan.php', [], ['PMSS_CUSTOMER_CONTEXT_SCAN_ROOT' => $root]);
        $this->assertSame(1, $result['rc']);
        $this->assertSame("[customer-context-fatal-scan] FAIL - UNDEFINED_FUNCTION_CALL: 1 operator PHP call(s) to undefined functions:\n  scripts/lib/probe.php:2 - pmssFixtureMissing()\n\n[customer-context-fatal-scan] FAIL - OPERATOR_TREE_FUNCTION_LEAK: 1 unresolved customer PHP function call(s):\n  etc/skel/www/probe.php:2 - pmssFixtureOperatorOnly() (operator tree definition: scripts/lib/probe.php)\n\nPer ADR 0016 and ADR 0017, customer PHP must not depend on operator-tree functions.\nMove the customer-side subset into etc/skel/www/ or split operator-write/customer-read behavior.", $result['output']);
    }

}
