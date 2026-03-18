<?php
/**
 * Welcome-page announcement RSS helpers.
 *
 * Keeps malformed remote feeds from bubbling XML parser failures into the
 * user-facing welcome page while leaving the render path easy to exercise in
 * hermetic development tests.
 *
 * @license GPL-3.0-only
 */

/**
 * Parse announcement RSS content into the welcome-page list-item HTML.
 *
 * Malformed XML, invalid UTF-8, and parser exceptions all fail soft to an
 * empty announcement list so the welcome page remains renderable.
 *
 * @param string $rssRaw Raw XML fetched from the announcement feed.
 * @return string
 */
function pmssWelcomeAnnouncementItemsHtmlBuildFromRaw(string $rssRaw): string
{
    if ($rssRaw === '') {
        return '';
    }

    if (
        (function_exists('mb_convert_encoding') && is_string($rssUtf8 = @mb_convert_encoding($rssRaw, 'UTF-8', 'UTF-8')))
        || (function_exists('iconv') && is_string($rssUtf8 = @iconv('UTF-8', 'UTF-8//IGNORE', $rssRaw)))
    ) {
        $rssRaw = $rssUtf8;
    }

    $restoreInternalErrors = function_exists('libxml_use_internal_errors');
    $clearErrors = function_exists('libxml_clear_errors');
    $previousInternalErrors = $restoreInternalErrors ? libxml_use_internal_errors(true) : false;

    if ($clearErrors) {
        libxml_clear_errors();
    }

    try {
        $rssXml = simplexml_load_string($rssRaw, 'SimpleXMLElement', LIBXML_NOCDATA);
    } catch (\Throwable $throwable) {
        $rssXml = false;
    } finally {
        if ($clearErrors) {
            libxml_clear_errors();
        }

        if ($restoreInternalErrors) {
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    if ($rssXml === false) {
        return '';
    }

    if (!isset($rssXml->channel->item)) {
        return '';
    }

    $itemsHtml = '';
    $renderedItems = 0;
    foreach ($rssXml->channel->item as $thisItem) {
        if (!isset($thisItem->pubDate, $thisItem->link, $thisItem->title)) {
            continue;
        }

        $dateText = date('d/m', strtotime((string) $thisItem->pubDate));
        $itemsHtml .= "<li>({$dateText}) <a href=\"".(string) $thisItem->link."\" target=\"_blank\">"
            .htmlspecialchars((string) $thisItem->title)."</a></li>\n";
        $renderedItems++;
        if ($renderedItems === 4) {
            break;
        }
    }

    return $itemsHtml;
}
