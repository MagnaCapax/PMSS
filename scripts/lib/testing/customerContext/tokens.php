<?php
/** Token navigation and bare-call classification shared by both scan scopes. @license GPL-3.0-only */

trait PmssCustomerContextTokens
{
    private function tokens(string $file): array
    {
        return token_get_all((string) file_get_contents($file));
    }

    /** Consume calls in token order without maintaining a second per-file call list. */
    private function calls(array $tokens): \Generator
    {
        foreach ($tokens as $i => $token) {
            if (!$this->is($token, T_STRING)) continue;
            $next = $this->walk($tokens, $i, 1);
            if ($next === null || $this->text($tokens[$next]) !== '(' || $this->callExcluded($tokens, $i)) continue;
            yield ['index' => $i, 'function' => $token[1], 'line' => $token[2]];
        }
    }

    private function callExcluded(array $tokens, int $i): bool
    {
        $prev = $this->walk($tokens, $i, -1);
        if ($prev === null) return false;
        $beforeRef = $this->text($tokens[$prev]) === '&' ? $this->walk($tokens, $prev, -1) : null;
        return ($beforeRef !== null && $this->is($tokens[$beforeRef], T_FUNCTION))
            || (is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true))
            || $this->text($tokens[$prev]) === '\\';
    }

    /** Depth one finds the enclosing brace; depth zero matches a closing delimiter at the cursor. */
    private function matchOpen(array $tokens, int $close, string $openText, string $closeText, int $depth = 0): ?int
    {
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
}
