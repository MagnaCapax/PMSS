<?php
/**
 * Compatibility loader for rTorrent SCGI helpers.
 *
 * Existing callers require this file directly. Keep that contract stable while
 * the XML-RPC codec, Unix-socket transport, and listen-queue parser live in
 * focused modules.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

require_once __DIR__.'/scgi/xmlrpc.php';
require_once __DIR__.'/scgi/transport.php';
require_once __DIR__.'/scgi/queue.php';
