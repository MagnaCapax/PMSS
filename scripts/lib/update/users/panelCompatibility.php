<?php
/** Known legacy panel-index detection and guarded refresh. @license GPL-3.0-only */

/**
 * Detect legacy panel copies that fatal on PHP 8.2 because frameData is null.
 */
function pmssUserPanelIndexNeedsFrameDataCompatRefresh(string $content): bool
{
    return strpos($content, '$frameData = array();') === false
        && strpos($content, '$frames = array_merge($frames, $frameData);') !== false;
}

/**
 * Force-refresh only the known broken legacy panel index.php variant.
 *
 * This explicit migration runs before the broad updateUserFile() SHA1 sync so
 * the PHP 8 frameData repair stays visible as its own compatibility path.
 */
function pmssUserRefreshPanelIndexForFrameDataCompat(array $ctx): void
{
    [$user, $home] = pmssUserContextIdentity($ctx);
    if ($user === '' || $home === '') {
        return;
    }

    $targetFile = $home.'/www/index.php';
    if (!pmssUserPathWithinHomeRoot($targetFile) || !pmssRegularFilePathIsReadable($targetFile)) {
        return;
    }

    $sourceFile = pmssSkeletonBase().'/www/index.php';
    if (!is_file($sourceFile)) {
        return;
    }

    $sourceContent = @file_get_contents($sourceFile);
    $targetContent = pmssReadRegularFileContents($targetFile);
    if (!is_string($sourceContent)
        || $targetContent === null
        || !pmssUserPanelIndexNeedsFrameDataCompatRefresh($targetContent)
        || strpos($sourceContent, '$frameData = array();') === false
        || strpos($sourceContent, 'function pmssFrameOpensInNewWindow(array $frame)') === false) {
        return;
    }

    if (copyToUserSpace($sourceFile, $targetFile, $user)) {
        logMessage("[user:{$user}] Updated legacy panel index.php for frameData compatibility");
    }
}

/**
 * Verify the coupled media-stack panel files after account delivery (Refs #953).
 *
 * Exact readback detects stale siblings without a brittle function-name list.
 * Parse bytes only: customer-owned PHP must never be included by the updater.
 */
function pmssUserMediaStackPanelDeliveryCheck(array $ctx): bool
{
    [$user, $home] = pmssUserContextIdentity($ctx);
    foreach (['mediaStack.php', 'userMediaStackPanel.php'] as $file) {
        $target = $home.'/www/'.$file;
        $source = pmssReadRegularFileContentsVerified(pmssSkeletonBase().'/www/'.$file, null, 262144);
        $delivered = pmssUserPathWithinHomeRoot($target) && pmssPathTargetIsSafe($target, false, false, false)
            ? pmssReadRegularFileContentsVerified($target, null, 262144) : null;
        if ($source === null || $delivered === null || $source !== $delivered) {
            logMessage("[user:{$user}] [WARN] Media-stack panel delivery incomplete: {$file} missing, unsafe, unreadable or differs from skeleton");
            return false;
        }
        try {
            token_get_all($delivered, TOKEN_PARSE);
        } catch (\ParseError $error) {
            // Do not log customer-controlled source text or parser excerpts.
            logMessage("[user:{$user}] [WARN] Media-stack panel delivery incomplete: {$file} has invalid PHP syntax");
            return false;
        }
    }

    return true;
}
