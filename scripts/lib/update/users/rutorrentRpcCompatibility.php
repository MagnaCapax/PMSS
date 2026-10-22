<?php
// Default seeding requires evidence of each bundled ruTorrent 0.16 compatibility patch.
const PMSS_RUTORRENT_RPC_SETTINGS_MARKER = 'new rXMLRPCCommand("convert.kb", array(\'\', floatval(1024)))';
const PMSS_RUTORRENT_RPC_HTTPRPC_MARKER = '$addReq = ($trusted && count($add))';
const PMSS_RUTORRENT_RPC_ERASEDATA_MARKER = 'execute.nothrow={sh,-c,';

/**
 * Return exact legacy-to-skeleton edits for existing accounts without a full refresh.
 *
 * Alternative anchors accept original and round-1 files without changing the writer.
 * @return array<int, array{0:string,1:string,2:string}>
 */
function pmssUserRutorrentRpcCompatibilityPatches(): array
{
    $aliasesPatched = <<<'PHP'
			if($this->iVersion>=0x904)
			{
				require_once( 'methods-0.9.4.php' );
			}
			if($this->iVersion >= 0x1010)
			{
				$this->aliases = array_merge($this->aliases,array
				(
					"get_proxy_address" => array( "name"=>"network.proxy.global", "prm"=>0 ),
					"set_proxy_address" => array( "name"=>"network.proxy.global.set", "prm"=>1 ),
				));
			}
			if($this->iVersion >= 0x1000)
			{
				$this->aliases = array_merge($this->aliases,array
				(
					"dht" => array( "name"=>"dht.mode.set", "prm"=>1 ),
				));
			}
			if($this->iVersion >= 0x1012)
			{
				// Deprecated port_open is inert; cat preserves that behavior.
				$this->aliases = array_merge($this->aliases,array
				(
					"get_port_range" => array( "name"=>"network.listen.port.range", "prm"=>0 ),
					"set_port_range" => array( "name"=>"network.listen.port.range.set", "prm"=>1 ),
					"get_port_random" => array( "name"=>"network.listen.port.random", "prm"=>0 ),
					"set_port_random" => array( "name"=>"network.listen.port.random.set", "prm"=>1 ),
					"get_port_open" => array( "name"=>"cat", "prm"=>0 ),
					"set_port_open" => array( "name"=>"cat", "prm"=>1 ),
					"port_open" => array( "name"=>"cat", "prm"=>0 ),
				));
			}

PHP;

    $simpleCallPatched = <<<'PHP'
// Only server-selected commands may bypass rTorrent's untrusted method filter.
$trusted = rTorrentSettings::get()->iVersion >= 0x1000;

function makeSimpleCall($cmds,$hash)
{
	global $trusted;
	$req = new rXMLRPCRequest();
	foreach($hash as $h)
		foreach($cmds as $cmd)	
			$req->addCommand( new rXMLRPCCommand( $cmd, $h ) );
	// Only fixed server-side commands reach this trusted call; hashes remain parameters.
	return($req->success($trusted) ? $req->val : false);
}
PHP;

    return [
        // F1: Modern conversion requires an explicit string target.
        ['php/settings.php', <<<'PHP'
                        $req = new rXMLRPCRequest( new rXMLRPCCommand("to_kb", floatval(1024)) );
PHP, <<<'PHP'
			$req = new rXMLRPCRequest( $this->iVersion >= 0x1000
				? new rXMLRPCCommand("convert.kb", array('', floatval(1024)))
				: new rXMLRPCCommand("to_kb", floatval(1024)) );
PHP
        ],
        // F2: API versions above 10 no longer imply the feature-bind aliases.
        ['php/settings.php', <<<'PHP'
			$this->apiVersion = 0;
			if($this->iVersion>=0x901)
			{
				$req = new rXMLRPCRequest( new rXMLRPCCommand("system.api_version") );
				$req->important = false;
				if($req->success())
					$this->apiVersion = $req->val[0];
			}

			if($this->apiVersion >= 11)	// at current moment (2019.07.20) this is feature-bind branch of rtorrent
			{
				$this->aliases = array_merge($this->aliases,array
				(
					"get_port_open"	=> array( "name"=>"network.listen.is_open", "prm"=>0 ),
					"get_port_random" => array( "name"=>"network.port.randomize", "prm"=>0 ),
					"get_port_range" => array( "name"=>"network.port.range", "prm"=>0 ),
					"set_port_open"	=> array( "name"=>"network.listen.open", "prm"=>1 ),
					"set_port_random" => array( "name"=>"network.port.randomize.set", "prm"=>1 ),
					"set_port_range" => array( "name"=>"network.port.range.set", "prm"=>1 ),
					"network.listen.port" => array( "name"=>"network.port", "prm"=>0 ),
				));
			}
PHP, <<<'PHP'
			$this->apiVersion = 0;
			if($this->iVersion>=0x901)
			{
				$req = new rXMLRPCRequest( new rXMLRPCCommand("system.api_version") );
				$req->important = false;
				if($req->success())
					$this->apiVersion = $req->val[0];
			}
PHP
        ],
        // F3: Only modern group setters need an extra target argument.
        ['php/settings.php', <<<'PHP'
	public function getRatioGroupCommand($ratio,$cmd,$args)
	{
		$prefix = ($this->iVersion >= 0x904) && in_array($cmd,$this->ratioCmds) ? "group2." : "group.";
		return( new rXMLRPCCommand( $prefix.$ratio.".".$cmd, $args ) );
	}
PHP, <<<'PHP'
	public function getRatioGroupCommand($ratio,$cmd,$args)
	{
		if($this->iVersion >= 0x1000)
		{
			$prefix = "group.";
			if(substr($cmd, -4) === ".set")
			{
				if(!is_array($args)) $args = array($args);
				array_unshift($args, "");
			}
		}
		else
			$prefix = ($this->iVersion >= 0x904) && in_array($cmd,$this->ratioCmds) ? "group2." : "group.";
		return( new rXMLRPCCommand( $prefix.$ratio.".".$cmd, $args ) );
	}
PHP
        ],
        // F4/R2: The following blank line keeps the original anchor out of round-1 blocks.
        ['php/settings.php', <<<'PHP'
			if($this->iVersion>=0x904)
			{
				require_once( 'methods-0.9.4.php' );
			}
			

PHP, $aliasesPatched
        ],
        // Also accept the exact round-1 alias block; both variants converge.
        ['php/settings.php', <<<'PHP'
			if($this->iVersion>=0x904)
			{
				require_once( 'methods-0.9.4.php' );
			}
			if($this->iVersion >= 0x1010)
			{
				$this->aliases = array_merge($this->aliases,array
				(
					"get_proxy_address" => array( "name"=>"network.proxy.global", "prm"=>0 ),
					"set_proxy_address" => array( "name"=>"network.proxy.global.set", "prm"=>1 ),
				));
			}
			

PHP, $aliasesPatched
        ],
        // F5/R3: One version decision for fixed server-side commands only.
        ['plugins/httprpc/action.php', <<<'PHP'
function makeSimpleCall($cmds,$hash)
{
	$req = new rXMLRPCRequest();
	foreach($hash as $h)
		foreach($cmds as $cmd)	
			$req->addCommand( new rXMLRPCCommand( $cmd, $h ) );
       	return($req->success(false) ? $req->val : false);
}
PHP, $simpleCallPatched
        ],
        // An account that received round 1 must reach the same function.
        ['plugins/httprpc/action.php', <<<'PHP'
function makeSimpleCall($cmds,$hash)
{
	$req = new rXMLRPCRequest();
	foreach($hash as $h)
		foreach($cmds as $cmd)	
			$req->addCommand( new rXMLRPCCommand( $cmd, $h ) );
	// Only fixed server-side commands reach this trusted call; hashes remain parameters.
	return($req->success(rTorrentSettings::get()->iVersion >= 0x1000) ? $req->val : false);
}
PHP, $simpleCallPatched
        ],
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
        // R3: Trust only these fixed setter names; parameters stay XML-escaped.
        ['plugins/httprpc/action.php', <<<'PHP'
			$req->addCommand( new rXMLRPCCommand( "t.set_enabled", array($hash[0], intval($value), intval($ss[0])) ) );
		if($req->success(false))
PHP, <<<'PHP'
			$req->addCommand( new rXMLRPCCommand( "t.set_enabled", array($hash[0], intval($value), intval($ss[0])) ) );
		if($req->success($trusted))
PHP
        ],
        ['plugins/httprpc/action.php', <<<'PHP'
		$req->addCommand( new rXMLRPCCommand("d.update_priorities", $hash[0]) );
		if($req->success(false))
PHP, <<<'PHP'
		$req->addCommand( new rXMLRPCCommand("d.update_priorities", $hash[0]) );
		if($req->success($trusted))
PHP
        ],
        ['plugins/httprpc/action.php', <<<'PHP'
			$req->addCommand( new rXMLRPCCommand( "d.set_priority", array($h, intval($vs[0])) ) );
		if($req->success(false))
PHP, <<<'PHP'
			$req->addCommand( new rXMLRPCCommand( "d.set_priority", array($h, intval($vs[0])) ) );
		if($req->success($trusted))
PHP
        ],
        ['plugins/httprpc/action.php', <<<'PHP'
			$req->addCommand( new rXMLRPCCommand( "d.set_custom1", array($h, $vs[0]) ) );
		if($req->success(false))
PHP, <<<'PHP'
			$req->addCommand( new rXMLRPCCommand( "d.set_custom1", array($h, $vs[0]) ) );
		if($req->success($trusted))
PHP
        ],
        ['plugins/httprpc/action.php', <<<'PHP'
			$req->addCommand( new rXMLRPCCommand("p.snubbed.set", array($hash[0].":p".$v,$on)) );
		if($req->success(false))
PHP, <<<'PHP'
			$req->addCommand( new rXMLRPCCommand("p.snubbed.set", array($hash[0].":p".$v,$on)) );
		if($req->success($trusted))
PHP
        ],
        ['plugins/httprpc/action.php', <<<'PHP'
			$req->addCommand( new rXMLRPCCommand("p.disconnect", $hash[0].":p".$v) );
		}
		if($req->success(false))
PHP, <<<'PHP'
			$req->addCommand( new rXMLRPCCommand("p.disconnect", $hash[0].":p".$v) );
		}
		if($req->success($trusted))
PHP
        ],
        ['plugins/httprpc/action.php', <<<'PHP'
			$req->addCommand( new rXMLRPCCommand("p.disconnect", $hash[0].":p".$v) );
		if($req->success(false))
PHP, <<<'PHP'
			$req->addCommand( new rXMLRPCCommand("p.disconnect", $hash[0].":p".$v) );
		if($req->success($trusted))
PHP
        ],
        ['plugins/httprpc/action.php', <<<'PHP'
		$req = new rXMLRPCRequest(
			new rXMLRPCCommand( "add_peer", array($hash[0], $vs[0]) ) );
		if($req->success(false))
PHP, <<<'PHP'
		$req = new rXMLRPCRequest(
			new rXMLRPCCommand( "add_peer", array($hash[0], $vs[0]) ) );
		if($req->success($trusted))
PHP
        ],
        // Client-derived names retain the untrusted path unless all are UI properties.
        ['plugins/httprpc/action.php', <<<'PHP'
			$req->addCommand($cmd);
		}
		if($req->success(false))
PHP, <<<'PHP'
			$req->addCommand($cmd);
		}
		// Every client-selected property must name a setter used by the bundled UI.
		if($req->success($trusted && !array_diff($ss, array("superseed", "ulslots", "pex", "peers_min", "peers_max", "tracker_numwant"))))
PHP
        ],
        // rTorrent 0.16 serves the port getters only on trusted calls; client-added getters stay untrusted.
        ['plugins/httprpc/action.php', <<<'PHP'
		$req = new rXMLRPCRequest( new rXMLRPCCommand( "dht_statistics" ) );
		foreach( $cmds as $cmd )
			$req->addCommand( new rXMLRPCCommand( $cmd ) );	
		foreach( $add as $prm )
			$req->addCommand( new rXMLRPCCommand( $prm ) );	
		if($req->success(false))
		{
	        	$result = array();
PHP, <<<'PHP'
		$req = new rXMLRPCRequest( new rXMLRPCCommand( "dht_statistics" ) );
		foreach( $cmds as $cmd )
			$req->addCommand( new rXMLRPCCommand( $cmd ) );	
		// The fixed getters above may need a trusted call; client-named getters never get one.
		$addReq = ($trusted && count($add)) ? new rXMLRPCRequest() : $req;
		foreach( $add as $prm )
			$addReq->addCommand( new rXMLRPCCommand( $prm ) );	
		if($req->success($trusted) && (($addReq === $req) || $addReq->success(false)))
		{
			if($addReq !== $req)
				$req->val = array_merge($req->val, $addReq->val);
	        	$result = array();
PHP
        ],
    ];
}

