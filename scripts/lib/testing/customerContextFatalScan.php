<?php
/**
 * Token-aware PHP fatal-call scanner.
 *
 * Customer-facing PHP cannot load operator-only helpers from `/scripts`, so
 * unresolved bare function calls are treated as ADR 0016/0017 regressions.
 * Operator PHP under `/scripts` is checked too: a call to a function that is
 * neither built in nor defined anywhere in the repo fatals at runtime.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/customerContext/scanner.php';

const PMSS_CUSTOMER_CONTEXT_ANTIPATTERN = 'OPERATOR_TREE_FUNCTION_LEAK';
const PMSS_OPERATOR_UNDEFINED_FUNCTION = 'UNDEFINED_FUNCTION_CALL';

/** Execute the customer context fatal scanner CLI. */
function pmssCustomerContextFatalScanMain(array $argv): int
{
    $override = getenv('PMSS_CUSTOMER_CONTEXT_SCAN_ROOT');
    $root = is_string($override) && trim($override) !== '' ? rtrim($override, '/') : dirname(__DIR__, 2);
    $violations = pmssCustomerContextFatalScan($root);
    $undefined = (new PmssCustomerContextFatalScanner($root))->scanOperatorTree();
    if ($violations === [] && $undefined === []) {
        fwrite(STDERR, "[customer-context-fatal-scan] OK - customer-side and operator-side bare function calls resolve safely\n");
        return 0;
    }
    if ($undefined !== []) {
        fwrite(STDERR, '[customer-context-fatal-scan] FAIL - '.PMSS_OPERATOR_UNDEFINED_FUNCTION.': '.count($undefined)." operator PHP call(s) to undefined functions:\n");
        foreach ($undefined as $v) fwrite(STDERR, '  '.$v['file'].':'.$v['line'].' - '.$v['function']."()\n");
        if ($violations === []) return 1;
        fwrite(STDERR, "\n");
    }

    fwrite(STDERR, '[customer-context-fatal-scan] FAIL - '.PMSS_CUSTOMER_CONTEXT_ANTIPATTERN.': '.count($violations)." unresolved customer PHP function call(s):\n");
    foreach ($violations as $v) {
        $source = $v['operator_tree_source'] !== '' ? ' (operator tree definition: '.$v['operator_tree_source'].')' : '';
        fwrite(STDERR, '  '.$v['file'].':'.$v['line'].' - '.$v['function']."()".$source."\n");
    }
    fwrite(STDERR, "\nPer ADR 0016 and ADR 0017, customer PHP must not depend on operator-tree functions.\n");
    fwrite(STDERR, "Move the customer-side subset into etc/skel/www/ or split operator-write/customer-read behavior.\n");
    return 1;
}

/** Return unresolved bare function calls in customer-facing PHP. */
function pmssCustomerContextFatalScan(string $root): array
{
    return (new PmssCustomerContextFatalScanner($root))->scan();
}
