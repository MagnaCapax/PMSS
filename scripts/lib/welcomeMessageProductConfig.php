<?php
/**
 * Product-level welcome message configuration helpers.
 *
 * Stores product message templates in `/etc/seedbox/config/welcomeMessages.json`.
 * The file may either be a plain `{product: template}` map or a root object
 * with a nested `products` map.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/welcomeMessage.php';
require_once __DIR__.'/lighttpd/userFileWrite.php';

/**
 * Set or clear a product-level welcome message template.
 */
function pmssWelcomeProductMessageSet(
    string $productKey,
    string $template,
    string $productMessagesPath = '/etc/seedbox/config/welcomeMessages.json'
): bool {
    $normalizedProductKey = trim($productKey);
    if ($normalizedProductKey === '') {
        return false;
    }

    $rootMap = pmssWelcomeReadJson($productMessagesPath);
    $productMap = pmssWelcomeProductMessageMap($rootMap);

    if (trim($template) === '') {
        unset($productMap[$normalizedProductKey]);
    } else {
        $productMap[$normalizedProductKey] = $template;
    }
    ksort($productMap, SORT_STRING);

    $rootMap = is_array($rootMap['products'] ?? null)
        ? array_replace($rootMap, ['products' => $productMap])
        : $productMap;

    if (!is_string($encoded = json_encode($rootMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        return false;
    }

    return pmssReplaceUserFile(
        $productMessagesPath,
        $encoded.PHP_EOL,
        static function (string $temporaryPath): void {
            @chmod($temporaryPath, 0640);
        }
    );
}
