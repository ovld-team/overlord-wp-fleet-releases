<?php
/**
 * WordPress update integration for this plugin (docs/AUTO-UPDATE.md sections 5 and 6).
 *
 * The plugin header carries an Update URI, so WordPress asks the `update_plugins_github.com`
 * filter for this plugin. The answer is built only from a verified Ovp_Release index. Packages are
 * downloaded and checked here (URL, size, sha256, signature, version) before WordPress unpacks
 * them, and the unpacked folder is checked again. The offer never asks WordPress to install by
 * itself; the unattended switch below does that. Owner rule (2.0.8): this plugin, and ONLY this
 * plugin, updates itself automatically by default, through WordPress's own unattended path. Nothing
 * else becomes automatic. A human in wp-admin can turn it off (explicit choice, stored with
 * set_by=human) and on again; no remote call can change it. A human-requested remote rollout is a
 * different path and needs effective tier T3, which follows the tier granted at pairing.
 *
 * Identification is by plugin basename. Bulk upgrades do not set hook_extra action or type, so
 * those keys are never used to decide whether a package is ours.
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Updater {

	public const OPT_AUTO = 'ovp_auto_update';
	public const NONCE    = 'ovp_auto_update';
	public const FOLDER   = 'overlord-wp-fleet';

	/** Version approved by upgrader_pre_download in this request; source selection compares to it. */
	private static ?string $installing_version = null;

	public static function hooks(): void {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_selection' ), 10, 4 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'pre_install' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'process_complete' ), 10, 2 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'filter_auto_update' ), PHP_INT_MAX, 2 );
		add_action( 'update_site_option_auto_update_plugins', array( __CLASS__, 'mirror_site_option' ), 10, 4 );
		add_action( 'add_site_option_auto_update_plugins', array( __CLASS__, 'mirror_site_add' ), 10, 3 );
		// Single-site WordPress stores the site option as a plain option and fires these instead.
		add_action( 'update_option_auto_update_plugins', array( __CLASS__, 'mirror_plain_update' ), 10, 3 );
		add_action( 'add_option_auto_update_plugins', array( __CLASS__, 'mirror_site_add' ), 10, 2 );
		add_filter( 'plugin_auto_update_setting_html', array( __CLASS__, 'filter_setting_html' ), 10, 3 );
		// Core writes an unchanged auto_update_plugins list (no option hook fires) when a human disables
		// an entry that was never in the list, which is the default-on case. Catch the click first.
		add_action( 'wp_ajax_toggle-auto-updates', array( __CLASS__, 'pre_ajax_toggle' ), 0 );
		add_action( 'load-plugins.php', array( __CLASS__, 'pre_plugins_screen' ), 0 );
	}

	/* ------------------------------------------------------------------ identity */

	public static function basename(): string {
		return plugin_basename( OVP_FLEET_FILE );
	}

	/** True when the upgrader context names this plugin (single or bulk). */
	public static function is_ours( array $hook_extra ): bool {
		$base = self::basename();
		if ( ( $hook_extra['plugin'] ?? null ) === $base ) {
			return true;
		}
		return in_array( $base, (array) ( $hook_extra['plugins'] ?? array() ), true );
	}

	/* ------------------------------------------------------------------ offer */

	/**
	 * The verified, newer release that fits this site, or null.
	 *
	 * @return array{index:array<string,mixed>,key_id:string}|null
	 */
	private static function current_offer(): ?array {
		$r = Ovp_Release::current();
		if ( empty( $r['verified'] ) || ! is_array( $r['index'] ) ) {
			return null;
		}
		$d = Ovp_Release::offer_decision( $r['index'], OVP_FLEET_VERSION, PHP_VERSION, self::wp_version() );
		return $d['ok'] ? array( 'index' => $r['index'], 'key_id' => $r['key_id'] ) : null;
	}

	private static function wp_version(): string {
		global $wp_version;
		return is_string( $wp_version ) ? $wp_version : '0';
	}

	/**
	 * @param mixed                $update      Previous answer.
	 * @param array<string,mixed>  $plugin_data Plugin header data.
	 * @param string               $plugin_file Basename.
	 * @param array<int,string>    $locales
	 * @return mixed
	 */
	public static function filter_update( $update, $plugin_data, $plugin_file, $locales = array() ) {
		unset( $plugin_data, $locales );
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$o = self::current_offer();
		if ( null === $o ) {
			return false;
		}
		$idx = $o['index'];
		$v   = (string) $idx['version'];
		return array(
			'slug'         => Ovp_Release::SLUG,
			'version'      => $v,
			'url'          => 'https://github.com/' . Ovp_Release::REPO . '/releases/tag/v' . $v,
			'package'      => Ovp_Release::zip_url( $v ),
			'requires'     => (string) $idx['requires_wp'],
			'requires_php' => (string) $idx['requires_php'],
			'tested'       => (string) ( $idx['tested_wp'] ?? '' ),
			'icons'        => array(),
		);
	}

	/**
	 * "View details" data, from a verified index only.
	 *
	 * @param mixed $result
	 * @param mixed $action
	 * @param mixed $args
	 * @return mixed
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== Ovp_Release::SLUG ) {
			return $result;
		}
		$r = Ovp_Release::current();
		if ( empty( $r['verified'] ) || ! is_array( $r['index'] ) ) {
			return $result;
		}
		$idx = $r['index'];
		$v   = (string) $idx['version'];
		return (object) array(
			'name'          => 'Overlord WP Fleet',
			'slug'          => Ovp_Release::SLUG,
			'version'       => $v,
			'author'        => 'Overlord Team',
			'homepage'      => 'https://overlord.team',
			'requires'      => (string) $idx['requires_wp'],
			'requires_php'  => (string) $idx['requires_php'],
			'tested'        => (string) ( $idx['tested_wp'] ?? '' ),
			'last_updated'  => (string) ( $idx['released_at'] ?? '' ),
			'sections'      => array( 'changelog' => '<pre>' . esc_html( (string) ( $idx['changelog'] ?? '' ) ) . '</pre>' ),
			'download_link' => Ovp_Release::zip_url( $v ),
		);
	}

	/* ------------------------------------------------------------------ package checks */

	/**
	 * Replace the download of this plugin's package with a verified one.
	 *
	 * @param mixed $reply
	 * @param mixed $package
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public static function pre_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		$extra = is_array( $hook_extra ) ? $hook_extra : array();
		$pkg   = is_string( $package ) ? $package : '';
		if ( ! self::is_ours( $extra ) && ! str_starts_with( $pkg, Ovp_Release::DL_PREFIX ) ) {
			return $reply;
		}
		self::$installing_version = null;

		$r = Ovp_Release::current();
		if ( empty( $r['verified'] ) || ! is_array( $r['index'] ) ) {
			return new WP_Error( 'ovp_update_bad_sig', 'No signed release is available, so the package was refused.' );
		}
		$idx = $r['index'];
		$ver = Ovp_Release::verify( $idx );
		if ( is_wp_error( $ver ) ) {
			return new WP_Error( 'ovp_update_bad_sig', $ver->get_error_message() );
		}
		$d = Ovp_Release::offer_decision( $idx, OVP_FLEET_VERSION, PHP_VERSION, self::wp_version() );
		if ( ! $d['ok'] ) {
			return 'not_newer' === $d['reason']
				? new WP_Error( 'ovp_update_not_newer', 'The release is not newer than the installed version.' )
				: new WP_Error( 'ovp_update_requirements', 'This site does not meet the release requirements (' . $d['reason'] . ').' );
		}
		$url = Ovp_Release::zip_url( (string) $idx['version'] );
		if ( $pkg !== $url ) {
			return new WP_Error( 'ovp_update_url', 'The package URL is not the official release URL for the signed version.' );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( basename( $url ) );
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return new WP_Error( 'ovp_update_download', 'Could not create a temporary file for the package.' );
		}
		$fail = static function ( WP_Error $e ) use ( $tmp ): WP_Error {
			if ( is_file( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- own temp file.
			}
			return $e;
		};

		$dl = Ovp_Release::download_zip( $url, $tmp );
		if ( is_wp_error( $dl ) ) {
			return $fail( $dl );
		}
		$size = filesize( $tmp );
		if ( false === $size || $size !== (int) $idx['size'] ) {
			return $fail( new WP_Error( 'ovp_update_size', 'Downloaded package size does not match the signed release.' ) );
		}
		$sha = hash_file( 'sha256', $tmp );
		if ( ! is_string( $sha ) || ! hash_equals( (string) $idx['sha256'], $sha ) ) {
			return $fail( new WP_Error( 'ovp_update_sha', 'Downloaded package checksum does not match the signed release.' ) );
		}
		self::$installing_version = (string) $idx['version'];
		return $tmp;
	}

	/**
	 * Check the unpacked folder before WordPress moves it into place.
	 *
	 * @param mixed $source
	 * @param mixed $remote_source
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public static function source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
		unset( $remote_source, $upgrader );
		if ( ! is_string( $source ) ) {
			return $source;
		}
		$extra = is_array( $hook_extra ) ? $hook_extra : array();
		// Bulk path: keys 'plugin'/'plugins'. Fresh install path: neither key, but our pre_download ran.
		$unnamed = ! isset( $extra['plugin'] ) && ! isset( $extra['plugins'] );
		if ( ! self::is_ours( $extra ) && ! ( $unnamed && null !== self::$installing_version ) ) {
			return $source;
		}
		$mismatch = static fn( string $why ) => new WP_Error( 'ovp_update_package_mismatch', 'The unpacked package is not Overlord WP Fleet: ' . $why . '.' );

		if ( basename( untrailingslashit( $source ) ) !== self::FOLDER ) {
			return $mismatch( 'unexpected folder name' );
		}
		$main = trailingslashit( $source ) . self::FOLDER . '.php';
		if ( ! is_file( $main ) ) {
			return $mismatch( 'main file is missing' );
		}
		$h = get_file_data( $main, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
		if ( 'Overlord WP Fleet' !== ( $h['Name'] ?? '' ) ) {
			return $mismatch( 'unexpected plugin name' );
		}
		if ( null === self::$installing_version || ( $h['Version'] ?? '' ) !== self::$installing_version ) {
			return $mismatch( 'version differs from the signed release' );
		}
		return $source;
	}

	/**
	 * @param mixed $response
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public static function pre_install( $response, $hook_extra = array() ) {
		if ( ! is_array( $hook_extra ) || ! self::is_ours( $hook_extra ) || ! class_exists( 'Ovp_Self_Update' ) ) {
			return $response;
		}
		$r = Ovp_Self_Update::before_install( $response, $hook_extra );
		return is_wp_error( $r ) ? $r : $response;
	}

	/**
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 */
	public static function process_complete( $upgrader, $hook_extra = array() ): void {
		if ( ! is_array( $hook_extra ) || ! self::is_ours( $hook_extra ) || ! class_exists( 'Ovp_Self_Update' ) ) {
			return;
		}
		// A bulk list is only ours to act on for an update; a direct plugin key always is.
		if ( ( $hook_extra['plugin'] ?? null ) !== self::basename() && 'update' !== ( $hook_extra['action'] ?? '' ) ) {
			return;
		}
		Ovp_Self_Update::after_install( $upgrader, $hook_extra );
	}

	/* ------------------------------------------------------------------ automatic update switch */

	/**
	 * The stored human choice, or null when none counts.
	 *
	 * Migration rule: only a stored value with set_by === "human" and a boolean "on" is a choice. A
	 * missing option, or a value written by 2.0.7 (`{on, by, at}`, no set_by), means "never set" and the
	 * default applies, which is ON.
	 */
	public static function human_choice(): ?bool {
		$o = get_option( self::OPT_AUTO, false );
		if ( ! is_array( $o ) || 'human' !== ( $o['set_by'] ?? null ) || ! array_key_exists( 'on', $o ) || ! is_bool( $o['on'] ) ) {
			return null;
		}
		return $o['on'];
	}

	/** On unless a human explicitly turned it off. */
	public static function auto_enabled(): bool {
		$c = self::human_choice();
		return null === $c ? true : $c;
	}

	/** @return 'default_on'|'on'|'off' */
	public static function auto_status(): string {
		$c = self::human_choice();
		return null === $c ? 'default_on' : ( $c ? 'on' : 'off' );
	}

	private static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Human-only switch. Refused for any request that has carried a signed remote call, for users
	 * without update_plugins, and without the nonce. Under WP-CLI turning it ON is refused outright
	 * (enable it in the settings page or the Plugins screen); turning it OFF is allowed.
	 */
	public static function admin_set_auto( bool $on, string $nonce ): bool {
		if ( Ovp_Proto::remote_seen() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'update_plugins' ) ) {
			return false;
		}
		if ( $on && self::is_cli() ) {
			return false;
		}
		if ( ! self::is_cli() && ( ! function_exists( 'wp_verify_nonce' ) || false === wp_verify_nonce( $nonce, self::NONCE ) ) ) {
			return false;
		}
		// Only our own option is written. WordPress's auto_update_plugins list is never written from here:
		// the filter below does not read it, and the Plugins row shows an "Effective" note when they differ.
		self::store( $on );
		return true;
	}

	/** Callers have already proven a human (not a remote call) with update_plugins. */
	private static function store( bool $on ): void {
		update_option(
			self::OPT_AUTO,
			array( 'on' => $on, 'set_by' => 'human', 'by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'at' => time() ),
			false
		);
	}

	private static function human_with_cap(): bool {
		return ! Ovp_Proto::remote_seen()
			&& function_exists( 'current_user_can' ) && current_user_can( 'update_plugins' );
	}

	private static function unslash( string $v ): string {
		return function_exists( 'wp_unslash' ) ? (string) wp_unslash( $v ) : $v;
	}

	/** @param array<int,string> $keys */
	private static function nonce_ok( array $keys, string $action ): bool {
		if ( ! function_exists( 'wp_verify_nonce' ) ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- this is the verification.
		foreach ( $keys as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && is_string( $_REQUEST[ $k ] ) && false !== wp_verify_nonce( self::unslash( $_REQUEST[ $k ] ), $action ) ) {
				return true;
			}
		}
		// phpcs:enable
		return false;
	}

	/**
	 * Plugins-screen "Disable/Enable auto-updates" ajax link for OUR row: record the human's explicit
	 * choice even when core's list does not change (it never held our entry under the default).
	 * Runs before core's handler (priority 0; core registers at 1); core still verifies and writes its own list as it always does.
	 */
	public static function pre_ajax_toggle(): void {
		if ( ! self::human_with_cap() || ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax()
			|| ! self::nonce_ok( array( '_ajax_nonce', '_wpnonce', 'nonce' ), 'updates' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- verified just above.
		$asset = isset( $_POST['asset'] ) && is_string( $_POST['asset'] ) ? trim( urldecode( self::unslash( $_POST['asset'] ) ) ) : '';
		$state = isset( $_POST['state'] ) && is_string( $_POST['state'] ) ? self::unslash( $_POST['state'] ) : '';
		// phpcs:enable
		if ( self::basename() !== $asset || ! in_array( $state, array( 'enable', 'disable' ), true ) ) {
			return;
		}
		$on = 'enable' === $state;
		if ( self::human_choice() !== $on ) {
			self::store( $on );
		}
	}

	/** Plugins screen: the single-row link and the bulk "Enable/Disable auto-updates" actions, for OUR entry only. */
	public static function pre_plugins_screen(): void {
		if ( ! self::human_with_cap() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- verified below per action.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) && is_string( $_REQUEST['action2'] ) ? $_REQUEST['action2'] : '';
		}
		$names = array();
		if ( in_array( $action, array( 'enable-auto-update', 'disable-auto-update' ), true ) ) {
			if ( ! self::nonce_ok( array( '_wpnonce' ), 'updates' ) ) {
				return;
			}
			$names = isset( $_GET['plugin'] ) && is_string( $_GET['plugin'] ) ? array( self::unslash( $_GET['plugin'] ) ) : array();
		} elseif ( in_array( $action, array( 'enable-auto-update-selected', 'disable-auto-update-selected' ), true ) ) {
			if ( ! self::nonce_ok( array( '_wpnonce' ), 'bulk-plugins' ) ) {
				return;
			}
			$checked = isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ? $_POST['checked'] : array();
			foreach ( $checked as $c ) {
				if ( is_string( $c ) ) {
					$names[] = self::unslash( $c );
				}
			}
		} else {
			return;
		}
		// phpcs:enable
		foreach ( $names as $n ) {
			if ( self::basename() === trim( urldecode( $n ) ) ) {
				$on = str_starts_with( $action, 'enable' );
				if ( self::human_choice() !== $on ) {
					self::store( $on );
				}
				return;
			}
		}
	}

	/** True when the human's ajax toggle in this request names OUR plugin and asks for "enable". */
	private static function human_enabled_ours(): bool {
		if ( ! self::human_with_cap() || ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- core verifies the ajax nonce before it writes the option.
		$action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
		$asset  = isset( $_POST['asset'] ) && is_string( $_POST['asset'] ) ? trim( urldecode( self::unslash( $_POST['asset'] ) ) ) : '';
		$state  = isset( $_POST['state'] ) && is_string( $_POST['state'] ) ? self::unslash( $_POST['state'] ) : '';
		// phpcs:enable
		return 'toggle-auto-updates' === $action && self::basename() === $asset && 'enable' === $state;
	}

	/**
	 * Mirror a change of OUR entry in auto_update_plugins. Only a change of our own membership counts:
	 * a remote write that parked our basename in the list, followed by a human toggling a DIFFERENT plugin,
	 * leaves our membership unchanged and does nothing.
	 * Turning it OFF is always honoured for a non-remote user with update_plugins (disabling is safe).
	 * Turning it ON is honoured only for the Plugins-screen ajax toggle that names our plugin with
	 * state=enable. WP-CLI and bulk non-ajax enables are refused: use the settings-page button.
	 *
	 * @param mixed $old Previous value (array or false).
	 * @param mixed $new New value.
	 */
	private static function mirror( $old, $new ): void {
		$base = self::basename();
		$had  = is_array( $old ) && in_array( $base, $old, true );
		$has  = is_array( $new ) && in_array( $base, $new, true );
		if ( $had === $has ) {
			return;
		}
		if ( $has ) {
			if ( self::human_enabled_ours() && ! self::auto_enabled() ) {
				self::store( true );
			}
			return;
		}
		if ( ( self::human_with_cap() || ( self::is_cli() && ! Ovp_Proto::remote_seen() ) ) && self::auto_enabled() ) {
			self::store( false );
		}
	}

	/** update_site_option_* (option, value, old_value, network_id). @param mixed $option @param mixed $value @param mixed $old */
	public static function mirror_site_option( $option, $value = null, $old = null ): void {
		unset( $option );
		self::mirror( $old, $value );
	}

	/** add_site_option_* (option, value, network_id) and add_option_* (option, value). @param mixed $option @param mixed $value */
	public static function mirror_site_add( $option, $value = null ): void {
		unset( $option );
		self::mirror( array(), $value );
	}

	/** update_option_* (old_value, value, option). @param mixed $old @param mixed $value @param mixed $option */
	public static function mirror_plain_update( $old, $value = null, $option = '' ): void {
		unset( $option );
		self::mirror( $old, $value );
	}

	/**
	 * @param mixed $update
	 * @param mixed $item
	 * @return mixed
	 */
	public static function filter_auto_update( $update, $item ) {
		if ( ! is_object( $item ) || ( $item->plugin ?? '' ) !== self::basename() ) {
			return $update; // Any other plugin: input returned unchanged, in both directions.
		}
		if ( null === $update ) {
			return null; // Plugins-list UI probe: keep WordPress's toggle visible.
		}
		if ( ! self::auto_enabled() ) {
			return false;
		}
		// True only for the verified, newer, requirement-satisfying signed release that is on offer.
		$o = self::current_offer();
		return null !== $o && isset( $item->new_version ) && (string) $item->new_version === (string) $o['index']['version'];
	}

	/**
	 * @param mixed $html
	 * @param mixed $plugin_file
	 * @param mixed $plugin_data
	 * @return mixed
	 */
	public static function filter_setting_html( $html, $plugin_file, $plugin_data = null ) {
		unset( $plugin_data );
		if ( self::basename() !== $plugin_file || ! is_string( $html ) ) {
			return $html;
		}
		$list = get_site_option( 'auto_update_plugins', array() );
		$wp   = is_array( $list ) && in_array( self::basename(), $list, true );
		$mine = self::auto_enabled();
		if ( $wp === $mine ) {
			return $html;
		}
		$line = $mine
			? 'Effective: on (this plugin updates itself automatically; change it in Settings > Overlord WP Fleet).'
			: 'Effective: off (turned off by an admin). Turn it on in Settings > Overlord WP Fleet.';
		return $html . '<br><span class="description">' . esc_html( $line ) . '</span>';
	}
}
