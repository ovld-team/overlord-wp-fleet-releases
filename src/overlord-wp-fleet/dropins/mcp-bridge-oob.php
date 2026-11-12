<?php
/**
 * Standalone emergency API. Marker: MCP_BRIDGE_FATAL_DROPIN
 *
 * DISABLED in Overlord WP Fleet 2.0. The 1.x gate (a shared bearer token hash plus a hop
 * header) was removed with the rest of the bearer scheme. Until a signed (OVP1) out-of-band
 * gate exists this file refuses every request, so an upgraded site has no unauthenticated or
 * legacy-credential back door.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

header( 'Content-Type: application/json; charset=utf-8' );
header( 'X-Robots-Tag: noindex, nofollow' );
header( 'Cache-Control: no-store' );
http_response_code( 410 );
echo '{"ok":false,"error":"oob_disabled"}';
exit;
