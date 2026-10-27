<?php
// Default seeding requires evidence of each bundled ruTorrent 0.16 compatibility patch.
const PMSS_RUTORRENT_RPC_SETTINGS_MARKER = 'new rXMLRPCCommand("convert.kb", array(\'\', floatval(1024)))';
const PMSS_RUTORRENT_RPC_HTTPRPC_MARKER = '$addReq = ($trusted && count($add))';
const PMSS_RUTORRENT_RPC_ERASEDATA_MARKER = 'execute.nothrow={sh,-c,';
// Held outside the heredocs so the docblock lint does not read vendored ruTorrent code as a method here.
const PMSS_RUTORRENT_RATIO_GROUP_SIGNATURE = "\tpublic function getRatioGroupCommand(\$ratio,\$cmd,\$args)\n";


foreach (['rutorrentRpcSettings.php', 'rutorrentRpcCalls.php', 'rutorrentRpcErase.php', 'rutorrentRpcActions.php'] as $module) {
    require_once __DIR__.'/'.$module;
}
/**
 * Return exact legacy-to-skeleton edits for existing accounts without a full refresh.
 *
 * Alternative anchors accept original and round-1 files without changing the writer.
 * @return array<int, array{0:string,1:string,2:string}>
 */
function pmssUserRutorrentRpcCompatibilityPatches(): array
{
    // Preserve delivery order: settings, call adapters, erase handler, then HTTP actions.
    return array_merge(
        pmssUserRutorrentRpcSettingsPatches(),
        pmssUserRutorrentRpcCallPatches(),
        pmssUserRutorrentRpcErasePatches(),
        pmssUserRutorrentRpcActionPatches()
    );
}
