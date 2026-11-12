<?php
/**
 * Settings > Overlord WP Fleet: connection mode, pairing with a one-time code (with an explicit
 * consent screen that states the tier being granted), status, tier cap and timed god window,
 * unpair, update check.
 *
 * Every action needs manage_options and its own nonce. Consent happens once, at pairing: the tier
 * shown there becomes the cap. The cap setters live in Ovp_Tiers and verify the `ovp_set_cap`
 * nonce themselves; they refuse once a signed remote request has been seen in this process, so a
 * person here can always lower the cap, open or close a god window, or unpair.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Settings_Page {

	public const SLUG = 'overlord-wp-fleet';

	private const TIER_LABELS = array(
		0 => 'T0 Read only',
		1 => 'T1 Safe edits (content and media; every change needs a two-step confirmation)',
		2 => 'T2 Structural (plugins, themes, users, options, search-replace, ZIP uploads, database writes)',
		3 => 'T3 Full control (raw SQL, read and write any file including this plugin, replace or delete this plugin, administrator login links)',
	);

	public static function register_menu(): void {
		add_options_page(
			__( 'Overlord WP Fleet', 'overlord-wp-fleet' ),
			__( 'Overlord WP Fleet', 'overlord-wp-fleet' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	private static function post_str( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- callers verify.
	}

	/** @return array{0:string,1:string} [message, success|error|warning] */
	private static function pair_message( string $code ): array {
		$map = array(
			'not_configured'       => 'Save the connection (mode and URL) first.',
			'forbidden'            => 'Not allowed.',
			'bad_mode'             => 'Unknown connection mode.',
			'bad_code'             => 'That is not a valid pairing string. It looks like 32 hex characters, a dot, 16 hex characters and optionally a dot and a tier such as t3.',
				'tier_mismatch'        => 'The master answered with a different tier than the one shown on the consent screen. Nothing was changed.',
				'bad_tier_signature'   => 'The master did not sign the tier it granted. Nothing was changed.',
			'bad_url'              => 'The pairing URL is not allowed (https is required; http only on loopback in local mode).',
			'consent_required'     => 'The connection changed after you reviewed it. Review and approve again.',
			'already_bound'        => 'This site is already paired. Unpair here and revoke it in the master first.',
			'busy'                 => 'A pairing is already running. Try again in a minute.',
			'master_rejected'      => 'The master rejected the pairing (wrong, used or expired code, or the site is still active there) or could not be reached.',
			'bad_response'         => 'The master sent an invalid answer. Nothing was changed.',
			'fingerprint_mismatch' => 'The master key does not match the fingerprint in your pairing string. Nothing was changed. Do not continue.',
			'transcript_mismatch'  => 'The answer did not match what this site sent (a relay may have altered it). Nothing was changed.',
			'bad_master_signature' => 'The master signature is invalid. Nothing was changed.',
			'pair_error'           => 'Pairing failed unexpectedly. Nothing was changed.',
			'sodium_missing'       => 'The PHP sodium extension is required.',
		);
		return array( $map[ $code ] ?? 'Pairing failed.', 'error' );
	}

	/**
	 * Run the posted action. Returns [notice text, type] and, for the review step, the consent data.
	 *
	 * @return array{notice:string,type:string,review:?array<string,mixed>}
	 */
	private static function handle_post(): array {
		$out    = array( 'notice' => '', 'type' => 'success', 'review' => null );
		$action = self::post_str( 'ovp_action' );
		if ( '' === $action ) {
			return $out;
		}
		$fail = static function ( string $msg ) use ( $out ): array {
			$out['notice'] = $msg;
			$out['type']   = 'error';
			return $out;
		};

		switch ( $action ) {
			case 'save_conn':
				check_admin_referer( 'ovp_save_conn' );
				$r = Ovp_Enroll::save_config( self::post_str( 'mode' ), self::post_str( 'master_url' ), self::post_str( 'relay_url' ), self::post_str( 'relay_token' ) );
				if ( is_wp_error( $r ) ) {
					return $fail( $r->get_error_message() );
				}
				$out['notice'] = __( 'Connection saved. Nothing was sent anywhere.', 'overlord-wp-fleet' );
				return $out;

			case 'pair_review':
				check_admin_referer( 'ovp_pair_review' );
				$ps = Ovp_Pair::parse_pairing_string( self::post_str( 'pairing' ) );
				if ( null === $ps ) {
					return $fail( self::pair_message( 'bad_code' )[0] );
				}
				if ( Ovp_Keys::is_bound() ) {
					return $fail( self::pair_message( 'already_bound' )[0] );
				}
				if ( '' === Ovp_Enroll::pair_url() ) {
					return $fail( self::pair_message( 'not_configured' )[0] );
				}
				$out['review'] = array(
						'pairing' => $ps['code'] . '.' . $ps['fp'] . ( null === $ps['tier'] ? '' : '.t' . $ps['tier'] ),
						'consent' => Ovp_Enroll::consent( $ps['tier'] ),
						'fp'      => $ps['fp'],
						'grant'   => Ovp_Pair::grant_for( $ps['tier'] ),
					);
				return $out;

			case 'pair_confirm':
				check_admin_referer( 'ovp_pair_confirm' );
				if ( '' === self::post_str( 'approve' ) ) {
					return $fail( __( 'You must tick the approval box to send the pairing request.', 'overlord-wp-fleet' ) );
				}
				$res = Ovp_Enroll::pair( self::post_str( 'pairing' ), self::post_str( 'consent_hash' ) );
				if ( ! $res['ok'] ) {
					return $fail( self::pair_message( $res['code'] )[0] );
				}
				$hb            = $res['heartbeat'] ?? array();
				$out['notice'] = sprintf( /* translators: %s: tier description */ __( 'Paired. You granted: %s. You can lower this, open or close a god window, or unpair below at any time.', 'overlord-wp-fleet' ), Ovp_Tiers::describe( Ovp_Tiers::cap_state()['cap'] ) )
					. ( ! empty( $hb['ok'] ) ? ' ' . __( 'First heartbeat delivered.', 'overlord-wp-fleet' ) : ' ' . __( 'The first heartbeat could not be delivered yet; it will be retried automatically.', 'overlord-wp-fleet' ) );
				return $out;

			case 'unpair':
				check_admin_referer( 'ovp_unpair' );
				if ( '' === self::post_str( 'confirm_unpair' ) ) {
					return $fail( __( 'Tick the box to confirm unpairing.', 'overlord-wp-fleet' ) );
				}
				if ( ! Ovp_Enroll::unpair() ) {
					return $fail( __( 'Unpairing was refused.', 'overlord-wp-fleet' ) );
				}
				$out['notice'] = __( 'Unpaired. All keys were wiped and the tier cap reset. Also revoke the site in the master before pairing again.', 'overlord-wp-fleet' );
				return $out;

			case 'set_cap':
				return self::handle_cap( $out, $fail );

			case 'heartbeat_now':
				check_admin_referer( 'ovp_heartbeat_now' );
				$r = Ovp_Fleet::heartbeat();
				$out['notice'] = $r['ok'] ? __( 'Heartbeat delivered.', 'overlord-wp-fleet' ) : sprintf( /* translators: %s: reason code */ __( 'Heartbeat failed (%s).', 'overlord-wp-fleet' ), $r['code'] );
				$out['type']   = $r['ok'] ? 'success' : 'error';
				return $out;

			case 'check_update':
				check_admin_referer( 'ovp_check_update' );
				$r = Ovp_Release::current( true );
				// Let WordPress see the fresh answer now instead of at its next 12 h check.
				delete_site_transient( 'update_plugins' );
				if ( ! function_exists( 'wp_update_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/update.php';
				}
				wp_update_plugins();
				if ( $r['verified'] && is_array( $r['index'] ) ) {
					$v = (string) $r['index']['version'];
					/* translators: 1: version, 2: key id */
					$out['notice'] = version_compare( $v, OVP_FLEET_VERSION, '>' )
						? sprintf( __( 'Signed release %1$s is available (key %2$s). Update it from the Plugins screen.', 'overlord-wp-fleet' ), $v, $r['key_id'] )
						: __( 'You are on the latest signed release.', 'overlord-wp-fleet' );
					$out['type'] = 'info';
				} elseif ( 'unavailable' === $r['status'] ) {
					$out['notice'] = __( 'No release has been published yet.', 'overlord-wp-fleet' );
					$out['type']   = 'info';
				} else {
					$out['notice'] = '' !== $r['error'] ? $r['error'] : __( 'Could not check for updates.', 'overlord-wp-fleet' );
					$out['type']   = 'error';
				}
				return $out;

			case 'set_auto_update':
				// Nonce is verified inside admin_set_auto(); a remote-seen request or a user without update_plugins is refused there.
				$want = '1' === self::post_str( 'auto_update' );
				if ( ! Ovp_Updater::admin_set_auto( $want, self::post_str( '_wpnonce' ) ) ) {
					return $fail( __( 'Could not change the automatic update setting.', 'overlord-wp-fleet' ) );
				}
				$out['notice'] = $want
					? __( 'Automatic updates of this plugin are on. Only signed releases are installed.', 'overlord-wp-fleet' )
					: __( 'Automatic updates of this plugin are off.', 'overlord-wp-fleet' );
				return $out;

			case 'self_restore':
				// Nonce, manage_options and not-remote are all checked inside admin_restore().
				$r             = Ovp_Self_Update::admin_restore( self::post_str( '_wpnonce' ) );
				$out['notice'] = $r['message'];
				$out['type']   = $r['ok'] ? 'success' : 'error';
				return $out;

			case 'clear_log':
				check_admin_referer( 'ovp_clear_log' );
				Ovp_Log::clear();
				$out['notice'] = __( 'Request log cleared.', 'overlord-wp-fleet' );
				return $out;
		}
		return $out;
	}

	/**
	 * @param array{notice:string,type:string,review:?array<string,mixed>} $out
	 * @return array{notice:string,type:string,review:?array<string,mixed>}
	 */
	private static function handle_cap( array $out, callable $fail ): array {
		// The nonce is verified inside Ovp_Tiers (action ovp_set_cap), together with manage_options.
		$nonce = self::post_str( 'ovp_cap_nonce' );
		$sub   = self::post_str( 'cap_op' );
		if ( 'cap' === $sub ) {
			$cap = (int) self::post_str( 'cap' );
			if ( $cap >= 3 && '' === self::post_str( 'ack_god' ) ) {
				return $fail( __( 'Tick the god mode acknowledgement to set T3.', 'overlord-wp-fleet' ) );
			}
			if ( ! Ovp_Tiers::admin_set_cap( $cap, $nonce ) ) {
				return $fail( __( 'The cap was not changed (invalid value, bad nonce or not allowed).', 'overlord-wp-fleet' ) );
			}
			$out['notice'] = sprintf( /* translators: %s: tier */ __( 'Tier cap set to %s.', 'overlord-wp-fleet' ), Ovp_Tiers::name( $cap ) );
			return $out;
		}
		if ( 'god' === $sub ) {
			if ( '' === self::post_str( 'ack_god' ) ) {
				return $fail( __( 'Tick the god mode acknowledgement to open a god window.', 'overlord-wp-fleet' ) );
			}
			if ( ! Ovp_Tiers::admin_god_window( (int) self::post_str( 'minutes' ), $nonce ) ) {
				return $fail( __( 'The god window was not opened (1 to 1440 minutes, valid nonce, and not blocked by the build flavor).', 'overlord-wp-fleet' ) );
			}
			$out['notice'] = __( 'God window opened. It closes automatically.', 'overlord-wp-fleet' );
			$out['type']   = 'warning';
			return $out;
		}
		if ( 'end_god' === $sub ) {
			if ( Ovp_Proto::remote_seen() || ! current_user_can( 'manage_options' ) || false === wp_verify_nonce( $nonce, 'ovp_set_cap' ) ) {
				return $fail( __( 'Not allowed.', 'overlord-wp-fleet' ) );
			}
			$s              = Ovp_Tiers::cap_state();
			$s['god_until'] = 0;
			update_option( Ovp_Tiers::CAP_OPTION, $s, false );
			$out['notice'] = __( 'God window closed.', 'overlord-wp-fleet' );
			return $out;
		}
		if ( 'raise' === $sub ) {
			$on = '' !== self::post_str( 'allow_raise' );
			if ( ! Ovp_Tiers::admin_set_remote_raise( $on, $nonce ) ) {
				return $fail( __( 'The setting was not changed.', 'overlord-wp-fleet' ) );
			}
			$out['notice'] = $on ? __( 'The master may now raise the cap remotely.', 'overlord-wp-fleet' ) : __( 'The master can no longer raise the cap remotely.', 'overlord-wp-fleet' );
			return $out;
		}
		return $out;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'overlord-wp-fleet' ) );
		}
		$res = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? self::handle_post() : array( 'notice' => '', 'type' => 'success', 'review' => null );

		echo '<div class="wrap"><h1>' . esc_html__( 'Overlord WP Fleet', 'overlord-wp-fleet' ) . '</h1>';
		echo '<style>.ovp-card{background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:860px}.ovp-card h2{margin-top:0}.ovp-god{border-left:4px solid #b32d2e;background:#fcf0f1;padding:10px 14px;margin:10px 0}.ovp-kv td{padding:4px 12px 4px 0;vertical-align:top}.ovp-mono{font-family:monospace;word-break:break-all}</style>';
		if ( '' !== $res['notice'] ) {
			$cls = 'error' === $res['type'] ? 'notice-error' : ( 'warning' === $res['type'] ? 'notice-warning' : ( 'info' === $res['type'] ? 'notice-info' : 'notice-success' ) );
			echo '<div class="notice ' . esc_attr( $cls ) . '"><p>' . esc_html( $res['notice'] ) . '</p></div>';
		}
		if ( ! Ovp_Proto::sodium_available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The PHP sodium extension is missing. Signed connections cannot work until it is enabled.', 'overlord-wp-fleet' ) . '</p></div>';
		}

		self::render_status();
		if ( Ovp_Keys::is_bound() ) {
			self::render_cap();
			self::render_unpair();
		} else {
			self::render_connection();
			self::render_pair( $res['review'] );
		}
		self::render_updates();
		self::render_maintenance();
		echo '</div>';
	}

	private static function render_updates(): void {
		$r    = Ovp_Release::cached(); // No network on page load; "Check now" refetches.
		$idx  = is_array( $r['index'] ) ? $r['index'] : null;
		$keys = array_keys( Ovp_Release::RELEASE_KEYS );
		echo '<div class="ovp-card"><h2>' . esc_html__( 'Updates', 'overlord-wp-fleet' ) . '</h2><table class="ovp-kv">';
		$st_auto = Ovp_Updater::auto_status();
		self::row(
			__( 'Automatic updates', 'overlord-wp-fleet' ),
			'off' === $st_auto
				? __( 'OFF (turned off by an admin)', 'overlord-wp-fleet' )
				: ( 'on' === $st_auto ? __( 'ON (turned on by an admin)', 'overlord-wp-fleet' ) : __( 'ON (default)', 'overlord-wp-fleet' ) )
		);
		self::row( __( 'Installed version', 'overlord-wp-fleet' ), OVP_FLEET_VERSION );
		self::row( __( 'Latest signed release', 'overlord-wp-fleet' ), null !== $idx ? (string) $idx['version'] : __( 'none', 'overlord-wp-fleet' ) );
		self::row( __( 'Signature verified', 'overlord-wp-fleet' ), $r['verified'] ? __( 'yes', 'overlord-wp-fleet' ) : __( 'no', 'overlord-wp-fleet' ) );
		self::row( __( 'Signing key id', 'overlord-wp-fleet' ), '' !== $r['key_id'] ? $r['key_id'] : '-' );
		self::row( __( 'Last checked', 'overlord-wp-fleet' ), $r['checked_at'] > 0 ? gmdate( 'Y-m-d H:i:s', $r['checked_at'] ) . ' UTC' : __( 'never', 'overlord-wp-fleet' ) );
		if ( '' !== $r['error'] ) {
			self::row( __( 'Last error', 'overlord-wp-fleet' ), $r['error'] );
		}
		self::row( __( 'Pinned key ids', 'overlord-wp-fleet' ), array() === $keys ? '-' : implode( ', ', $keys ) );
		echo '</table>';
		if ( array() === $keys ) {
			echo '<div class="ovp-god"><strong>' . esc_html__( 'No release key is pinned in this build, so no update package can be trusted and none will be installed.', 'overlord-wp-fleet' ) . '</strong></div>';
		}
		echo '<form method="post" style="display:inline">';
		wp_nonce_field( 'ovp_check_update' );
		echo '<input type="hidden" name="ovp_action" value="check_update">';
		submit_button( __( 'Check now', 'overlord-wp-fleet' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( Ovp_Proto::remote_seen() || ! current_user_can( 'update_plugins' ) ) {
			echo '<p class="description">' . esc_html__( 'Automatic updates can only be changed by an administrator who can update plugins.', 'overlord-wp-fleet' ) . '</p>';
		} else {
			echo '<form method="post" style="margin-top:12px">';
			wp_nonce_field( Ovp_Updater::NONCE );
			echo '<input type="hidden" name="ovp_action" value="set_auto_update">';
			$now_on = Ovp_Updater::auto_enabled();
			echo '<input type="hidden" name="auto_update" value="' . ( $now_on ? '0' : '1' ) . '">';
			submit_button(
				$now_on ? __( 'Turn off automatic updates', 'overlord-wp-fleet' ) : __( 'Turn on automatic updates', 'overlord-wp-fleet' ),
				'secondary',
				'submit',
				false
			);
			echo '</form>';
		}
		echo '<p class="description">' . esc_html__( 'This plugin, and only this plugin, updates itself automatically by default. Only signed, newer releases are installed; the previous version is kept and restored if the new one fails. Nothing else on this site (WordPress, other plugins, themes, content) is updated automatically by this setting. Only a person in wp-admin can change it; a remote request cannot.', 'overlord-wp-fleet' ) . '</p>';

		$st = Ovp_Self_Update::state();
		echo '<table class="ovp-kv">';
		self::row( __( 'Last result', 'overlord-wp-fleet' ), (string) ( $st['state'] ?? '' ) !== '' ? (string) $st['state'] : __( 'none', 'overlord-wp-fleet' ) );
		if ( '' !== (string) ( $st['reason'] ?? '' ) ) {
			self::row( __( 'Reason', 'overlord-wp-fleet' ), (string) $st['reason'] );
		}
		$bk = Ovp_Self_Update::latest_backup();
		self::row( __( 'Previous version kept', 'overlord-wp-fleet' ), null !== $bk ? $bk['version'] : __( 'none', 'overlord-wp-fleet' ) );
		echo '</table>';
		if ( null !== $bk && ! Ovp_Proto::remote_seen() && current_user_can( 'manage_options' ) ) {
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( Ovp_Self_Update::NONCE );
			echo '<input type="hidden" name="ovp_action" value="self_restore">';
			/* translators: %s: version number */
			submit_button( sprintf( __( 'Restore previous version %s', 'overlord-wp-fleet' ), $bk['version'] ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	private static function render_status(): void {
		$bound = Ovp_Keys::is_bound();
		$state = Ovp_Fleet::state();
		$fp    = '';
		if ( $bound ) {
			$pub = Ovp_Keys::lookup_master( Ovp_Keys::active_master_kid(), '' );
			$fp  = null !== $pub ? Ovp_Pair::fingerprint( $pub ) : '';
		}
		$cap = Ovp_Tiers::cap_state();
		echo '<div class="ovp-card"><h2>' . esc_html__( 'Status', 'overlord-wp-fleet' ) . '</h2><table class="ovp-kv">';
		self::row( __( 'Paired', 'overlord-wp-fleet' ), $bound ? 'Yes' : 'No (inert: no signed request is accepted and nothing is sent)' );
		if ( $bound ) {
			self::row( __( 'Site ID', 'overlord-wp-fleet' ), Ovp_Keys::site_id(), true );
			self::row( __( 'Mode', 'overlord-wp-fleet' ), Ovp_Keys::mode() );
			self::row( __( 'Master key fingerprint', 'overlord-wp-fleet' ), $fp, true );
			$at = (int) ( $state['at'] ?? 0 );
			self::row(
				__( 'Last heartbeat', 'overlord-wp-fleet' ),
				0 === $at ? 'Never' : gmdate( 'Y-m-d H:i:s', $at ) . ' UTC, ' . ( ! empty( $state['ok'] ) ? 'ok' : 'failed (' . (string) ( $state['code'] ?? '?' ) . ')' )
			);
			self::row( __( 'Tier cap', 'overlord-wp-fleet' ), self::TIER_LABELS[ $cap['cap'] ] ?? Ovp_Tiers::name( $cap['cap'] ) );
			self::row( __( 'Effective cap right now', 'overlord-wp-fleet' ), Ovp_Tiers::name( Ovp_Tiers::cap_now() ) );
		}
		$mig = Ovp_Migration::status();
		if ( null !== $mig ) {
			self::row(
				__( 'Automatic pairing (migration)', 'overlord-wp-fleet' ),
				gmdate( 'Y-m-d H:i:s', $mig['at'] ) . ' UTC, ' . ( $mig['ok'] ? 'paired' : 'not paired (' . $mig['code'] . ')' ) . ( '' !== $mig['source'] ? ', via ' . $mig['source'] : '' )
			);
			if ( '' !== $mig['legacy'] ) {
				self::row( __( 'Legacy 1.x plugin', 'overlord-wp-fleet' ), 'deactivated' === $mig['legacy'] ? 'Deactivated, old credentials wiped. The files are still installed.' : 'Not active, old credentials wiped.' );
			}
		}
		if ( Ovp_Migration::legacy_active() ) {
			self::row( __( 'Legacy 1.x plugin', 'overlord-wp-fleet' ), $bound ? 'Still active. It keeps working until the master retires it (or deactivate it in Plugins).' : 'Active alongside this plugin. Pairing does not deactivate it.' );
		}
		echo '</table>';
		if ( Ovp_Tiers::cap_now() >= 3 ) {
			echo '<div class="ovp-god"><strong>' . esc_html__( 'GOD MODE IS ACTIVE.', 'overlord-wp-fleet' ) . '</strong> ';
			echo esc_html__( 'The connected master can run raw SQL, read, write or delete any file including PHP and this plugin, replace or delete this plugin, and create administrator login links on this site. It cannot delete or change the mcp_bridge_service account.', 'overlord-wp-fleet' ) . '</div>';
		}
		echo '</div>';
	}

	private static function row( string $k, string $v, bool $mono = false ): void {
		echo '<tr><td><strong>' . esc_html( $k ) . '</strong></td><td' . ( $mono ? ' class="ovp-mono"' : '' ) . '>' . esc_html( $v ) . '</td></tr>';
	}

	private static function render_connection(): void {
		$cfg = Ovp_Enroll::config();
		echo '<div class="ovp-card"><h2>' . esc_html__( '1. Connection', 'overlord-wp-fleet' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'ovp_save_conn' );
		echo '<input type="hidden" name="ovp_action" value="save_conn">';
		echo '<p><label>' . esc_html__( 'Mode', 'overlord-wp-fleet' ) . ' <select name="mode">';
		$labels = array(
			'remote' => 'Remote (the master calls this site and this site calls the master)',
			'direct' => 'Direct (same as remote, no relay)',
			'proxy'  => 'Proxy (this site reaches the master only through a relay)',
			'local'  => 'Local (an agent on your own machine; http on loopback allowed)',
		);
		foreach ( $labels as $m => $l ) {
			echo '<option value="' . esc_attr( $m ) . '"' . selected( $cfg['mode'], $m, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Master URL', 'overlord-wp-fleet' ) . '<br><input type="url" class="regular-text" name="master_url" value="' . esc_attr( $cfg['master_url'] ) . '" placeholder="https://fleet.example.com" autocomplete="off"></label></p>';
		echo '<p><label>' . esc_html__( 'Relay URL (proxy mode)', 'overlord-wp-fleet' ) . '<br><input type="url" class="regular-text" name="relay_url" value="' . esc_attr( $cfg['relay_url'] ) . '" placeholder="https://relay.example.com" autocomplete="off"></label></p>';
		echo '<p><label>' . esc_html__( 'Relay token (proxy mode)', 'overlord-wp-fleet' ) . '<br><input type="password" class="regular-text" name="relay_token" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $cfg['relay_token'] ? '(stored, leave empty to keep)' : '' ) . '"></label><br><span class="description">'
			. esc_html__( 'The relay token only authenticates this site to the relay. It cannot sign or forge requests.', 'overlord-wp-fleet' ) . '</span></p>';
		submit_button( __( 'Save connection', 'overlord-wp-fleet' ), 'secondary' );
		echo '</form></div>';
	}

	/** @param array<string,mixed>|null $review */
	private static function render_pair( ?array $review ): void {
		echo '<div class="ovp-card"><h2>' . esc_html__( '2. Pair with a code', 'overlord-wp-fleet' ) . '</h2>';
		if ( null !== $review ) {
			$consent = $review['consent'];
			echo '<p>' . esc_html__( 'This is exactly what will be sent. Check the destination and the fingerprint source before approving.', 'overlord-wp-fleet' ) . '</p><table class="ovp-kv">';
			foreach ( $consent['summary'] as $k => $v ) {
				self::row( str_replace( '_', ' ', (string) $k ), (string) $v, true );
			}
			echo '</table>';
			if ( (int) ( $review['grant'] ?? 0 ) >= 3 ) {
				echo '<div class="ovp-god"><strong>' . esc_html__( 'You are granting full control.', 'overlord-wp-fleet' ) . '</strong> ' . esc_html__( 'The master will be able to read, write and delete any file (including this plugin), run raw SQL, replace or delete this plugin and create administrator login links. It will not be able to delete or change the mcp_bridge_service account. You will not be asked again; you can lower the cap or unpair here at any time.', 'overlord-wp-fleet' ) . '</div>';
			}
			echo '<p><strong>' . esc_html__( 'Master key fingerprint you were given:', 'overlord-wp-fleet' ) . '</strong> <span class="ovp-mono">' . esc_html( (string) $review['fp'] ) . '</span><br>';
			echo esc_html__( 'The pairing is refused if the master answers with a key that does not match it.', 'overlord-wp-fleet' ) . '</p>';
			echo '<form method="post">';
			wp_nonce_field( 'ovp_pair_confirm' );
			echo '<input type="hidden" name="ovp_action" value="pair_confirm">';
			echo '<input type="hidden" name="pairing" value="' . esc_attr( (string) $review['pairing'] ) . '">';
			echo '<input type="hidden" name="consent_hash" value="' . esc_attr( (string) $consent['hash'] ) . '">';
			echo '<p><label><input type="checkbox" name="approve" value="1"> ' . esc_html__( 'I approve sending this pairing request.', 'overlord-wp-fleet' ) . '</label></p>';
			echo '<p class="description">' . esc_html__( 'This is the only consent step. The tier above becomes the cap on this site. You can lower it, or unpair, here at any time afterwards.', 'overlord-wp-fleet' ) . '</p>';
			submit_button( __( 'Pair now', 'overlord-wp-fleet' ) );
			echo '</form></div>';
			return;
		}
		echo '<p>' . esc_html__( 'Paste the pairing string shown in the Fleet panel (Add site). It is single use and expires after 10 minutes.', 'overlord-wp-fleet' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'ovp_pair_review' );
		echo '<input type="hidden" name="ovp_action" value="pair_review">';
		echo '<p><input type="text" class="regular-text ovp-mono" name="pairing" value="" maxlength="128" autocomplete="off" spellcheck="false" placeholder="0123456789abcdef0123456789abcdef.0123456789abcdef"></p>';
		submit_button( __( 'Review', 'overlord-wp-fleet' ), 'primary' );
		echo '</form></div>';
	}

	private static function render_cap(): void {
		$s   = Ovp_Tiers::cap_state();
		$max = Ovp_Tiers::max_cap();
		echo '<div class="ovp-card"><h2>' . esc_html__( 'Tier cap', 'overlord-wp-fleet' ) . '</h2>';
		echo '<p>' . esc_html__( 'The master can never act above this cap. Pairing set it to the tier you approved. You can change it here at any time; lowering it here also stops the master from raising it back. The master itself can only lower it, or raise it back up to the tier you approved at pairing.', 'overlord-wp-fleet' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'ovp_set_cap', 'ovp_cap_nonce' );
		echo '<input type="hidden" name="ovp_action" value="set_cap"><input type="hidden" name="cap_op" value="cap">';
		echo '<p><select name="cap">';
		for ( $i = 0; $i <= $max; $i++ ) {
			echo '<option value="' . (int) $i . '"' . selected( $s['cap'], $i, false ) . '>' . esc_html( self::TIER_LABELS[ $i ] ) . '</option>';
		}
		echo '</select></p>';
		if ( $max >= 3 ) {
			echo '<div class="ovp-god"><strong>' . esc_html__( 'Full control warning.', 'overlord-wp-fleet' ) . '</strong> ' . esc_html__( 'T3 has no limit: the master can run raw SQL, read, write or delete any file (including PHP code and this plugin), replace or delete this plugin, and create administrator login links. A compromised master or agent token then means a compromised site. The one thing it still cannot do is delete or change the mcp_bridge_service account, because WordPress would delete everything that account created. Consider a timed god window instead.', 'overlord-wp-fleet' );
			echo '<br><label><input type="checkbox" name="ack_god" value="1"> ' . esc_html__( 'I understand the risk (required for T3).', 'overlord-wp-fleet' ) . '</label></div>';
		}
		submit_button( __( 'Set cap', 'overlord-wp-fleet' ), 'secondary' );
		echo '</form>';

		if ( $max >= 3 ) {
			echo '<hr><h3>' . esc_html__( 'Timed god window', 'overlord-wp-fleet' ) . '</h3>';
			if ( $s['god_until'] > time() ) {
				echo '<div class="ovp-god">' . esc_html( sprintf( /* translators: %s: UTC time */ __( 'God window open until %s UTC.', 'overlord-wp-fleet' ), gmdate( 'Y-m-d H:i:s', $s['god_until'] ) ) ) . '</div>';
				echo '<form method="post">';
				wp_nonce_field( 'ovp_set_cap', 'ovp_cap_nonce' );
				echo '<input type="hidden" name="ovp_action" value="set_cap"><input type="hidden" name="cap_op" value="end_god">';
				submit_button( __( 'Close god window now', 'overlord-wp-fleet' ), 'secondary' );
				echo '</form>';
			}
			echo '<form method="post">';
			wp_nonce_field( 'ovp_set_cap', 'ovp_cap_nonce' );
			echo '<input type="hidden" name="ovp_action" value="set_cap"><input type="hidden" name="cap_op" value="god">';
			echo '<p><label>' . esc_html__( 'Open god mode for', 'overlord-wp-fleet' ) . ' <input type="number" name="minutes" min="1" max="1440" value="30" style="width:6em"> ' . esc_html__( 'minutes (1 to 1440)', 'overlord-wp-fleet' ) . '</label></p>';
			echo '<p><label><input type="checkbox" name="ack_god" value="1"> ' . esc_html__( 'I understand the risk of god mode.', 'overlord-wp-fleet' ) . '</label></p>';
			submit_button( __( 'Open god window', 'overlord-wp-fleet' ), 'secondary' );
			echo '</form>';
		}

		echo '<hr><form method="post">';
		wp_nonce_field( 'ovp_set_cap', 'ovp_cap_nonce' );
		echo '<input type="hidden" name="ovp_action" value="set_cap"><input type="hidden" name="cap_op" value="raise">';
		echo '<p><label><input type="checkbox" name="allow_raise" value="1"' . checked( $s['raise'], true, false ) . '> ' . esc_html__( 'Allow the master to raise the cap back remotely, never above the tier you approved at pairing (the master can always lower it).', 'overlord-wp-fleet' ) . '</label></p>';
		submit_button( __( 'Save', 'overlord-wp-fleet' ), 'secondary' );
		echo '</form></div>';
	}

	private static function render_unpair(): void {
		echo '<div class="ovp-card"><h2>' . esc_html__( 'Unpair', 'overlord-wp-fleet' ) . '</h2>';
		echo '<p>' . esc_html__( 'Wipes every key on this site and resets the tier cap. The site becomes inert. To pair again you also need to revoke the site in the master and get a fresh code.', 'overlord-wp-fleet' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'ovp_unpair' );
		echo '<input type="hidden" name="ovp_action" value="unpair">';
		echo '<p><label><input type="checkbox" name="confirm_unpair" value="1"> ' . esc_html__( 'Yes, unpair this site.', 'overlord-wp-fleet' ) . '</label></p>';
		submit_button( __( 'Unpair', 'overlord-wp-fleet' ), 'delete' );
		echo '</form></div>';
	}

	private static function render_maintenance(): void {
		echo '<div class="ovp-card"><h2>' . esc_html__( 'Maintenance', 'overlord-wp-fleet' ) . '</h2>';
		if ( Ovp_Keys::is_bound() ) {
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( 'ovp_heartbeat_now' );
			echo '<input type="hidden" name="ovp_action" value="heartbeat_now">';
			submit_button( __( 'Send heartbeat now', 'overlord-wp-fleet' ), 'secondary', 'submit', false );
			echo '</form> ';
		}

		$log = Ovp_Log::recent();
		echo '<h3>' . esc_html__( 'Recent signed requests', 'overlord-wp-fleet' ) . '</h3>';
		if ( array() === $log ) {
			echo '<p>' . esc_html__( 'None yet.', 'overlord-wp-fleet' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Method</th><th>Route</th><th>Status</th><th>Note</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 25 ) as $e ) {
				echo '<tr><td>' . esc_html( (string) ( $e['time'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $e['method'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $e['route'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $e['status'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $e['message'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<form method="post">';
			wp_nonce_field( 'ovp_clear_log' );
			echo '<input type="hidden" name="ovp_action" value="clear_log">';
			submit_button( __( 'Clear log', 'overlord-wp-fleet' ), 'secondary' );
			echo '</form>';
		}
		echo '</div>';
	}
}
