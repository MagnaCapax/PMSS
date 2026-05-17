<?php
/**
 * Token-aware customer PHP fatal-call scanner.
 *
 * Customer-facing PHP cannot load operator-only helpers from `/scripts`, so this
 * scanner catches bare function calls that are neither PHP built-ins nor defined
 * inside the customer-readable skeleton tree.
 *
 * @license GPL-3.0-only
 */

const PMSS_CUSTOMER_CONTEXT_ANTIPATTERN = 'OPERATOR_TREE_FUNCTION_LEAK';

/** Execute the customer context fatal scanner CLI. */
function pmssCustomerContextFatalScanMain(array $argv): int
{
    $rootDir = pmssCustomerContextScanRoot();
    $violations = pmssCustomerContextFatalScan($rootDir);

    if ($violations === []) {
        fwrite(STDERR, "[customer-context-fatal-scan] OK - customer-side bare function calls resolve safely\n");
        return 0;
    }

    fwrite(STDERR, '[customer-context-fatal-scan] FAIL - '.PMSS_CUSTOMER_CONTEXT_ANTIPATTERN.': '.count($violations)." unresolved customer PHP function call(s):\n");
    foreach ($violations as $violation) {
        $source = $violation['operator_tree_source'] !== ''
            ? ' (operator tree definition: '.$violation['operator_tree_source'].')'
            : '';
        fwrite(STDERR, '  '.$violation['file'].':'.$violation['line'].' - '.$violation['function']."()".$source."\n");
    }
    fwrite(STDERR, "\nPer ADR 0016 and ADR 0017, customer PHP must not depend on operator-tree functions.\n");
    fwrite(STDERR, "Move the customer-side subset into etc/skel/www/ or split operator-write/customer-read behavior.\n");

    return 1;
}

/** Return unresolved bare function calls in customer-facing PHP. */
function pmssCustomerContextFatalScan(string $rootDir): array
{
    $rootDir = rtrim($rootDir, '/');
    $customerRoot = $rootDir.'/etc/skel';
    $customerWww = $customerRoot.'/www';
    if (!is_dir($customerWww)) {
        return [];
    }

    $customerFiles = pmssCustomerContextCustomerDefinitionFiles($customerRoot, $customerWww);
    $targetFiles = pmssCustomerContextPhpFiles($customerWww, false);
    $customerFunctions = pmssCustomerContextFunctionDefinitions($customerFiles);
    $operatorFunctions = pmssCustomerContextFunctionDefinitions(pmssCustomerContextPhpFiles($rootDir.'/scripts/lib', true));
    $builtins = pmssCustomerContextBuiltinFunctions();
    $violations = [];

    foreach ($targetFiles as $file) {
        $tokens = pmssCustomerContextTokens($file);
        foreach (pmssCustomerContextBareFunctionCalls($tokens) as $call) {
            $nameKey = strtolower($call['function']);
            if (isset($builtins[$nameKey]) || isset($customerFunctions[$nameKey])) {
                continue;
            }
            if (pmssCustomerContextCallHasFunctionExistsGuard($tokens, $call['index'], $call['function'])) {
                continue;
            }

            $violations[] = [
                'file' => pmssCustomerContextRelativePath($rootDir, $file),
                'line' => $call['line'],
                'function' => $call['function'],
                'operator_tree_source' => isset($operatorFunctions[$nameKey])
                    ? pmssCustomerContextRelativePath($rootDir, $operatorFunctions[$nameKey])
                    : '',
            ];
        }
    }

    return $violations;
}

/** Resolve the repository root, allowing tests to provide a hermetic fixture. */
function pmssCustomerContextScanRoot(): string
{
    $override = getenv('PMSS_CUSTOMER_CONTEXT_SCAN_ROOT');
    if (is_string($override) && trim($override) !== '') {
        return rtrim($override, '/');
    }

    return dirname(__DIR__, 2);
}

/** @return array<int, string> */
function pmssCustomerContextCustomerDefinitionFiles(string $customerRoot, string $customerWww): array
{
    $files = array_merge(
        pmssCustomerContextPhpFiles($customerRoot, false),
        pmssCustomerContextPhpFiles($customerWww, false)
    );
    $files = array_values(array_unique($files));
    sort($files);
    return $files;
}

/** @return array<int, string> */
function pmssCustomerContextPhpFiles(string $dir, bool $recursive): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    if (!$recursive) {
        $iterator = new DirectoryIterator($dir);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if ($file->getExtension() !== 'php' || strpos($path, '/tests/') !== false || strpos($path, '/devristo/') !== false) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);
    return $files;
}

/** @return array<int, mixed> */
function pmssCustomerContextTokens(string $file): array
{
    $contents = file_get_contents($file);
    return token_get_all(is_string($contents) ? $contents : '');
}

/** @return array<string, string> */
function pmssCustomerContextFunctionDefinitions(array $files): array
{
    $definitions = [];
    foreach ($files as $file) {
        $tokens = pmssCustomerContextTokens($file);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!pmssCustomerContextTokenIs($tokens[$i], T_FUNCTION)) {
                continue;
            }
            $nameIndex = pmssCustomerContextNextSignificantIndex($tokens, $i);
            if ($nameIndex !== null && pmssCustomerContextTokenText($tokens[$nameIndex]) === '&') {
                $nameIndex = pmssCustomerContextNextSignificantIndex($tokens, $nameIndex);
            }
            if ($nameIndex !== null && pmssCustomerContextTokenIs($tokens[$nameIndex], T_STRING)) {
                $definitions[strtolower($tokens[$nameIndex][1])] = $file;
            }
        }
    }
    return $definitions;
}

/** @return array<string, true> */
function pmssCustomerContextBuiltinFunctions(): array
{
    $defined = get_defined_functions();
    $builtins = [];
    foreach (($defined['internal'] ?? []) as $function) {
        $builtins[strtolower($function)] = true;
    }
    return $builtins;
}

/** @return array<int, array{index:int,function:string,line:int}> */
function pmssCustomerContextBareFunctionCalls(array $tokens): array
{
    $calls = [];
    foreach ($tokens as $index => $token) {
        if (!pmssCustomerContextTokenIs($token, T_STRING)) {
            continue;
        }
        $next = pmssCustomerContextNextSignificantIndex($tokens, $index);
        if ($next === null || pmssCustomerContextTokenText($tokens[$next]) !== '(') {
            continue;
        }
        if (pmssCustomerContextIsDeclarationName($tokens, $index) || pmssCustomerContextIsMemberOrConstructorCall($tokens, $index)) {
            continue;
        }
        $calls[] = ['index' => $index, 'function' => $token[1], 'line' => $token[2]];
    }
    return $calls;
}

/** Return true when a missing function call is guarded by function_exists(). */
function pmssCustomerContextCallHasFunctionExistsGuard(array $tokens, int $callIndex, string $functionName): bool
{
    $guardNames = pmssCustomerContextGuardNamesForFunction($functionName);
    return pmssCustomerContextSameExpressionHasGuard($tokens, $callIndex, $guardNames)
        || pmssCustomerContextEnclosingIfHasGuard($tokens, $callIndex, $guardNames)
        || pmssCustomerContextPreviousIfReturnsWhenMissing($tokens, $callIndex, $guardNames);
}

/** Detect short-circuit guards like function_exists('x') && x(). */
function pmssCustomerContextSameExpressionHasGuard(array $tokens, int $callIndex, array $guardNames): bool
{
    $start = pmssCustomerContextPreviousBoundaryIndex($tokens, $callIndex);
    for ($i = $start + 1; $i < $callIndex; $i++) {
        $guard = pmssCustomerContextFunctionExistsGuardAt($tokens, $i, $guardNames);
        if ($guard === null) {
            continue;
        }
        $operator = pmssCustomerContextGuardOperatorBetween($tokens, $guard['end'], $callIndex);
        if ((!$guard['negated'] && ($operator === 'and' || $operator === 'ternary')) || ($guard['negated'] && $operator === 'or')) {
            return true;
        }
    }
    return false;
}

/** Detect enclosing guards like if (function_exists('x')) { x(); }. */
function pmssCustomerContextEnclosingIfHasGuard(array $tokens, int $callIndex, array $guardNames): bool
{
    $searchIndex = $callIndex;
    while (($openBrace = pmssCustomerContextNearestOpenBrace($tokens, $searchIndex)) !== null) {
        $closeParen = pmssCustomerContextPreviousSignificantIndex($tokens, $openBrace);
        $openParen = ($closeParen !== null && pmssCustomerContextTokenText($tokens[$closeParen]) === ')')
            ? pmssCustomerContextMatchingOpenParen($tokens, $closeParen)
            : null;
        if ($openParen !== null && pmssCustomerContextConditionHasFunctionExistsGuard($tokens, $openParen, $closeParen, $guardNames, false)) {
            return true;
        }
        $searchIndex = $openBrace;
    }
    return false;
}

/** @return array{end:int,negated:bool}|null */
function pmssCustomerContextFunctionExistsGuardAt(array $tokens, int $index, array $guardNames): ?array
{
    if (!pmssCustomerContextTokenIs($tokens[$index], T_STRING) || strtolower($tokens[$index][1]) !== 'function_exists') {
        return null;
    }
    $openParen = pmssCustomerContextNextSignificantIndex($tokens, $index);
    $literal = $openParen === null ? null : pmssCustomerContextNextSignificantIndex($tokens, $openParen);
    if ($openParen === null || $literal === null || pmssCustomerContextTokenText($tokens[$openParen]) !== '(' || !pmssCustomerContextTokenIs($tokens[$literal], T_CONSTANT_ENCAPSED_STRING)) {
        return null;
    }
    if (!in_array(strtolower(pmssCustomerContextStringLiteral($tokens[$literal])), $guardNames, true)) {
        return null;
    }
    $closeParen = pmssCustomerContextNextSignificantIndex($tokens, $literal);
    $previous = pmssCustomerContextPreviousSignificantIndex($tokens, $index);
    return [
        'end' => $closeParen !== null ? $closeParen : $literal,
        'negated' => $previous !== null && pmssCustomerContextTokenText($tokens[$previous]) === '!',
    ];
}

/** Return guard function names that prove the requested function family exists. */
function pmssCustomerContextGuardNamesForFunction(string $functionName): array
{
    $name = strtolower($functionName);
    $guards = [$name];
    if (strpos($name, 'zip_') === 0) {
        $guards[] = 'zip_open';
    }
    return array_values(array_unique($guards));
}

/** Detect a preceding if (!function_exists('x')) { return; } guard. */
function pmssCustomerContextPreviousIfReturnsWhenMissing(array $tokens, int $callIndex, array $guardNames): bool
{
    $boundary = pmssCustomerContextPreviousBoundaryIndex($tokens, $callIndex);
    if ($boundary < 0 || pmssCustomerContextTokenText($tokens[$boundary]) !== '}') {
        return false;
    }
    $openBrace = pmssCustomerContextMatchingOpenBraceForClose($tokens, $boundary);
    if ($openBrace === null || !pmssCustomerContextBlockHasTerminalReturn($tokens, $openBrace, $boundary)) {
        return false;
    }
    $closeParen = pmssCustomerContextPreviousSignificantIndex($tokens, $openBrace);
    $openParen = ($closeParen !== null && pmssCustomerContextTokenText($tokens[$closeParen]) === ')')
        ? pmssCustomerContextMatchingOpenParen($tokens, $closeParen)
        : null;
    return $openParen !== null
        && pmssCustomerContextConditionHasFunctionExistsGuard($tokens, $openParen, $closeParen, $guardNames, true);
}

/** Return true when an if condition contains the expected function_exists guard. */
function pmssCustomerContextConditionHasFunctionExistsGuard(array $tokens, int $openParen, int $closeParen, array $guardNames, bool $requireNegated): bool
{
    $beforeParen = pmssCustomerContextPreviousSignificantIndex($tokens, $openParen);
    if ($beforeParen === null || !pmssCustomerContextTokenIs($tokens[$beforeParen], T_IF)) {
        return false;
    }
    for ($i = $openParen + 1; $i < $closeParen; $i++) {
        $guard = pmssCustomerContextFunctionExistsGuardAt($tokens, $i, $guardNames);
        if ($guard !== null && $guard['negated'] === $requireNegated) {
            return true;
        }
    }
    return false;
}

/** Return true when a guard block exits before the guarded call can run. */
function pmssCustomerContextBlockHasTerminalReturn(array $tokens, int $openBrace, int $closeBrace): bool
{
    for ($i = $openBrace + 1; $i < $closeBrace; $i++) {
        if (pmssCustomerContextTokenIs($tokens[$i], T_RETURN) || pmssCustomerContextTokenIs($tokens[$i], T_EXIT)) {
            return true;
        }
    }
    return false;
}

/** Return the short-circuit operator between a guard and guarded call. */
function pmssCustomerContextGuardOperatorBetween(array $tokens, int $start, int $end): string
{
    for ($i = $start + 1; $i < $end; $i++) {
        if (pmssCustomerContextTokenIs($tokens[$i], T_BOOLEAN_AND) || pmssCustomerContextTokenText($tokens[$i]) === '&&') {
            return 'and';
        }
        if (pmssCustomerContextTokenIs($tokens[$i], T_BOOLEAN_OR) || pmssCustomerContextTokenText($tokens[$i]) === '||') {
            return 'or';
        }
        if (pmssCustomerContextTokenText($tokens[$i]) === '?') {
            return 'ternary';
        }
    }
    return '';
}

/** Return true when the T_STRING is the declared function name. */
function pmssCustomerContextIsDeclarationName(array $tokens, int $index): bool
{
    $previous = pmssCustomerContextPreviousSignificantIndex($tokens, $index);
    if ($previous !== null && pmssCustomerContextTokenIs($tokens[$previous], T_FUNCTION)) {
        return true;
    }
    $beforeReference = $previous !== null && pmssCustomerContextTokenText($tokens[$previous]) === '&'
        ? pmssCustomerContextPreviousSignificantIndex($tokens, $previous)
        : null;
    return $beforeReference !== null && pmssCustomerContextTokenIs($tokens[$beforeReference], T_FUNCTION);
}

/** Return true for method, static-method, and constructor-like calls. */
function pmssCustomerContextIsMemberOrConstructorCall(array $tokens, int $index): bool
{
    $previous = pmssCustomerContextPreviousSignificantIndex($tokens, $index);
    if ($previous === null) {
        return false;
    }
    if (pmssCustomerContextTokenIs($tokens[$previous], T_OBJECT_OPERATOR) || pmssCustomerContextTokenIs($tokens[$previous], T_DOUBLE_COLON) || pmssCustomerContextTokenIs($tokens[$previous], T_NEW)) {
        return true;
    }
    return pmssCustomerContextTokenText($tokens[$previous]) === '\\';
}

/** Return nearest unmatched opening brace before a token index. */
function pmssCustomerContextNearestOpenBrace(array $tokens, int $index): ?int
{
    $depth = 0;
    for ($i = $index - 1; $i >= 0; $i--) {
        $text = pmssCustomerContextTokenText($tokens[$i]);
        if ($text === '}') {
            $depth++;
        } elseif ($text === '{') {
            if ($depth === 0) {
                return $i;
            }
            $depth--;
        }
    }
    return null;
}

/** Return matching opening parenthesis for a closing parenthesis token. */
function pmssCustomerContextMatchingOpenParen(array $tokens, int $closeIndex): ?int
{
    $depth = 0;
    for ($i = $closeIndex; $i >= 0; $i--) {
        $text = pmssCustomerContextTokenText($tokens[$i]);
        if ($text === ')') {
            $depth++;
        } elseif ($text === '(') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return null;
}

/** Return matching opening brace for a closing brace token. */
function pmssCustomerContextMatchingOpenBraceForClose(array $tokens, int $closeIndex): ?int
{
    $depth = 0;
    for ($i = $closeIndex; $i >= 0; $i--) {
        $text = pmssCustomerContextTokenText($tokens[$i]);
        if ($text === '}') {
            $depth++;
        } elseif ($text === '{') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return null;
}

/** Return previous statement or block boundary index. */
function pmssCustomerContextPreviousBoundaryIndex(array $tokens, int $index): int
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (in_array(pmssCustomerContextTokenText($tokens[$i]), [';', '{', '}'], true)) {
            return $i;
        }
    }
    return -1;
}

/** Return the next non-whitespace/comment token index. */
function pmssCustomerContextNextSignificantIndex(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index + 1; $i < $count; $i++) {
        if (!pmssCustomerContextIsTrivia($tokens[$i])) {
            return $i;
        }
    }
    return null;
}

/** Return the previous non-whitespace/comment token index. */
function pmssCustomerContextPreviousSignificantIndex(array $tokens, int $index): ?int
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (!pmssCustomerContextIsTrivia($tokens[$i])) {
            return $i;
        }
    }
    return null;
}

/** Return true for whitespace and comments ignored by token adjacency checks. */
function pmssCustomerContextIsTrivia($token): bool
{
    return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

/** Return true when a token array has the requested token id. */
function pmssCustomerContextTokenIs($token, int $tokenId): bool
{
    return is_array($token) && $token[0] === $tokenId;
}

/** Return source text for array and single-character tokens. */
function pmssCustomerContextTokenText($token): string
{
    return is_array($token) ? (string) $token[1] : (string) $token;
}

/** Decode a simple PHP string literal token. */
function pmssCustomerContextStringLiteral(array $token): string
{
    $value = (string) $token[1];
    return stripcslashes(substr($value, 1, -1));
}

/** Return a stable repository-relative path when possible. */
function pmssCustomerContextRelativePath(string $rootDir, string $path): string
{
    $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');
    $path = str_replace('\\', '/', $path);
    return strpos($path, $rootDir.'/') === 0 ? substr($path, strlen($rootDir) + 1) : $path;
}
