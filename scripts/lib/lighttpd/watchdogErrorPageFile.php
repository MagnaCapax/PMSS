<?php
/**
 * Lighttpd watchdog per-user 502 page file helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/userFileWrite.php';

/** Render a static browser/plain-text 502 page for a specific diagnosis. */
function pmssLighttpdWatchdogRenderErrorPage(string $reasonKey): string
{
    $messages = array(
        'suspended' => array('headline' => 'Your account is suspended.', 'detail' => 'Check your invoices or contact support before retrying.'),
        'quota' => array('headline' => 'Your disk quota is full.', 'detail' => 'Connect with SFTP and delete files to free space, then retry.'),
        'inode' => array('headline' => 'Server-wide storage pressure is blocking web access.', 'detail' => 'No action is needed from your side; our team is already on it.'),
        'config' => array('headline' => 'A web configuration error was detected.', 'detail' => 'Please contact support so we can correct the service configuration.'),
        'php' => array('headline' => 'Your PHP backend is not responding.', 'detail' => 'An automatic restart is in progress; retry in 1-2 minutes.'),
        'restarting' => array('headline' => 'Your web service is restarting.', 'detail' => 'This usually settles within 1-2 minutes; please retry shortly.'),
    );
    $message = $messages[$reasonKey] ?? $messages['restarting'];
    $headline = htmlspecialchars($message['headline'], ENT_QUOTES, 'UTF-8');
    $detail = htmlspecialchars($message['detail'], ENT_QUOTES, 'UTF-8');

    return "502 - Bad Gateway\n\n"
        ."Your web interface could not be loaded.\n\n"
        ."Specific status:\n"
        .'- '.$message['headline']."\n"
        .'- '.$message['detail']."\n\n"
        ."Please wait 1-2 minutes and try again.\n"
        ."If the issue persists, please open a support ticket.\n\n"
        ."---\n"
        .'<!-- Visual page for browsers below -->' . "\n"
        ."<!DOCTYPE html>\n"
        ."<html>\n"
        ."<head>\n"
        ."  <meta charset=\"UTF-8\">\n"
        ."  <title>502 - Bad Gateway</title>\n"
        ."</head>\n"
        ."<body>\n"
        ."<div class=\"container\">\n"
        ."  <span class=\"error-message\">502 - Bad Gateway</span>\n"
        ."  <br />\n"
        ."  <div class=\"content-container\">\n"
        ."    <div id=\"image-container\">\n"
        ."      <img alt=\"502 - Bad Gateway\" id=\"error-image\" />\n"
        ."    </div>\n\n"
        ."    <div class=\"error-description\">\n"
        ."      <p>The sage is reconnecting the pathways.</p>\n"
        ."      <p><strong>Status:</strong> {$headline}</p>\n"
        ."      <p>{$detail}</p>\n"
        ."      <p>Wait 1-2 minutes and retry. If this continues, open a support ticket.</p>\n"
        ."      <p><a href=\"/\">Return to the main page.</a></p>\n"
        ."    </div>\n"
        ."  </div>\n"
        ."</div>\n\n"
        ."<script>\n"
        ."  const variants = ['/502_images/502-1.png', '/502_images/502-2.png', '/502_images/502-3.png', '/502_images/502-4.png', '/502_images/502-5.png', '/502_images/502-6.png', '/502_images/502-7.png', '/502_images/502-8.png', '/502_images/502-9.png', '/502_images/502-10.png', '/502_images/502-11.png', '/502_images/502-12.png', '/502_images/502-13.png'];\n"
        ."  const imageElement = document.getElementById('error-image');\n"
        ."  imageElement.src = variants[Math.floor(Math.random() * variants.length)];\n"
        ."  imageElement.classList.add('error-image');\n"
        ."  imageElement.addEventListener('error', function () {\n"
        ."    imageElement.style.display = 'none';\n"
        ."  });\n\n"
        ."  const link = document.createElement('link');\n"
        ."  link.rel = 'stylesheet';\n"
        ."  link.type = 'text/css';\n"
        ."  link.href = '/css/error-styles.css';\n"
        ."  document.head.appendChild(link);\n"
        ."</script>\n"
        ."</body>\n"
        ."</html>\n";
}

/** Write or refresh a per-user 502 page inside the selected web root. */
function pmssLighttpdWatchdogWriteErrorPage(string $username, string $reasonKey, string $webRoot = '/var/www'): bool
{
    return pmssReplaceUserFileWithMetadata(
        rtrim($webRoot, '/').'/error-502-'.$username.'.html',
        pmssLighttpdWatchdogRenderErrorPage($reasonKey),
        0644
    );
}

/** Remove a per-user 502 page when the tenant web stack recovers. */
function pmssLighttpdWatchdogDeleteErrorPage(string $username, string $webRoot = '/var/www'): bool
{
    $path = rtrim($webRoot, '/').'/error-502-'.$username.'.html';
    if (!file_exists($path)) {
        return true;
    }

    return pmssUserFilePathIsSafe($path) && !is_link($path) && @unlink($path);
}
