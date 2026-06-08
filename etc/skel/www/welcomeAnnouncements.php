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
require_once __DIR__.'/scriptsInc.php';

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

    $previousInternalErrors = function_exists('libxml_use_internal_errors') ? libxml_use_internal_errors(true) : null;

    if (function_exists('libxml_clear_errors')) { libxml_clear_errors(); }

    try {
        $rssXml = simplexml_load_string($rssRaw, 'SimpleXMLElement', LIBXML_NOCDATA);
    } catch (\Throwable $throwable) {
        $rssXml = false;
    } finally {
        if (function_exists('libxml_clear_errors')) { libxml_clear_errors(); }

        if ($previousInternalErrors !== null) {
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    if ($rssXml === false || !isset($rssXml->channel->item)) {
        return '';
    }

    $itemsHtml = '';
    $renderedItems = 0;
    foreach ($rssXml->channel->item as $thisItem) {
        if (!isset($thisItem->pubDate, $thisItem->link, $thisItem->title)) {
            continue;
        }

        $itemsHtml .= '<li>('.date('d/m', strtotime((string) $thisItem->pubDate)).') <a href="'.(string) $thisItem->link.'" target="_blank">'
            .pmssCustomerHtmlAttr($thisItem->title)."</a></li>\n";
        if (++$renderedItems === 4) {
            break;
        }
    }

    return $itemsHtml;
}
