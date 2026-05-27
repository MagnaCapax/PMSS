<?php
/** Shared tracker-cleaner policy and in-memory torrent mutation helpers. */

function pmssTrackerCleanerTimestamp(): string { return '['.date('Y-m-d H:i:s').']'; }

function pmssTrackerCleanerLog(string $message): void { echo pmssTrackerCleanerTimestamp().' '.$message."\n"; }

/** @return array<int, string> */
function pmssTrackerCleanerFilterList(): array
{
    return ['udp://public.popcorn-tracker.org:6969/announce', 'http://sub4all.org', 'udp://tracker.openbittorrent.com:80/announce', 'udp://tracker.publicbt.com', 'udp://tracker.ccc.de', 'http://tracker.tntvillage.scambioetico.org', 'http://exodus.desync.com', 'http://tracker.ftfansub.net', 'http://nyaa.tracker.wf', 'udp://tracker.istole.it', 'udp://mgtracker.org'];
}

/** @return array<int, string> */
function pmssTrackerCleanerFilterDomainList(): array
{
    return ['legittorrents.info', 'tracker.openbittorrent.com', 'tracker.leechers-paradise.org', 'tracker.coppersurfer.tk', '9.rarbg.', '10.rarbg.', 'tracker.eddie4.nl', 'tracker.supertracker.net', 'concen.org', 'tracker.tfile.me', 'tracker.cyberia.is'];
}

function pmssTrackerCleanerShouldScrubTracker(string $trackerUrl, array $filterList, array $filterDomainList): bool
{
    foreach ($filterList as $filter) {
        if ($filter !== '' && strpos($trackerUrl, $filter) !== false) return true;
    }
    foreach ($filterDomainList as $domain) {
        if ($domain !== '' && stripos($trackerUrl, $domain) !== false) return true;
    }
    return false;
}

function pmssTrackerCleanerLogValue($value): string { return str_replace(["\r", "\n"], ' ', (string) $value); }

/**
 * Remove blocked trackers from the announce-list tiers while preserving order.
 *
 * @return array{changed:bool,announce_list:array<int,array<int,string>>,removed_trackers:array<int,string>,remaining_trackers:array<int,string>}
 */
function pmssTrackerCleanerPruneAnnounceList(array $announceList, array $filterList, array $filterDomainList): array
{
    $newList = [];
    $removed = [];
    $remaining = [];
    $changed = false;

    foreach ($announceList as $tier) {
        if (!is_array($tier)) continue;
        $tierNew = [];
        foreach ($tier as $trackerUrl) {
            if (!is_string($trackerUrl)) continue;
            if (pmssTrackerCleanerShouldScrubTracker($trackerUrl, $filterList, $filterDomainList)) {
                $removed[$trackerUrl] = true;
                $changed = true;
                continue;
            }
            $tierNew[] = $trackerUrl;
            $remaining[$trackerUrl] = true;
        }
        if ($tierNew !== []) $newList[] = $tierNew;
    }

    return ['changed' => $changed, 'announce_list' => $newList, 'removed_trackers' => array_keys($removed), 'remaining_trackers' => array_keys($remaining)];
}

function pmssTrackerCleanerFirstAnnounceReplacement(array $announceList): ?string { foreach ($announceList as $tier) { if (isset($tier[0]) && is_string($tier[0]) && $tier[0] !== '') return $tier[0]; } return null; }

/**
 * Apply tracker cleanup to a torrent-like object and return the intended result.
 *
 * @return array{changed:bool,would_trackerless:bool,removed_trackers:array<int,string>,remaining_trackers:array<int,string>,events:array<int,string>}
 */
function pmssTrackerCleanerScrubTorrent($torrent, array $filterList, array $filterDomainList): array
{
    $list = pmssTrackerCleanerPruneAnnounceList($torrent->getAnnounceList(), $filterList, $filterDomainList);
    if ($list['changed']) $torrent->setAnnounceList($list['announce_list']);
    $changed = $list['changed'];
    $removed = array_fill_keys($list['removed_trackers'], true);
    $remaining = array_fill_keys($list['remaining_trackers'], true);
    $events = [];
    $announce = $torrent->getAnnounce();
    if (is_string($announce) && $announce !== '' && pmssTrackerCleanerShouldScrubTracker($announce, $filterList, $filterDomainList)) {
        $removed[$announce] = true;
        $replacement = pmssTrackerCleanerFirstAnnounceReplacement($list['announce_list']);
        if ($replacement !== null) {
            $torrent->setAnnounce($replacement);
            $changed = true;
            $remaining[$replacement] = true;
            $events[] = 'announce_replaced from='.$announce.' to='.$replacement;
        } else {
            $events[] = 'announce_scrubbed_no_replacement announce='.$announce;
        }
    } elseif (is_string($announce) && $announce !== '') {
        $remaining[$announce] = true;
    }
    return ['changed' => $changed, 'would_trackerless' => $changed && count($remaining) === 0, 'removed_trackers' => array_keys($removed), 'remaining_trackers' => array_keys($remaining), 'events' => $events];
}

function pmssTrackerCleanerRemovedTrackersText(array $removedTrackers): string { return $removedTrackers === [] ? '(unknown)' : implode(', ', $removedTrackers); }

function pmssTrackerCleanerCommentWithMarker(string $comment): string
{
    $marker = 'Trackers cleaned by PMSS tracker cleaner';
    if (strpos($comment, $marker) !== false) return $comment;
    return $comment.'; '.$marker.' (https://github.com/MagnaCapax/PMSS/blob/main/docs/tracker-cleaner.md)';
}
