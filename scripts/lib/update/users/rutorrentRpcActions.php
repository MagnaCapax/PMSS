<?php
/** ruTorrent HTTP action compatibility with one fixed-setter transformation. @license GPL-3.0-only */

/** @return array<int, array{0:string,1:string,2:string}> Exact ordered compatibility edits. */
function pmssUserRutorrentRpcActionPatches(): array
{
    $patches = [];
    // Trust only these fixed setter names; parameters stay XML-escaped.
    foreach ([
        '			$req->addCommand( new rXMLRPCCommand( "t.set_enabled", array($hash[0], intval($value), intval($ss[0])) ) );',
        '		$req->addCommand( new rXMLRPCCommand("d.update_priorities", $hash[0]) );',
        '			$req->addCommand( new rXMLRPCCommand( "d.set_priority", array($h, intval($vs[0])) ) );',
        '			$req->addCommand( new rXMLRPCCommand( "d.set_custom1", array($h, $vs[0]) ) );',
        '			$req->addCommand( new rXMLRPCCommand("p.snubbed.set", array($hash[0].":p".$v,$on)) );',
        '			$req->addCommand( new rXMLRPCCommand("p.disconnect", $hash[0].":p".$v) );
		}',
        '			$req->addCommand( new rXMLRPCCommand("p.disconnect", $hash[0].":p".$v) );',
        '		$req = new rXMLRPCRequest(
			new rXMLRPCCommand( "add_peer", array($hash[0], $vs[0]) ) );',
    ] as $command) {
        $legacy = $command."\n\t\t".'if($req->success(false))';
        $patches[] = ['plugins/httprpc/action.php', $legacy, str_replace('$req->success(false)', '$req->success($trusted)', $legacy)];
    }

    // Client-selected properties and getters retain their distinct trust boundaries.
    return array_merge($patches, [
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
    ]);
}
