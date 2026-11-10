<?php
/** Optional-function guard recognition; preserves the scanner's existing heuristics. @license GPL-3.0-only */

trait PmssCustomerContextGuards
{
    /** Keep statement, enclosing-if and preceding early-return guards in their original order. */
    private function guarded(array $tokens, int $call, string $name): bool
    {
        $guards = strpos($name, 'zip_') === 0 && $name !== 'zip_open' ? [$name, 'zip_open'] : [$name];
        if ($this->statementGuarded($tokens, $call, $guards)) return true;

        $cursor = $call;
        while (($brace = $this->matchOpen($tokens, $cursor - 1, '{', '}', 1)) !== null) {
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
            if (!$guard['negated'] && $this->bracelessIfGuards($tokens, $i, $call)) return true;
            for ($j = $guard['end'] + 1; $j < $call; $j++) {
                $text = $this->text($tokens[$j]);
                if ((!$guard['negated'] && ($this->is($tokens[$j], T_BOOLEAN_AND) || $text === '&&' || $text === '?'))
                    || ($guard['negated'] && ($this->is($tokens[$j], T_BOOLEAN_OR) || $text === '||'))
                ) return true;
            }
        }
        return false;
    }

    /** `if (function_exists('f')) f();`: the guard sits in the condition of a braceless if whose body holds the call. */
    private function bracelessIfGuards(array $tokens, int $guard, int $call): bool
    {
        for ($i = $this->boundary($tokens, $call) + 1; $i < $guard; $i++) {
            if (!$this->is($tokens[$i], T_IF)) continue;
            $open = $this->walk($tokens, $i, 1);
            if ($open === null || $this->text($tokens[$open]) !== '(') continue;
            $depth = 0;
            for ($j = $open; $j < $call; $j++) {
                $text = $this->text($tokens[$j]);
                if ($this->is($tokens[$j], T_BOOLEAN_OR) || $this->is($tokens[$j], T_LOGICAL_OR)) return false;
                if ($text === '(') $depth++;
                if ($text === ')' && --$depth === 0) return $j > $guard;
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
}
