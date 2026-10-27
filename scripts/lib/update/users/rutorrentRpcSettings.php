<?php
/** ruTorrent settings conversion, ratio and versioned alias compatibility. @license GPL-3.0-only */

/** @return array<int, array{0:string,1:string,2:string}> Exact ordered compatibility edits. */
function pmssUserRutorrentRpcSettingsPatches(): array
{
    $aliasesOriginal = <<<'PHP'
			if($this->iVersion>=0x904)
			{
				require_once( 'methods-0.9.4.php' );
			}
PHP;
    $aliasesRoundOne = $aliasesOriginal."\n".<<<'PHP'
			if($this->iVersion >= 0x1010)
			{
				$this->aliases = array_merge($this->aliases,array
				(
					"get_proxy_address" => array( "name"=>"network.proxy.global", "prm"=>0 ),
					"set_proxy_address" => array( "name"=>"network.proxy.global.set", "prm"=>1 ),
				));
			}
PHP;
    $aliasesPatched = $aliasesRoundOne."\n".<<<'PHP'
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
        ['php/settings.php', PMSS_RUTORRENT_RATIO_GROUP_SIGNATURE . <<<'PHP'
	{
		$prefix = ($this->iVersion >= 0x904) && in_array($cmd,$this->ratioCmds) ? "group2." : "group.";
		return( new rXMLRPCCommand( $prefix.$ratio.".".$cmd, $args ) );
	}
PHP, PMSS_RUTORRENT_RATIO_GROUP_SIGNATURE . <<<'PHP'
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
        ['php/settings.php', $aliasesOriginal."\n\t\t\t\n", $aliasesPatched],
        // Also accept the exact round-1 alias block; both variants converge.
        ['php/settings.php', $aliasesRoundOne."\n\t\t\t\n", $aliasesPatched],
    ];
}
