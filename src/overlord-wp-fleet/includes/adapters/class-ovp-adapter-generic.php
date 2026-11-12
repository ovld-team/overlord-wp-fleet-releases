<?php
/**
 * Raw fallback for any key on post|term|user|comment|option (Bricks, Beaver reads, custom
 * plugins). Values are JSON in/JSON out; objects are never written.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ovp-adapter-base.php';

final class OVP_Adapter_Generic extends OVP_Adapter {

	public function id(): string {
		return 'generic';
	}

	public function handles( string $type, string $key, array $ctx ): bool {
		return true;
	}

	public function plan( string $type, int $id, string $key, mixed $value, array $ctx ): array {
		return self::writes( array( array( 'key' => $key, 'value' => $value ) ) );
	}
}
