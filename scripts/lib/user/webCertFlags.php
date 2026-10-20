<?php
/** Certificate request flags are account state, including when root cron processes them. */
require_once __DIR__.'/../lighttpd/accountPath.php';

/** Atomically replace one fixed certificate flag under the account identity. */
function pmssWebCertFlagWrite(string $user, string $home, string $name, string $content): bool
{
    if (!in_array($name, ['.request-web-certs', '.request-web-certs.failed'], true)) return false;
    $path = rtrim($home, '/').'/'.$name;
    $template = rtrim($home, '/').'/.request-web-certs-tmp.XXXXXXXX';
    $command = 'tmp=$(mktemp -- '.escapeshellarg($template).') || exit 1; '
        .'trap \'rm -f -- "$tmp"\' EXIT; '
        .'cat > "$tmp" && test "$(wc -c < "$tmp")" -eq '.strlen($content)
        .' && chmod 0644 -- "$tmp" && mv -T -- "$tmp" '.escapeshellarg($path);
    return pmssAccountPathRun($user, $home, [$path, $template], $command, $content);
}

/** Remove issued flags as the account; root never unlinks entries in its home. */
function pmssWebCertFlagsClear(string $user, string $home): bool
{
    $flag = rtrim($home, '/').'/.request-web-certs';
    $failed = $flag.'.failed';
    return pmssAccountPathRun($user, $home, [$flag, $failed],
        'rm -f -- '.escapeshellarg($flag).' '.escapeshellarg($failed));
}
