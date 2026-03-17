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

    if (function_exists('mb_convert_encoding')) {
        $rssUtf8 = @mb_convert_encoding($rssRaw, 'UTF-8', 'UTF-8');
        if ($rssUtf8 !== false) {
            $rssRaw = $rssUtf8;
        }
    } elseif (function_exists('iconv')) {
        $rssUtf8 = @iconv('UTF-8', 'UTF-8//IGNORE', $rssRaw);
        if ($rssUtf8 !== false) {
            $rssRaw = $rssUtf8;
        }
    }

    $restoreInternalErrors = function_exists('libxml_use_internal_errors');
    $previousInternalErrors = false;

    if ($restoreInternalErrors) {
        $previousInternalErrors = libxml_use_internal_errors(true);
    }

    if (function_exists('libxml_clear_errors')) {
        libxml_clear_errors();
    }

    try {
        $rssXml = simplexml_load_string($rssRaw, 'SimpleXMLElement', LIBXML_NOCDATA);
    } catch (\Throwable $throwable) {
        $rssXml = false;
    }

    if (function_exists('libxml_clear_errors')) {
        libxml_clear_errors();
    }

    if ($restoreInternalErrors) {
        libxml_use_internal_errors($previousInternalErrors);
    }

    if ($rssXml === false) {
        return '';
    }

    $rssFeedJson = json_encode($rssXml);
    if (!is_string($rssFeedJson) || $rssFeedJson === '') {
        return '';
    }

    $rssFeed = json_decode($rssFeedJson, true);
    if (!isset($rssFeed['channel']['item']) || !is_array($rssFeed['channel']['item'])) {
        return '';
    }

    $itemsHtml = '';
    $items = $rssFeed['channel']['item'];
    if (isset($items['pubDate'], $items['link'], $items['title'])) {
        $items = array($items);
    }

    $items = array_slice($items, 0, 4, true);
    foreach ($items as $thisItem) {
        if (!isset($thisItem['pubDate'], $thisItem['link'], $thisItem['title'])) {
            continue;
        }

        $dateText = date('d/m', strtotime($thisItem['pubDate']));
        $title = htmlspecialchars($thisItem['title']);
        $link = $thisItem['link'];
        $itemsHtml .= "<li>({$dateText}) <a href=\"{$link}\" target=\"_blank\">{$title}</a></li>\n";
    }

    return $itemsHtml;
}
