<?php
/**
 * Token-aware customer PHP fatal-call scanner.
 *
 * Customer-facing PHP cannot load operator-only helpers from `/scripts`, so
 * unresolved bare function calls are treated as ADR 0016/0017 regressions.
 *
 * @license GPL-3.0-only
 */

const PMSS_CUSTOMER_CONTEXT_ANTIPATTERN = 'OPERATOR_TREE_FUNCTION_LEAK';

/** Execute the customer context fatal scanner CLI. */
function pmssCustomerContextFatalScanMain(array $argv): int
{
    $root = pmssCustomerContextRoot();
    $violations = pmssCustomerContextFatalScan($root);
    if ($violations === []) {
        fwrite(STDERR, "[customer-context-fatal-scan] OK - customer-side bare function calls resolve safely\n");
        return 0;
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
    $root = rtrim($root, '/');
    $skel = $root.'/etc/skel';
    $www = $skel.'/www';
    if (!is_dir($www)) return [];

    $wwwFiles = pmssCustomerContextFiles($www, false);
    $customerDefs = pmssCustomerContextDefinitions(array_merge(pmssCustomerContextFiles($skel, false), $wwwFiles));
    $operatorDefs = pmssCustomerContextDefinitions(pmssCustomerContextFiles($root.'/scripts/lib', true));
    $builtins = array_fill_keys(array_map('strtolower', get_defined_functions()['internal'] ?? []), true);
    $violations = [];

    foreach ($wwwFiles as $file) {
        $tokens = pmssCustomerContextTokens($file);
        foreach (pmssCustomerContextCalls($tokens) as $call) {
            $key = strtolower($call['function']);
            if (isset($builtins[$key]) || isset($customerDefs[$key]) || pmssCustomerContextGuarded($tokens, $call['index'], $key)) {
                continue;
            }
            $violations[] = [
                'file' => pmssCustomerContextRelative($root, $file),
                'line' => $call['line'],
                'function' => $call['function'],
                'operator_tree_source' => isset($operatorDefs[$key]) ? pmssCustomerContextRelative($root, $operatorDefs[$key]) : '',
            ];
        }
    }
    return $violations;
}

/** Resolve the repository root, allowing tests to provide a hermetic fixture. */
function pmssCustomerContextRoot(): string
{
    $override = getenv('PMSS_CUSTOMER_CONTEXT_SCAN_ROOT');
    return is_string($override) && trim($override) !== '' ? rtrim($override, '/') : dirname(__DIR__, 2);
}

/** @return array<int, string> */
function pmssCustomerContextFiles(string $dir, bool $recursive): array
{
    if (!is_dir($dir)) return [];
    $files = [];
    $iterator = $recursive
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS))
        : new DirectoryIterator($dir);
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        if ($recursive && (strpos($path, '/tests/') !== false || strpos($path, '/devristo/') !== false)) continue;
        $files[] = $path;
    }
    sort($files);
    return $files;
}

/** @return array<int, mixed> */
function pmssCustomerContextTokens(string $file): array
{
    $content = file_get_contents($file);
    return token_get_all(is_string($content) ? $content : '');
}

/** @return array<string, string> */
function pmssCustomerContextDefinitions(array $files): array
{
    $defs = [];
    foreach (array_values(array_unique($files)) as $file) {
        $tokens = pmssCustomerContextTokens($file);
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (!pmssCustomerContextIs($tokens[$i], T_FUNCTION)) continue;
            $name = pmssCustomerContextWalk($tokens, $i, 1);
            if ($name !== null && pmssCustomerContextText($tokens[$name]) === '&') $name = pmssCustomerContextWalk($tokens, $name, 1);
            if ($name !== null && pmssCustomerContextIs($tokens[$name], T_STRING)) $defs[strtolower($tokens[$name][1])] = $file;
        }
    }
    return $defs;
}

/** @return array<int, array{index:int,function:string,line:int}> */
function pmssCustomerContextCalls(array $tokens): array
{
    $calls = [];
    foreach ($tokens as $i => $token) {
        if (!pmssCustomerContextIs($token, T_STRING)) continue;
        $next = pmssCustomerContextWalk($tokens, $i, 1);
        if ($next === null || pmssCustomerContextText($tokens[$next]) !== '(') continue;
        if (pmssCustomerContextCallExcluded($tokens, $i)) continue;
        $calls[] = ['index' => $i, 'function' => $token[1], 'line' => $token[2]];
    }
    return $calls;
}

/** Return true when function_exists() proves a missing call cannot fatal. */
function pmssCustomerContextGuarded(array $tokens, int $call, string $name): bool
{
    $guards = strpos($name, 'zip_') === 0 && $name !== 'zip_open' ? [$name, 'zip_open'] : [$name];
    return pmssCustomerContextSameExpressionGuarded($tokens, $call, $guards)
        || pmssCustomerContextEnclosingIfGuarded($tokens, $call, $guards)
        || pmssCustomerContextPreviousIfReturns($tokens, $call, $guards);
}

/** Detect short-circuit guards like function_exists('x') && x(). */
function pmssCustomerContextSameExpressionGuarded(array $tokens, int $call, array $guards): bool
{
    for ($i = pmssCustomerContextBoundary($tokens, $call) + 1; $i < $call; $i++) {
        $guard = pmssCustomerContextFunctionExistsAt($tokens, $i, $guards);
        if ($guard === null) continue;
        $operator = '';
        for ($j = $guard['end'] + 1; $j < $call; $j++) {
            if (pmssCustomerContextIs($tokens[$j], T_BOOLEAN_AND) || pmssCustomerContextText($tokens[$j]) === '&&') { $operator = 'and'; break; }
            if (pmssCustomerContextIs($tokens[$j], T_BOOLEAN_OR) || pmssCustomerContextText($tokens[$j]) === '||') { $operator = 'or'; break; }
            if (pmssCustomerContextText($tokens[$j]) === '?') { $operator = 'ternary'; break; }
        }
        if ((!$guard['negated'] && ($operator === 'and' || $operator === 'ternary')) || ($guard['negated'] && $operator === 'or')) return true;
    }
    return false;
}

/** Detect ancestor blocks guarded by if (function_exists('x')). */
function pmssCustomerContextEnclosingIfGuarded(array $tokens, int $call, array $guards): bool
{
    $cursor = $call;
    while (($brace = pmssCustomerContextNearestOpenBrace($tokens, $cursor)) !== null) {
        $closeParen = pmssCustomerContextWalk($tokens, $brace, -1);
        $openParen = ($closeParen !== null && pmssCustomerContextText($tokens[$closeParen]) === ')') ? pmssCustomerContextMatchOpen($tokens, $closeParen, '(', ')') : null;
        $before = $openParen === null ? null : pmssCustomerContextWalk($tokens, $openParen, -1);
        if ($before !== null && pmssCustomerContextIs($tokens[$before], T_IF)) {
            for ($i = $openParen + 1; $i < $closeParen; $i++) {
                $guard = pmssCustomerContextFunctionExistsAt($tokens, $i, $guards);
                if ($guard !== null && !$guard['negated']) return true;
            }
        }
        $cursor = $brace;
    }
    return false;
}

/** Detect immediately preceding if (!function_exists('x')) { return; }. */
function pmssCustomerContextPreviousIfReturns(array $tokens, int $call, array $guards): bool
{
    $boundary = pmssCustomerContextBoundary($tokens, $call);
    if ($boundary < 0 || pmssCustomerContextText($tokens[$boundary]) !== '}') return false;
    $openBrace = pmssCustomerContextMatchOpen($tokens, $boundary, '{', '}');
    if ($openBrace === null) return false;
    $blockReturns = false;
    for ($i = $openBrace + 1; $i < $boundary; $i++) {
        if (pmssCustomerContextIs($tokens[$i], T_RETURN) || pmssCustomerContextIs($tokens[$i], T_EXIT)) { $blockReturns = true; break; }
    }
    if (!$blockReturns) return false;
    $closeParen = pmssCustomerContextWalk($tokens, $openBrace, -1);
    $openParen = ($closeParen !== null && pmssCustomerContextText($tokens[$closeParen]) === ')') ? pmssCustomerContextMatchOpen($tokens, $closeParen, '(', ')') : null;
    $before = $openParen === null ? null : pmssCustomerContextWalk($tokens, $openParen, -1);
    if ($before === null || !pmssCustomerContextIs($tokens[$before], T_IF)) return false;
    for ($i = $openParen + 1; $i < $closeParen; $i++) {
        $guard = pmssCustomerContextFunctionExistsAt($tokens, $i, $guards);
        if ($guard !== null && $guard['negated']) return true;
    }
    return false;
}

/** @return array{end:int,negated:bool}|null */
function pmssCustomerContextFunctionExistsAt(array $tokens, int $i, array $guards): ?array
{
    if (!pmssCustomerContextIs($tokens[$i], T_STRING) || strtolower($tokens[$i][1]) !== 'function_exists') return null;
    $open = pmssCustomerContextWalk($tokens, $i, 1);
    $literal = $open === null ? null : pmssCustomerContextWalk($tokens, $open, 1);
    if ($open === null || $literal === null || pmssCustomerContextText($tokens[$open]) !== '(' || !pmssCustomerContextIs($tokens[$literal], T_CONSTANT_ENCAPSED_STRING)) return null;
    if (!in_array(strtolower(stripcslashes(substr((string) $tokens[$literal][1], 1, -1))), $guards, true)) return null;
    $previous = pmssCustomerContextWalk($tokens, $i, -1);
    return ['end' => pmssCustomerContextWalk($tokens, $literal, 1) ?? $literal, 'negated' => $previous !== null && pmssCustomerContextText($tokens[$previous]) === '!'];
}

function pmssCustomerContextCallExcluded(array $tokens, int $i): bool
{
    $prev = pmssCustomerContextWalk($tokens, $i, -1);
    if ($prev === null) return false;
    if (pmssCustomerContextIs($tokens[$prev], T_FUNCTION)) return true;
    $beforeRef = pmssCustomerContextText($tokens[$prev]) === '&' ? pmssCustomerContextWalk($tokens, $prev, -1) : null;
    if ($beforeRef !== null && pmssCustomerContextIs($tokens[$beforeRef], T_FUNCTION)) return true;
    return pmssCustomerContextIs($tokens[$prev], T_OBJECT_OPERATOR)
        || pmssCustomerContextIs($tokens[$prev], T_DOUBLE_COLON)
        || pmssCustomerContextIs($tokens[$prev], T_NEW)
        || pmssCustomerContextText($tokens[$prev]) === '\\';
}

function pmssCustomerContextNearestOpenBrace(array $tokens, int $i): ?int
{
    $depth = 0;
    for ($j = $i - 1; $j >= 0; $j--) {
        $text = pmssCustomerContextText($tokens[$j]);
        if ($text === '}') $depth++;
        if ($text === '{' && $depth-- === 0) return $j;
    }
    return null;
}

function pmssCustomerContextMatchOpen(array $tokens, int $close, string $openText, string $closeText): ?int
{
    $depth = 0;
    for ($i = $close; $i >= 0; $i--) {
        $text = pmssCustomerContextText($tokens[$i]);
        if ($text === $closeText) $depth++;
        if ($text === $openText && --$depth === 0) return $i;
    }
    return null;
}

function pmssCustomerContextBoundary(array $tokens, int $i): int
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (in_array(pmssCustomerContextText($tokens[$j]), [';', '{', '}'], true)) return $j;
    }
    return -1;
}

function pmssCustomerContextWalk(array $tokens, int $i, int $step): ?int
{
    $step = $step < 0 ? -1 : 1;
    for ($j = $i + $step, $n = count($tokens); $j >= 0 && $j < $n; $j += $step) {
        if (!pmssCustomerContextTrivia($tokens[$j])) return $j;
    }
    return null;
}

function pmssCustomerContextTrivia($token): bool
{
    return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

function pmssCustomerContextIs($token, int $id): bool
{
    return is_array($token) && $token[0] === $id;
}

function pmssCustomerContextText($token): string
{
    return is_array($token) ? (string) $token[1] : (string) $token;
}

function pmssCustomerContextRelative(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return strpos($path, $root.'/') === 0 ? substr($path, strlen($root) + 1) : $path;
}
