<?php
namespace PMSS\Tests;

/** Derive route write sites from PHP tokens, excluding helper definitions. */
function pmssFilemanagerUnguardedWrites(string $source): array
{
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) continue;
        $tokens[] = is_array($token) ? [$token[0], $token[1], $token[2]] : [0, $token, 0];
    }
    $text = static function (int $start, int $end) use (&$tokens): string {
        return implode('', array_column(array_slice($tokens, $start, $end - $start), 1));
    };
    $pairs = $stack = [];
    foreach ($tokens as $i => $token) {
        if (in_array($token[1], ['(', '[', '{', '${'], true)) $stack[] = $i;
        elseif (in_array($token[1], [')', ']', '}'], true) && $stack !== []) $pairs[array_pop($stack)] = $i;
    }

    // Definitions contain the implementations of writes, not request dispatch.
    $definitions = $branches = [];
    foreach ($tokens as $i => $token) {
        if (in_array($token[0], [T_FUNCTION, T_CLASS, T_TRAIT], true)
            && ($tokens[$i - 1][0] ?? 0) !== T_DOUBLE_COLON) {
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j][1] !== '{'; ++$j) {}
            if (isset($pairs[$j])) $definitions[] = [$j, $pairs[$j]];
        }
        if (!in_array($token[0], [T_IF, T_ELSEIF], true)) continue;
        $open = $i + 1;
        if (!isset($pairs[$open])) continue;
        $body = $pairs[$open] + 1;
        if (($tokens[$body][1] ?? '') === '{') {
            $end = $pairs[$body] ?? $body;
            ++$body;
        } else {
            for ($end = $body; isset($tokens[$end]) && $tokens[$end][1] !== ';'; ++$end) {}
        }
        $header = $text($open, $pairs[$open] + 1);
        $branches[] = ['start' => $open, 'body' => $body, 'end' => $end, 'header' => $header];
    }

    $writes = ['copy', 'rename', 'fm_rdelete', 'fm_rename', 'fm_rcopy', 'fm_mkdir', 'mkdir',
        'move_uploaded_file', 'chmod', 'extractto', 'save', 'create', 'file_put_contents',
        'unlink', 'fm_remote_url_download', 'fwrite'];
    $offenders = [];
    foreach ($tokens as $i => $token) {
        if ($token[0] !== T_STRING || ($tokens[$i + 1][1] ?? '') !== '(') continue;
        if (($tokens[$i - 1][0] ?? 0) === T_FUNCTION) continue;
        $name = strtolower($token[1]);
        $call = $text($i, ($pairs[$i + 1] ?? $i + 1) + 1);
        if ($name === 'fopen') {
            if (!preg_match('/,[\'"][wa][^\'"]*[\'"]\)$/', $call)) continue;
        } elseif (!in_array($name, $writes, true)) continue;
        foreach ($definitions as [$start, $end]) {
            if ($i > $start && $i < $end) continue 2;
        }

        $parents = array_values(array_filter($branches, static function (array $branch) use ($i): bool {
            return $i > $branch['start'] && $i < $branch['end'];
        }));
        $guarded = false;
        foreach ($parents as $parent) {
            if ($i < $parent['body']) continue;
            // A guard must be the first statement, before any dispatch or write.
            $body = $text($parent['body'], $parent['end']);
            if (strpos($body, 'fm_action_required();') === 0) $guarded = true;
            // Recognize the AJAX prefix only with its terminating type check and
            // strict read-only exceptions. A write added to either exception fails.
            $prefix = '~^if\(!is_string\(\$_POST\[\'type\'\]\?\?null\)\)\{http_response_code\(403\);exit\(\'[^\']*\'\);\}'
                .'if\(!in_array\(\$_POST\[\'type\'\],array\(\'search\',\'pwdhash\'\),true\)\)\{fm_action_required\(\);\}~';
            if (!preg_match($prefix, $body) || preg_match('/\$_POST\[\'type\'\]==(?!=)/', $body)) continue;
            $readOnly = false;
            foreach ($parents as $branch) {
                if (preg_match('/\$_POST\[\'type\'\]===([\'"])(search|pwdhash)\1/', $branch['header'])) $readOnly = true;
            }
            if (!$readOnly) $guarded = true;
        }
        if (!$guarded) $offenders[] = 'line '.$token[2].': '.$name;
    }
    return $offenders;
}
