<?php
/** release-5.3.0 upgrade.cpp handleSettingKeys mappings removed by migration 9. */

/** New path => old path; restore only missing old names, preserving account edits. */
function pmssQbittorrentLegacyKeys(): array
{
    return [
        'AddNewTorrentDialog/Enabled' => 'Preferences/Downloads/NewAdditionDialog',
        'AddNewTorrentDialog/Expanded' => 'AddNewTorrentDialog/expanded',
        'AddNewTorrentDialog/Position' => 'AddNewTorrentDialog/y',
        'AddNewTorrentDialog/SavePathHistory' => 'TorrentAdditionDlg/save_path_history',
        'AddNewTorrentDialog/TopLevel' => 'Preferences/Downloads/NewAdditionDialogFront',
        'AddNewTorrentDialog/TreeHeaderState' => 'AddNewTorrentDialog/qt5/treeHeaderState',
        'AddNewTorrentDialog/Width' => 'AddNewTorrentDialog/width',
        'BitTorrent/Session/AddExtensionToIncompleteFiles' => 'Preferences/Downloads/UseIncompleteExtension',
        'BitTorrent/Session/AdditionalTrackers' => 'Preferences/Bittorrent/TrackersList',
        'BitTorrent/Session/AddTorrentPaused' => 'Preferences/Downloads/StartInPause',
        'BitTorrent/Session/AddTrackersEnabled' => 'Preferences/Bittorrent/AddTrackers',
        'BitTorrent/Session/AlternativeGlobalDLSpeedLimit' => 'Preferences/Connection/GlobalDLLimitAlt',
        'BitTorrent/Session/AlternativeGlobalUPSpeedLimit' => 'Preferences/Connection/GlobalUPLimitAlt',
        'BitTorrent/Session/AnnounceIP' => 'Preferences/Connection/InetAddress',
        'BitTorrent/Session/AnnounceToAllTrackers' => 'Preferences/Advanced/AnnounceToAllTrackers',
        'BitTorrent/Session/AnonymousModeEnabled' => 'Preferences/Advanced/AnonymousMode',
        'BitTorrent/Session/BandwidthSchedulerEnabled' => 'Preferences/Scheduler/Enabled',
        'BitTorrent/Session/DefaultSavePath' => 'Preferences/Downloads/SavePath',
        'BitTorrent/Session/DHTEnabled' => 'Preferences/Bittorrent/DHT',
        'BitTorrent/Session/DiskCacheSize' => 'Preferences/Downloads/DiskWriteCacheSize',
        'BitTorrent/Session/DiskCacheTTL' => 'Preferences/Downloads/DiskWriteCacheTTL',
        'BitTorrent/Session/Encryption' => 'Preferences/Bittorrent/Encryption',
        'BitTorrent/Session/FinishedTorrentExportDirectory' => 'Preferences/Downloads/FinishedTorrentExportDir',
        'BitTorrent/Session/ForceProxy' => 'Preferences/Connection/ProxyForce',
        'BitTorrent/Session/GlobalDLSpeedLimit' => 'Preferences/Connection/GlobalDLLimit',
        'BitTorrent/Session/GlobalMaxRatio' => 'Preferences/Bittorrent/MaxRatio',
        'BitTorrent/Session/GlobalUPSpeedLimit' => 'Preferences/Connection/GlobalUPLimit',
        'BitTorrent/Session/IgnoreLimitsOnLAN' => 'Preferences/Advanced/IgnoreLimitsLAN',
        'BitTorrent/Session/IgnoreSlowTorrentsForQueueing' => 'Preferences/Queueing/IgnoreSlowTorrents',
        'BitTorrent/Session/IncludeOverheadInLimits' => 'Preferences/Advanced/IncludeOverhead',
        'BitTorrent/Session/Interface' => 'Preferences/Connection/Interface',
        'BitTorrent/Session/InterfaceAddress' => 'Preferences/Connection/InterfaceAddress',
        'BitTorrent/Session/InterfaceName' => 'Preferences/Connection/InterfaceName',
        'BitTorrent/Session/IPFilter' => 'Preferences/IPFilter/File',
        'BitTorrent/Session/IPFilteringEnabled' => 'Preferences/IPFilter/Enabled',
        'BitTorrent/Session/LSDEnabled' => 'Preferences/Bittorrent/LSD',
        'BitTorrent/Session/MaxActiveDownloads' => 'Preferences/Queueing/MaxActiveDownloads',
        'BitTorrent/Session/MaxActiveTorrents' => 'Preferences/Queueing/MaxActiveTorrents',
        'BitTorrent/Session/MaxActiveUploads' => 'Preferences/Queueing/MaxActiveUploads',
        'BitTorrent/Session/MaxConnections' => 'Preferences/Bittorrent/MaxConnecs',
        'BitTorrent/Session/MaxConnectionsPerTorrent' => 'Preferences/Bittorrent/MaxConnecsPerTorrent',
        'BitTorrent/Session/MaxHalfOpenConnections' => 'Preferences/Connection/MaxHalfOpenConnec',
        'BitTorrent/Session/MaxRatioAction' => 'Preferences/Bittorrent/MaxRatioAction',
        'BitTorrent/Session/MaxUploads' => 'Preferences/Bittorrent/MaxUploads',
        'BitTorrent/Session/MaxUploadsPerTorrent' => 'Preferences/Bittorrent/MaxUploadsPerTorrent',
        'BitTorrent/Session/OutgoingPortsMax' => 'Preferences/Advanced/OutgoingPortsMax',
        'BitTorrent/Session/OutgoingPortsMin' => 'Preferences/Advanced/OutgoingPortsMin',
        'BitTorrent/Session/PeXEnabled' => 'Preferences/Bittorrent/PeX',
        'BitTorrent/Session/Port' => 'Preferences/Connection/PortRangeMin',
        'BitTorrent/Session/Preallocation' => 'Preferences/Downloads/PreAllocation',
        'BitTorrent/Session/ProxyPeerConnections' => 'Preferences/Connection/ProxyPeerConnections',
        'BitTorrent/Session/QueueingSystemEnabled' => 'Preferences/Queueing/QueueingEnabled',
        'BitTorrent/Session/RefreshInterval' => 'Preferences/General/RefreshInterval',
        'BitTorrent/Session/SaveResumeDataInterval' => 'Preferences/Downloads/SaveResumeDataInterval',
        'BitTorrent/Session/SuperSeedingEnabled' => 'Preferences/Advanced/SuperSeeding',
        'BitTorrent/Session/TempPath' => 'Preferences/Downloads/TempPath',
        'BitTorrent/Session/TempPathEnabled' => 'Preferences/Downloads/TempPathEnabled',
        'BitTorrent/Session/TorrentExportDirectory' => 'Preferences/Downloads/TorrentExportDir',
        'BitTorrent/Session/TrackerFilteringEnabled' => 'Preferences/IPFilter/FilterTracker',
        'BitTorrent/Session/UseAlternativeGlobalSpeedLimit' => 'Preferences/Connection/alt_speeds_on',
        'BitTorrent/Session/UseOSCache' => 'Preferences/Advanced/osCache',
        'BitTorrent/Session/UseRandomPort' => 'Preferences/General/UseRandomPort',
        'BitTorrent/Session/uTPEnabled' => 'Preferences/Bittorrent/uTP',
        'BitTorrent/Session/uTPRateLimited' => 'Preferences/Bittorrent/uTP_rate_limited',
        'BitTorrent/TrackerEnabled' => 'Preferences/Advanced/trackerEnabled',
        'Network/PortForwardingEnabled' => 'Preferences/Connection/UPnP',
        'Network/Proxy/Authentication' => 'Preferences/Connection/Proxy/Authentication',
        'Network/Proxy/IP' => 'Preferences/Connection/Proxy/IP',
        'Network/Proxy/OnlyForTorrents' => 'Preferences/Connection/ProxyOnlyForTorrents',
        'Network/Proxy/Password' => 'Preferences/Connection/Proxy/Password',
        'Network/Proxy/Port' => 'Preferences/Connection/Proxy/Port',
        'Network/Proxy/Type' => 'Preferences/Connection/ProxyType',
        'Network/Proxy/Username' => 'Preferences/Connection/Proxy/Username',
        'State/BannedIPs' => 'Preferences/IPFilter/BannedIPs',
    ];
}

/** Upstream migration 3 proxy enum and migration 7 share-limit enum, without profile/DNS changes. */
function pmssQbittorrentLegacyEnums(string $config, bool $forward): string
{
    $values = pmssQbittorrentConfigValues($config);
    $proxy = ['None', 'HTTP', 'SOCKS5', 'HTTP_PW', 'SOCKS5_PW', 'SOCKS4'];
    $ratio = ['Stop', 'Remove', 'EnableSuperSeeding', 'RemoveWithContent'];
    if ($forward) {
        $value = trim($values['Network']['Proxy\\Type'] ?? '', " \t\"");
        if (ctype_digit($value) && isset($proxy[(int) $value]))
            $config = pmssQbittorrentConfigSet($config, 'Network', 'Proxy\\Type', $proxy[(int) $value], true);
        $value = trim($values['BitTorrent']['Session\\MaxRatioAction'] ?? '', " \t\"");
        if (ctype_digit($value) && isset($ratio[(int) $value]))
            $config = pmssQbittorrentConfigSet($config, 'BitTorrent', 'Session\\ShareLimitAction', $ratio[(int) $value], true);
    } else {
        $value = array_search(trim($values['Network']['Proxy\\Type'] ?? '', " \t\""), $proxy, true);
        if ($value !== false)
            $config = pmssQbittorrentConfigSet($config, 'Preferences', 'Connection\\ProxyType', (string) $value, true);
        $value = array_search(trim($values['BitTorrent']['Session\\ShareLimitAction'] ?? '', " \t\""), $ratio, true);
        if ($value !== false)
            $config = pmssQbittorrentConfigSet($config, 'Preferences', 'Bittorrent\\MaxRatioAction', (string) $value, true);
    }
    return $config;
}
