<?php
/** ruTorrent fixed-command call compatibility for both legacy source generations. @license GPL-3.0-only */

/** @return array<int, array{0:string,1:string,2:string}> Exact ordered compatibility edits. */
function pmssUserRutorrentRpcCallPatches(): array
{
    // One function body; each source generation keeps its exact scope and return lines.
    $simpleCall = <<<'PHP'
function makeSimpleCall($cmds,$hash)
{
%s	$req = new rXMLRPCRequest();
	foreach($hash as $h)
		foreach($cmds as $cmd)	
			$req->addCommand( new rXMLRPCCommand( $cmd, $h ) );
%s
}
PHP;
    $comment = "\t// Only fixed server-side commands reach this trusted call; hashes remain parameters.\n";
    $simpleCallPatched = "// Only server-selected commands may bypass rTorrent's untrusted method filter.\n"
        .'$trusted = rTorrentSettings::get()->iVersion >= 0x1000;'."\n\n"
        .sprintf($simpleCall, "\tglobal \$trusted;\n", $comment."\t".'return($req->success($trusted) ? $req->val : false);');

    return [
        // F5/R3: One version decision for fixed server-side commands only.
        ['plugins/httprpc/action.php', sprintf($simpleCall, '', "       \t".'return($req->success(false) ? $req->val : false);'), $simpleCallPatched],
        // An account that received round 1 must reach the same function.
        ['plugins/httprpc/action.php', sprintf($simpleCall, '', $comment."\t".'return($req->success(rTorrentSettings::get()->iVersion >= 0x1000) ? $req->val : false);'), $simpleCallPatched],
    ];
}
