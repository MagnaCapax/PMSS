<?php

require_once( 'xmlrpc.php' );
eval(getPluginConf($plugin["name"]));

$listPath = getSettingsPath()."/erasedata";
@makeDirectory($listPath);
$thisDir = dirname(__FILE__);

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
	$theSettings->getOnEraseCommand(array('erasedata1'.getUser(),
		getCmd('branch=').getCmd('d.get_custom5').'=,"'.
			getCmd('execute').'={'.$thisDir.'/fin.sh,'.
				$listPath.',$'.
				getCmd('system.pid').'=,$'.
				getCmd('d.get_hash').'=,$'.
				getCmd('d.get_base_path').'=,$'.
				getCmd('d.is_multi_file').'=,$'.
				getCmd('d.get_custom5').'=}"')),
	$theSettings->getAbsScheduleCommand("erasedata",$garbageCheckInterval,
		getCmd('execute').'={sh,-c,'.escapeshellarg(getPHP()).' '.escapeshellarg($thisDir.'/update.php').' '.escapeshellarg(getUser()).' &}' )
	) );
if($req->success())
{
	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
	$jResult.="plugin.enableForceDeletion = ".($enableForceDeletion ? 1 : 0).";";
}
else
	$jResult.="plugin.disable(); noty('erasedata: '+theUILang.pluginCantStart,'error');";
