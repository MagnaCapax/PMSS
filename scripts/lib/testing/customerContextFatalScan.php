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
    $override = getenv('PMSS_CUSTOMER_CONTEXT_SCAN_ROOT');
    $root = is_string($override) && trim($override) !== '' ? rtrim($override, '/') : dirname(__DIR__, 2);
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
    return (new PmssCustomerContextFatalScanner($root))->scan();
}

/** Encapsulates token walking so only the scanner entrypoints remain global. */
final class PmssCustomerContextFatalScanner
{
    private $root, $skel, $www, $builtins;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->skel = $this->root.'/etc/skel';
        $this->www = $this->skel.'/www';
        $this->builtins = array_fill_keys(array_map('strtolower', get_defined_functions()['internal'] ?? []), true);
    }

    /** @return array<int,array{file:string,line:int,function:string,operator_tree_source:string}> */
    public function scan(): array
    {
        if (!is_dir($this->www)) return [];

        $wwwFiles = $this->files($this->www, false);
        $customerDefs = $this->definitions(array_merge($this->files($this->skel, false), $wwwFiles));
        $operatorDefs = $this->definitions($this->files($this->root.'/scripts/lib', true));
        $violations = [];

        foreach ($wwwFiles as $file) {
            $tokens = $this->tokens($file);
            foreach ($this->calls($tokens) as $call) {
                $key = strtolower($call['function']);
                if (isset($this->builtins[$key]) || isset($customerDefs[$key]) || $this->guarded($tokens, $call['index'], $key)) continue;
                $violations[] = [
                    'file' => $this->relative($file),
                    'line' => $call['line'],
                    'function' => $call['function'],
                    'operator_tree_source' => isset($operatorDefs[$key]) ? $this->relative($operatorDefs[$key]) : '',
                ];
            }
        }
        return $violations;
    }

    private function files(string $dir, bool $recursive): array
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

    private function definitions(array $files): array
    {
        $defs = [];
        foreach (array_values(array_unique($files)) as $file) {
            $tokens = $this->tokens($file);
            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                if (!$this->is($tokens[$i], T_FUNCTION)) continue;
                $name = $this->walk($tokens, $i, 1);
                if ($name !== null && $this->text($tokens[$name]) === '&') $name = $this->walk($tokens, $name, 1);
                if ($name !== null && $this->is($tokens[$name], T_STRING)) $defs[strtolower($tokens[$name][1])] = $file;
            }
        }
        return $defs;
    }

    private function tokens(string $file): array
    {
        $content = file_get_contents($file);
        return token_get_all(is_string($content) ? $content : '');
    }

    private function calls(array $tokens): array
    {
        $calls = [];
        foreach ($tokens as $i => $token) {
            if (!$this->is($token, T_STRING)) continue;
            $next = $this->walk($tokens, $i, 1);
            if ($next === null || $this->text($tokens[$next]) !== '(' || $this->callExcluded($tokens, $i)) continue;
            $calls[] = ['index' => $i, 'function' => $token[1], 'line' => $token[2]];
        }
        return $calls;
    }

    private function guarded(array $tokens, int $call, string $name): bool
    {
        $guards = strpos($name, 'zip_') === 0 && $name !== 'zip_open' ? [$name, 'zip_open'] : [$name];
        if ($this->statementGuarded($tokens, $call, $guards)) return true;

        $cursor = $call;
        while (($brace = $this->nearestOpenBrace($tokens, $cursor)) !== null) {
            $range = $this->ifConditionRange($tokens, $brace);
            if ($range !== null && $this->conditionHasGuard($tokens, $range, $guards, false)) return true;
            $cursor = $brace;
        }

        $boundary = $this->boundary($tokens, $call);
        if ($boundary < 0 || $this->text($tokens[$boundary]) !== '}') return false;
        $openBrace = $this->matchOpen($tokens, $boundary, '{', '}');
        $range = $openBrace === null || !$this->blockReturns($tokens, $openBrace, $boundary)
            ? null
            : $this->ifConditionRange($tokens, $openBrace);
        return $range !== null && $this->conditionHasGuard($tokens, $range, $guards, true);
    }

    private function statementGuarded(array $tokens, int $call, array $guards): bool
    {
        for ($i = $this->boundary($tokens, $call) + 1; $i < $call; $i++) {
            $guard = $this->functionExistsAt($tokens, $i, $guards);
            if ($guard === null) continue;
            for ($j = $guard['end'] + 1; $j < $call; $j++) {
                $text = $this->text($tokens[$j]);
                if ((!$guard['negated'] && ($this->is($tokens[$j], T_BOOLEAN_AND) || $text === '&&' || $text === '?'))
                    || ($guard['negated'] && ($this->is($tokens[$j], T_BOOLEAN_OR) || $text === '||'))
                ) return true;
            }
        }
        return false;
    }

    /** @return array{start:int,end:int}|null */
    private function ifConditionRange(array $tokens, int $openBrace): ?array
    {
        $closeParen = $this->walk($tokens, $openBrace, -1);
        $openParen = ($closeParen !== null && $this->text($tokens[$closeParen]) === ')')
            ? $this->matchOpen($tokens, $closeParen, '(', ')')
            : null;
        $before = $openParen === null ? null : $this->walk($tokens, $openParen, -1);
        return $before !== null && $this->is($tokens[$before], T_IF)
            ? ['start' => $openParen + 1, 'end' => $closeParen]
            : null;
    }

    private function conditionHasGuard(array $tokens, array $range, array $guards, bool $negated): bool
    {
        for ($i = $range['start']; $i < $range['end']; $i++) {
            $guard = $this->functionExistsAt($tokens, $i, $guards);
            if ($guard !== null && $guard['negated'] === $negated) return true;
        }
        return false;
    }

    private function blockReturns(array $tokens, int $openBrace, int $closeBrace): bool
    {
        for ($i = $openBrace + 1; $i < $closeBrace; $i++) {
            if ($this->is($tokens[$i], T_RETURN) || $this->is($tokens[$i], T_EXIT)) return true;
        }
        return false;
    }

    /** @return array{end:int,negated:bool}|null */
    private function functionExistsAt(array $tokens, int $i, array $guards): ?array
    {
        if (!$this->is($tokens[$i], T_STRING) || strtolower($tokens[$i][1]) !== 'function_exists') return null;

        $open = $this->walk($tokens, $i, 1);
        $literal = $open === null ? null : $this->walk($tokens, $open, 1);
        if ($open === null || $literal === null || $this->text($tokens[$open]) !== '(' || !$this->is($tokens[$literal], T_CONSTANT_ENCAPSED_STRING)) return null;
        if (!in_array(strtolower(stripcslashes(substr((string) $tokens[$literal][1], 1, -1))), $guards, true)) return null;

        $previous = $this->walk($tokens, $i, -1);
        return ['end' => $this->walk($tokens, $literal, 1) ?? $literal, 'negated' => $previous !== null && $this->text($tokens[$previous]) === '!'];
    }

    private function callExcluded(array $tokens, int $i): bool
    {
        $prev = $this->walk($tokens, $i, -1);
        if ($prev === null) return false;
        if ($this->is($tokens[$prev], T_FUNCTION)) return true;

        $beforeRef = $this->text($tokens[$prev]) === '&' ? $this->walk($tokens, $prev, -1) : null;
        if ($beforeRef !== null && $this->is($tokens[$beforeRef], T_FUNCTION)) return true;

        return $this->is($tokens[$prev], T_OBJECT_OPERATOR)
            || $this->is($tokens[$prev], T_DOUBLE_COLON)
            || $this->is($tokens[$prev], T_NEW)
            || $this->text($tokens[$prev]) === '\\';
    }

    private function nearestOpenBrace(array $tokens, int $i): ?int
    {
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $text = $this->text($tokens[$j]);
            if ($text === '}') $depth++;
            if ($text === '{' && $depth-- === 0) return $j;
        }
        return null;
    }

    private function matchOpen(array $tokens, int $close, string $openText, string $closeText): ?int
    {
        $depth = 0;
        for ($i = $close; $i >= 0; $i--) {
            $text = $this->text($tokens[$i]);
            if ($text === $closeText) $depth++;
            if ($text === $openText && --$depth === 0) return $i;
        }
        return null;
    }

    private function boundary(array $tokens, int $i): int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (in_array($this->text($tokens[$j]), [';', '{', '}'], true)) return $j;
        }
        return -1;
    }

    private function walk(array $tokens, int $i, int $step): ?int
    {
        $step = $step < 0 ? -1 : 1;
        for ($j = $i + $step, $n = count($tokens); $j >= 0 && $j < $n; $j += $step) {
            if (!$this->trivia($tokens[$j])) return $j;
        }
        return null;
    }

    private function trivia($token): bool { return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); }
    private function is($token, int $id): bool { return is_array($token) && $token[0] === $id; }
    private function text($token): string { return is_array($token) ? (string) $token[1] : (string) $token; }

    private function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->root), '/');
        $path = str_replace('\\', '/', $path);
        return strpos($path, $root.'/') === 0 ? substr($path, strlen($root) + 1) : $path;
    }
}
