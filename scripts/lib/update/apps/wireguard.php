<?php
/**
 * WireGuard installer shim.
 *
 * Shared implementation now lives in scripts/lib/wireguard.php. This shim keeps
 * update-step2 includes satisfied without duplicating logic.
 */

require_once __DIR__.'/../../wireguard.php';
