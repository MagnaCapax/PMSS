<?php
/** Pure bencoded session transformation; file authority stays in sessionRewrite.php. */
/** @license GPL-3.0-only */
/**
 * Rewrite bencoded string values containing /home/<oldUser>/ paths.
 *
 * Returns null when the payload is malformed and cannot be safely rewritten.
 */
function pmssUserTransferRewriteBencodedHomePaths(string $payload, string $oldUser, string $newUser, int &$replacementCount = 0): ?string
{
    $replacementCount = 0;
    $needle = '/home/'.$oldUser.'/';
    $replacement = '/home/'.$newUser.'/';
    if ($needle === $replacement) {
        return $payload;
    }
    $offset = 0;
    $payloadLength = strlen($payload);
    $rewritten = '';
    while ($offset < $payloadLength) {
        $token = $payload[$offset];
        if ($token >= '0' && $token <= '9') {
            $digitsStart = $offset;
            $offset += strspn($payload, '0123456789', $offset);
            if ($offset >= $payloadLength || $payload[$offset] !== ':') {
                return null;
            }
            $lengthToken = substr($payload, $digitsStart, $offset - $digitsStart);
            if ($lengthToken === '' || strlen($lengthToken) > 9) {
                return null;
            }
            $stringLength = (int) $lengthToken;
            $offset++;
            if ($offset + $stringLength > $payloadLength) {
                return null;
            }
            $value = substr($payload, $offset, $stringLength);
            $offset += $stringLength;
            $value = str_replace($needle, $replacement, $value, $hits);
            $replacementCount += $hits;
            $rewritten .= strlen($value).':'.$value;
            continue;
        }
        if ($token === 'i') {
            $intEnd = strpos($payload, 'e', $offset + 1);
            if ($intEnd === false) {
                return null;
            }
            $rewritten .= substr($payload, $offset, $intEnd - $offset + 1);
            $offset = $intEnd + 1;
            continue;
        }
        if ($token === 'l' || $token === 'd' || $token === 'e') {
            $rewritten .= $token;
            $offset++;
            continue;
        }
        return null;
    }
    return $rewritten;
}
