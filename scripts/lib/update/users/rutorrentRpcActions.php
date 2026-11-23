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
    ], pmssUserRutorrentRpcUpdateTrackerPatches());
}

/**
 * Route "Update tracker" through a trusted handler fixed on the server (rTorrent 0.16 refuses the browser-built call).
 *
 * Anchored on the Remove handler, which is identical in every legacy copy. The mode name matches the
 * one later ruTorrent releases use, so a ruTorrent upgrade replaces these files without a conflict.
 *
 * @return array<int, array{0:string,1:string,2:string}> Exact ordered compatibility edits.
 */
function pmssUserRutorrentRpcUpdateTrackerPatches(): array
{
    $removeCase = "\tcase \"remove\":\t/**/\n\t{\n\t\t\$result = makeSimpleCall(array(\"d.erase\"), \$hash);\n\t\tbreak;\n\t}";
    $removeStub = "plugin.origremove = rTorrentStub.prototype.remove;\n"
        ."rTorrentStub.prototype.remove = function()\n{\n\tthis.getCommon(\"remove\");\n}\n";
    return [
        ['plugins/httprpc/action.php', $removeCase, $removeCase."\n\tcase \"updatetracker\":\t/**/\n\t{\n"
            ."\t\t// rTorrent 0.16 refuses a client-built d.tracker_announce on the untrusted connection.\n"
            ."\t\t// The command is fixed here; the hash stays an escaped parameter.\n"
            ."\t\t\$result = makeSimpleCall(array(\"d.tracker_announce\"), \$hash);\n\t\tbreak;\n\t}"],
        ['plugins/httprpc/init.js', $removeStub, $removeStub."\n"
            ."// getCommon() falls back to plugin[\"orig\"+cmd], so the stored name keeps the lower-case mode name.\n"
            ."plugin.origupdatetracker = rTorrentStub.prototype.updateTracker;\n"
            ."rTorrentStub.prototype.updateTracker = function()\n{\n\tthis.getCommon(\"updatetracker\");\n}\n"],
    ];
}
