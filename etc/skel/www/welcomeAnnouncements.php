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
function pmssWelcomeAnnouncementItemsHtmlBuildFromRaw(string $rssRaw, string $category = '', int $limit = 4): string
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
 $limit = ($limit > 0 && $limit <= 20) ? $limit : 4;
 // Dates earn their ~8 characters only on time-sensitive notices. Evergreen article and wiki
 // rows are title-only: at 768px the column holds ~51 characters, so a (dd/mm) prefix costs
 // 10-16%% of the scan line and manufactures a staleness impression on content that has none.
 $showDate = ($category === '' || $category === 'announcement');
    foreach ($rssXml->channel->item as $thisItem) {
        if (!isset($thisItem->pubDate, $thisItem->link, $thisItem->title)) {
            continue;
        }

        // Third-party feed items can carry a hostile link. Restrict the scheme to http(s) and
        // escape the href exactly as the title already is (CWE-79).
        // One merged feed carries every category; a block renders only its own.
        if ($category !== '' && strtolower(trim((string) $thisItem->category)) !== $category) {
            continue;
        }

        $itemLink = (string) $thisItem->link;
        if (!preg_match('#^https?://#i', $itemLink)) {
            continue;
        }

        $datePrefix = $showDate ? '('.date('d/m', strtotime((string) $thisItem->pubDate)).') ' : '';
        $itemsHtml .= '<li class="pmss-feed-row">'.$datePrefix.'<a href="'.pmssCustomerHtmlAttr($itemLink).'" target="_blank">'
            .pmssCustomerHtmlAttr($thisItem->title)."</a></li>\n";
        if (++$renderedItems === $limit) {
            break;
        }
    }

    return $itemsHtml;
}
