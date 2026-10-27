<?php
/** ruTorrent erasedata compatibility with nested command quoting preserved. @license GPL-3.0-only */

/** @return array<int, array{0:string,1:string,2:string}> Exact ordered compatibility edits. */
function pmssUserRutorrentRpcErasePatches(): array
{
    return [
        // F6: Modern rTorrent lacks file.append; keep the worker's list format.
        ['plugins/erasedata/init.php', <<<'PHP'
$req = new rXMLRPCRequest( array(
	$theSettings->getOnEraseCommand(array('erasedata0'.getUser(),
		getCmd('d.open').'= ; '.getCmd('branch=').getCmd('d.get_custom5').'=,"'.
			getCmd('f.multicall').'=,\"'.getCmd('file.append').'=(cat,'.$listPath.'/,$'.getCmd('system.pid').'=,.tmp),$'.getCmd('f.get_frozen_path').'=\""')),
PHP, <<<'PHP'
$eraseCommand = getCmd('d.open').'= ; '.getCmd('branch=').getCmd('d.get_custom5').'=,"'.
	getCmd('f.multicall').'=,\"'.getCmd('file.append').'=(cat,'.$listPath.'/,$'.getCmd('system.pid').'=,.tmp),$'.getCmd('f.get_frozen_path').'=\""';
if($theSettings->iVersion >= 0x1000)
{
	// Pass filenames as shell arguments and retain the cleanup worker's line format.
	$x = 'execute.nothrow={sh,-c,"printf \'%s\\\\n\' \\"$0\\" >> \\"$1/$2.tmp\\"",$'.getCmd('f.get_frozen_path').'=,'.$listPath.',$'.getCmd('system.pid').'=}';
	$eraseCommand = getCmd('d.open').'= ; '.getCmd('branch=').getCmd('d.get_custom5').'=,"'.getCmd('f.multicall').'=,\"'.addcslashes(addcslashes($x, '"\\'), '"\\').'\""';
}

$req = new rXMLRPCRequest( array(
	$theSettings->getOnEraseCommand(array('erasedata0'.getUser(), $eraseCommand)),
PHP
        ],
    ];
}
