<?php
/**
 * Contextual welcome-page message helpers.
 *
 * Provides per-user override support and product-level fallback messages for
 * `etc/skel/www/welcome.php` while keeping lookup logic testable.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';

/**
 * Resolve the per-user welcome-message override path.
 */
function pmssWelcomeUserMessagePath(string $userHome): string
{
    return pmssCustomerHomePath($userHome, '.config/welcome-message.html');
}

/**
 * Read a per-user welcome-message override from the managed file path.
 */
function pmssWelcomeUserMessageRead(string $userHome): string
{
    $path = pmssWelcomeUserMessagePath($userHome);
    if (!pmssCustomerPathIsSafe($path) || !is_file($path) || is_link($path)) {
        return '';
    }

    $content = @file_get_contents($path);
    return (is_string($content) && trim($content) !== '') ? $content : '';
}

/**
 * Resolve and render the contextual welcome message for a user.
 */
function pmssWelcomeMessageForUser(
    array $quotaInfo,
    string $userHome,
    string $username,
    string $productMessagesPath = '/etc/seedbox/config/welcomeMessages.json'
): string {
    $userHome = rtrim($userHome, '/');
    $userConfig = pmssJsonFileReadAssoc($userHome.'/.config/pmss-user.json', true) ?? [];
    $productKey = '';
    foreach (['product', 'productName'] as $candidateKey) {
        if (is_string($candidateValue = $userConfig[$candidateKey] ?? null) && ($productKey = trim($candidateValue)) !== '') {
            break;
        }
    }
    if ($productKey === '' && is_file($productFile = pmssCustomerHomePath($userHome, '.product')) && !is_link($productFile) && pmssCustomerPathIsSafe($productFile)) {
        $productKey = trim((string) @file_get_contents($productFile));
    }

    $template = pmssWelcomeUserMessageRead($userHome);
    if ($template === '' && is_string($userConfig['welcomeMessage'] ?? null) && trim($userConfig['welcomeMessage']) !== '') {
        $template = $userConfig['welcomeMessage'];
    }
    if ($template === '' && $productKey !== '') {
        $messageRootMap = pmssJsonFileReadAssoc($productMessagesPath, true) ?? [];
        $messageMap = is_array($messageRootMap['products'] ?? null) ? $messageRootMap['products'] : $messageRootMap;
        if (!is_string($template = $messageMap[$productKey] ?? null)) {
            $template = '';
            foreach ($messageMap as $mapKey => $mapValue) {
                if (!is_string($mapKey) || !is_string($mapValue) || strcasecmp($mapKey, $productKey) !== 0) {
                    continue;
                }

                $template = $mapValue;
                break;
            }
        }
    }
    if ($template === '') {
        return '';
    }

    $quota = is_numeric($quotaInfo['totalSpace'] ?? null) && ($quotaGiB = ((float) $quotaInfo['totalSpace']) / 1073741824) > 0
        ? round($quotaGiB, 1).' GiB'
        : '';

    return strtr($template, [
        '{{username}}' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
        '{{quota}}'    => htmlspecialchars($quota, ENT_QUOTES, 'UTF-8'),
        '{{ramMiB}}'   => htmlspecialchars(is_numeric($userConfig['ramMiB'] ?? null) ? (string) ((int) $userConfig['ramMiB']) : '', ENT_QUOTES, 'UTF-8'),
        '{{product}}'  => htmlspecialchars($productKey, ENT_QUOTES, 'UTF-8'),
    ]);
}
