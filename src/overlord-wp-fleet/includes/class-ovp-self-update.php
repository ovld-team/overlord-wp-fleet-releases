<?php
/**
 * Backup, loopback probe and restore around an update of this plugin.
 *
 * Runs on every update path (native wp-admin, background auto update, WP-CLI, the signed
 * fleet/self-update route and the T3 in-place replace, guarded_replace()). It only copies and
 * swaps this plugin's own folder and its staging subfolder; it never activates or deactivates
 * anything and never touches content. State lives in the option ovp_self_update_state, the probe
 * token in ovp_selfcheck.
 *
 * Who may start a remote update: effective tier T3 (the cap follows the tier the human granted at
 * pairing, so no wp-admin visit is needed), plus the human_requested and confirm-token rules on the
 * master. The WordPress unattended update of this plugin (ovp_auto_update) is ON by default since
 * 2.0.8; only a human in wp-admin can change it, never a remote call.
 *
 * @package OverlordWpFleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ovp_Self_Update {

	public const OPT_STATE = 'ovp_self_update_state';
	public const OPT_CHECK = 'ovp_selfcheck';
	public const HEADER    = 'X-OVP-Selfcheck';
	public const SLUG      = 'overlord-wp-fleet';
	public const KEEP      = 2;
	public const NONCE     = 'ovp_self_restore';
	public const TOKEN_TTL = 120;

	/** What this request's before_install did: {from, backup}. Null when it never ran here. */
	private static ?array $run = null;

	public static function hooks(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_selfcheck_route' ) );
		add_action( 'wp_ajax_ovp_selfcheck', array( __CLASS__, 'ajax_selfcheck' ) );
		add_action( 'wp_ajax_nopriv_ovp_selfcheck', array( __CLASS__, 'ajax_selfcheck' ) );
	}

	/* ------------------------------------------------------------------ paths */

	private static function basename(): string {
		return self::SLUG . '/' . self::SLUG . '.php';
	}

	private static function plugin_dir(): string {
		return rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . '/' . self::SLUG;
	}

	private static function is_ours( array $hook_extra ): bool {
		if ( ( $hook_extra['plugin'] ?? null ) === self::basename() ) {
			return true;
		}
		return is_array( $hook_extra['plugins'] ?? null ) && in_array( self::basename(), $hook_extra['plugins'], true );
	}

	/** Version header of the main file that is on disk now (the running code may be older). */
	public static function disk_version( ?string $dir = null ): string {
		$file = ( $dir ?? self::plugin_dir() ) . '/' . self::SLUG . '.php';
		$src  = is_file( $file ) ? (string) file_get_contents( $file, false, null, 0, 8192 ) : '';
		return 1 === preg_match( '/^[ \t\/*#@]*Version:[ \t]*(\S+)/mi', $src, $m ) ? $m[1] : '';
	}

	/* ------------------------------------------------------------------ state */

	/** @return array<string,mixed> */
	public static function state(): array {
		self::ingest_guard_result();
		$s = get_option( self::OPT_STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	/** The load guard cannot rely on the plugin, so it leaves its verdict in a file; fold it into the state option. */
	private static function ingest_guard_result(): void {
		$dir = OVP_Upload::sub_dir( 'self-update' );
		if ( $dir instanceof WP_Error ) {
			return;
		}
		$f = $dir . '/guard-result.json';
		if ( ! is_file( $f ) ) {
			return;
		}
		$d = json_decode( (string) @file_get_contents( $f ), true );
		@unlink( $f );
		if ( ! is_array( $d ) || 'rolled_back' !== ( $d['state'] ?? null ) ) {
			return;
		}
		update_option(
			self::OPT_STATE,
			array(
				'from'   => (string) ( $d['from'] ?? '' ),
				'to'     => (string) ( $d['to'] ?? '' ),
				'backup' => (string) ( $d['backup_version'] ?? '' ),
				'reason' => 'guard_fatal_on_load',
				'state'  => 'rolled_back',
				'at'     => (int) ( $d['at'] ?? time() ),
			),
			false
		);
	}

	/** @param array<string,mixed> $extra */
	private static function set_state( string $state, array $extra = array() ): void {
		$old = self::state();
		$new = array_merge(
			array(
				'from'   => $old['from'] ?? OVP_FLEET_VERSION,
				'to'     => $old['to'] ?? '',
				'backup' => $old['backup'] ?? '',
				'reason' => is_string( $old['reason'] ?? null ) && str_starts_with( $old['reason'], 'guard_unavailable' ) ? $old['reason'] : '',
			),
			$extra,
			array( 'state' => $state, 'at' => time() )
		);
		update_option( self::OPT_STATE, $new, false );
	}

	/* ------------------------------------------------------------------ guard */

	public const GUARD_MARKER = 'OVP_UPDATE_GUARD';

	/** Warning left by install_guard() when the mu-plugins folder is not usable. */
	private static string $guard_warning = '';

	private static function guard_path(): string {
		$mu = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : (string) WP_CONTENT_DIR . '/mu-plugins';
		return rtrim( $mu, '/\\' ) . '/000-ovp-update-guard.php';
	}

	private static function pending_path(): ?string {
		$dir = OVP_Upload::sub_dir( 'self-update' );
		return $dir instanceof WP_Error ? null : $dir . '/pending.json';
	}

	/**
	 * Source of the must-use load guard. PHP 7 syntax only, no plugin header.
	 * pending.json sits beside $state_path; the guard writes its verdict to $state_path.
	 */
	public static function guard_source( string $state_path, string $plugin_dir, string $backup_dir ): string {
		$tpl = <<<'OVPGUARD'
<?php
// OVP_UPDATE_GUARD - temporary load guard written by the Overlord fleet plugin during its own update.
// It removes itself when the update is confirmed, rolled back or older than 24 hours.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}
$ovp_guard_run = function () {
	$state_path = __OVP_STATE__;
	$plugin_dir = __OVP_PLUGIN__;
	$backup_dir = __OVP_BACKUP__;
	$marker     = dirname( $state_path ) . '/pending.json';
	$self       = __FILE__;
	$clean      = function () use ( $marker, $self ) {
		@unlink( $marker );
		@unlink( $self );
	};
	if ( ! is_file( $marker ) ) {
		@unlink( $self );
		return;
	}
	// Read under a shared lock: a writer holding the exclusive lock may be between truncate and write.
	$m = null;
	$h = @fopen( $marker, 'r' );
	if ( $h ) {
		if ( flock( $h, LOCK_SH ) ) {
			$m = json_decode( (string) stream_get_contents( $h ), true );
		}
		fclose( $h );
	}
	if ( ! is_array( $m ) ) {
		// Unreadable now: never delete on a doubtful read, only when it is clearly stale.
		if ( is_file( $marker ) && time() - (int) @filemtime( $marker ) > 86400 ) {
			$clean();
		}
		return;
	}
	if ( ! empty( $m['done'] ) ) {
		$clean();
		return;
	}
	$created = isset( $m['created'] ) ? (int) $m['created'] : 0;
	if ( $created <= 0 || time() - $created > 86400 ) {
		$clean();
		return;
	}
	$from = isset( $m['from'] ) && is_string( $m['from'] ) ? $m['from'] : '';
	$to   = isset( $m['to'] ) && is_string( $m['to'] ) ? $m['to'] : '';
	$disk = '';
	$main = $plugin_dir . '/overlord-wp-fleet.php';
	if ( is_file( $main ) ) {
		$head = (string) @file_get_contents( $main, false, null, 0, 8192 );
		if ( 1 === preg_match( '/^[ \t\/*#@]*Version:[ \t]*(\S+)/mi', $head, $vm ) ) {
			$disk = $vm[1];
		}
	}
	if ( '' === $disk ) {
		return;
	}
	if ( '' === $to ) {
		// The update has not reported its target yet: act only when the files already differ from the old version.
		if ( $disk === $from ) {
			return;
		}
		$to = $disk;
	} elseif ( $disk !== $to ) {
		$clean();
		return;
	}
	// An inactive plugin never loads, so it can never confirm: leave it alone.
	if ( function_exists( 'get_option' ) ) {
		$base   = 'overlord-wp-fleet/overlord-wp-fleet.php';
		$active = in_array( $base, (array) get_option( 'active_plugins', array() ), true );
		if ( ! $active && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$net    = get_site_option( 'active_sitewide_plugins', array() );
			$active = is_array( $net ) && isset( $net[ $base ] );
		}
		if ( ! $active ) {
			return;
		}
	}
	// Run a callback on the marker under an exclusive lock; it may change the array and return a verdict.
	$locked = function ( $fn ) use ( $marker ) {
		$h = @fopen( $marker, 'r+' );
		if ( ! $h ) {
			return null;
		}
		$out = null;
		if ( flock( $h, LOCK_EX ) ) {
			$cur = json_decode( (string) stream_get_contents( $h ), true );
			if ( is_array( $cur ) && empty( $cur['done'] ) ) {
				$out = $fn( $cur );
				rewind( $h );
				ftruncate( $h, 0 );
				fwrite( $h, json_encode( $cur ) );
				fflush( $h );
			}
			flock( $h, LOCK_UN );
		}
		fclose( $h );
		return $out;
	};
	$rm = function ( $path ) use ( &$rm ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			return @unlink( $path );
		}
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $e ) {
				if ( '.' !== $e && '..' !== $e ) {
					$rm( $path . '/' . $e );
				}
			}
			return @rmdir( $path );
		}
		return true;
	};
	$cp = function ( $src, $dst ) use ( &$cp ) {
		if ( is_link( $src ) ) {
			return true;
		}
		if ( is_dir( $src ) ) {
			if ( ! is_dir( $dst ) && ! @mkdir( $dst, 0755, true ) ) {
				return false;
			}
			foreach ( (array) scandir( $src ) as $e ) {
				if ( '.' !== $e && '..' !== $e && ! $cp( $src . '/' . $e, $dst . '/' . $e ) ) {
					return false;
				}
			}
			return true;
		}
		return @copy( $src, $dst );
	};
	$restore = function () use ( $plugin_dir, $backup_dir, $rm, $cp ) {
		if ( ! is_dir( $backup_dir ) ) {
			return false;
		}
		$failed = dirname( $backup_dir ) . '/failed-' . gmdate( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
		if ( is_dir( $plugin_dir ) ) {
			if ( ! @rename( $plugin_dir, $failed ) ) {
				if ( ! $cp( $plugin_dir, $failed ) ) {
					$rm( $failed );
					return false;
				}
				$rm( $plugin_dir );
			}
		}
		if ( ! @rename( $backup_dir, $plugin_dir ) && ! $cp( $backup_dir, $plugin_dir ) ) {
			$rm( $plugin_dir );
			if ( is_dir( $failed ) ) {
				@rename( $failed, $plugin_dir );
			}
			return false;
		}
		return true;
	};

	add_action(
		'plugins_loaded',
		function () use ( $locked, $to ) {
			if ( defined( 'OVP_FLEET_VERSION' ) && OVP_FLEET_VERSION === $to ) {
				$locked(
					function ( &$cur ) {
						$cur['confirmed'] = time();
					}
				);
			}
		},
		PHP_INT_MIN,
		0
	);

	$verdict = $locked(
		function ( &$cur ) use ( $restore ) {
			if ( ! empty( $cur['confirmed'] ) ) {
				return 'confirmed';
			}
			$starts = isset( $cur['starts'] ) && is_array( $cur['starts'] ) ? array_values( $cur['starts'] ) : array();
			$now    = time();
			$starts[]       = $now;
			$cur['starts']  = array_slice( $starts, -10 );
			$old            = 0;
			foreach ( $cur['starts'] as $t ) {
				if ( $now - (int) $t >= 20 ) {
					++$old;
				}
			}
			if ( $old < 3 ) {
				return 'wait';
			}
			if ( ! $restore() ) {
				return 'restore_failed';
			}
			$cur['done'] = true;
			return 'restored';
		}
	);
	if ( 'restored' === $verdict ) {
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		@file_put_contents(
			$state_path,
			json_encode(
				array(
					'state'  => 'rolled_back',
					'reason' => 'guard_fatal_on_load',
					'from'   => $from,
					'to'     => $to,
					'backup_version' => $from,
					'at'     => time(),
				)
			)
		);
		$clean();
	}
};
$ovp_guard_run();
unset( $ovp_guard_run );
OVPGUARD;
		return strtr(
			$tpl,
			array(
				'__OVP_STATE__'  => var_export( $state_path, true ),
				'__OVP_PLUGIN__' => var_export( $plugin_dir, true ),
				'__OVP_BACKUP__' => var_export( $backup_dir, true ),
			)
		);
	}

	/**
	 * Write pending.json and the guard. Without a writable mu-plugins folder the update goes on
	 * without a guard and a warning is kept for the state.
	 */
	private static function install_guard( string $backup_tree ): bool {
		self::$guard_warning = '';
		$pending             = self::pending_path();
		if ( null === $pending ) {
			self::$guard_warning = 'guard_unavailable: staging folder not usable';
			return true;
		}
		$w = @file_put_contents(
			$pending,
			(string) json_encode(
				array(
					'to'      => '',
					'from'    => (string) OVP_FLEET_VERSION,
					'backup'  => $backup_tree,
					'created' => time(),
				)
			),
			LOCK_EX
		);
		if ( false === $w ) {
			self::$guard_warning = 'guard_unavailable: cannot write pending marker';
			return true;
		}
		$g = OVP_Upload::atomic_write(
			self::guard_path(),
			self::guard_source( dirname( $pending ) . '/guard-result.json', self::plugin_dir(), $backup_tree ),
			array( 'rel' => '000-ovp-update-guard.php' )
		);
		if ( $g instanceof WP_Error ) {
			@unlink( $pending );
			self::$guard_warning = 'guard_unavailable: mu-plugins folder not writable (' . $g->get_error_code() . ')';
		}
		return true;
	}

	/** Tell the guard which version the update installed, and reset its counters. */
	private static function guard_set_target( string $to ): void {
		$pending = self::pending_path();
		if ( null === $pending || ! is_file( $pending ) ) {
			return;
		}
		$h = @fopen( $pending, 'r+' );
		if ( ! $h ) {
			return;
		}
		if ( flock( $h, LOCK_EX ) ) {
			$d = json_decode( (string) stream_get_contents( $h ), true );
			if ( is_array( $d ) ) {
				$d['to'] = $to;
				unset( $d['starts'], $d['confirmed'] );
				rewind( $h );
				ftruncate( $h, 0 );
				fwrite( $h, (string) json_encode( $d ) );
				fflush( $h );
			}
			flock( $h, LOCK_UN );
		}
		fclose( $h );
	}

	/** Delete the guard only if it carries our marker, then the pending marker. */
	private static function remove_guard(): void {
		$g = self::guard_path();
		if ( is_file( $g ) && ! is_link( $g ) && str_contains( (string) file_get_contents( $g, false, null, 0, 800 ), self::GUARD_MARKER ) ) {
			@unlink( $g );
		}
		$pending = self::pending_path();
		if ( null !== $pending && is_file( $pending ) ) {
			@unlink( $pending );
		}
	}

	/* ------------------------------------------------------------------ backup */

	/** @return array<int,array{dir:string,version:string,n:int,created_at:int}> newest first */
	private static function backups(): array {
		$root = OVP_Upload::sub_dir( 'self-update' );
		if ( $root instanceof WP_Error ) {
			return array();
		}
		$out = array();
		foreach ( (array) glob( $root . '/prev/*', GLOB_ONLYDIR ) as $dir ) {
			$m = json_decode( (string) @file_get_contents( $dir . '/manifest.json' ), true );
			if ( ! is_array( $m ) || ! is_string( $m['version'] ?? null ) || ! is_dir( $dir . '/' . self::SLUG ) ) {
				continue;
			}
			$out[] = array(
				'dir'        => $dir,
				'version'    => $m['version'],
				'n'          => (int) ( $m['n'] ?? 0 ),
				'created_at' => (int) ( $m['created_at'] ?? 0 ),
			);
		}
		usort( $out, static fn( array $a, array $b ): int => $b['n'] <=> $a['n'] );
		return $out;
	}

	/**
	 * Newest backup whose version is lower than the version on disk.
	 *
	 * @return array{dir:string,version:string,n:int,created_at:int}|null
	 */
	public static function latest_backup(): ?array {
		$disk = self::disk_version();
		foreach ( self::backups() as $b ) {
			if ( '' === $disk || version_compare( $b['version'], $disk, '<' ) ) {
				return $b;
			}
		}
		return null;
	}

	/**
	 * upgrader_pre_install: copy the current folder aside before WordPress touches it.
	 *
	 * @param mixed $response
	 * @return mixed
	 */
	public static function before_install( $response, array $hook_extra ) {
		if ( ! self::is_ours( $hook_extra ) ) {
			return $response;
		}
		$src = self::plugin_dir();
		if ( ! is_dir( $src ) ) {
			return new WP_Error( 'ovp_update_backup_failed', 'Current plugin folder not found; nothing was changed.' );
		}
		$root = OVP_Upload::sub_dir( 'self-update/prev' );
		if ( $root instanceof WP_Error ) {
			return new WP_Error( 'ovp_update_backup_failed', 'Backup folder unavailable; nothing was changed.' );
		}
		self::$run = null;
		$ver  = (string) OVP_FLEET_VERSION;
		$name = $ver . '-' . gmdate( 'YmdHis' );
		$dir  = $root . '/' . $name;
		for ( $i = 2; file_exists( $dir ); ++$i ) {
			$dir = $root . '/' . $name . '-' . $i;
		}
		if ( ! @mkdir( $dir, 0700, true ) || ! OVP_Upload::copy_tree( $src, $dir . '/' . self::SLUG ) ) {
			OVP_Upload::rrmdir( $dir );
			return new WP_Error( 'ovp_update_backup_failed', 'Could not copy the current version aside; nothing was changed.' );
		}
		$files = 0;
		$bytes = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir . '/' . self::SLUG, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f instanceof SplFileInfo && $f->isFile() ) {
				++$files;
				$bytes += (int) $f->getSize();
			}
		}
		$made_n = (int) ( microtime( true ) * 1000000 );
		$w = OVP_Upload::atomic_write(
			$dir . '/manifest.json',
			(string) json_encode(
				array(
					'version'    => $ver,
					'created_at' => time(),
					'n'          => $made_n,
					'files'      => $files,
					'bytes'      => $bytes,
				)
			)
		);
		if ( $w instanceof WP_Error ) {
			OVP_Upload::rrmdir( $dir );
			return new WP_Error( 'ovp_update_backup_failed', 'Could not write the backup manifest; nothing was changed.' );
		}
		foreach ( array_slice( self::backups(), self::KEEP ) as $old ) {
			OVP_Upload::rrmdir( $old['dir'] );
		}
		self::install_guard( $dir . '/' . self::SLUG );
		self::$run = array(
			'from'   => $ver,
			'backup' => array(
				'dir'        => $dir,
				'version'    => $ver,
				'n'          => $made_n,
				'created_at' => time(),
			),
		);
		self::set_state(
			'installing',
			array(
				'from'    => $ver,
				'to'      => '',
				'backup'  => $ver,
				'reason'  => self::$guard_warning,
			)
		);
		return $response;
	}

	/* ------------------------------------------------------------------ after install */

	/** upgrader_process_complete: probe the new copy, roll back when it does not answer. */
	public static function after_install( $upgrader, array $hook_extra ): void {
		if ( ! self::is_ours( $hook_extra ) ) {
			return;
		}
		// Decide from this request's own state and the disk, never from skin->result
		// (it is absent when a download was refused and belongs to the last plugin in a bulk run).
		$run       = self::$run;
		self::$run = null;
		if ( null === $run ) {
			return; // before_install never ran here: we installed nothing, so there is nothing to probe or restore.
		}
		$from = (string) $run['from'];
		$to   = self::disk_version();
		if ( '' !== $to && $to === $from ) {
			$res    = is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && isset( $upgrader->skin->result ) ? $upgrader->skin->result : null;
			$reason = is_wp_error( $res ) ? $res->get_error_code() : 'update_not_applied';
			self::set_state( 'failed', array( 'reason' => $reason ) );
			self::remove_guard();
			return;
		}
		self::settle( $to, $run );
	}

	/**
	 * After the new files are on disk: tell the guard the target version, probe the new copy, roll back
	 * when it does not answer. Shared by the native update hook and guarded_replace().
	 *
	 * @param array{from:string,backup:array<string,mixed>} $run
	 */
	private static function settle( string $to, array $run ): void {
		if ( '' !== $to ) {
			self::guard_set_target( $to );
		}
		if ( '' !== $to && ! self::plugin_active() ) {
			self::set_state( 'installed_inactive', array( 'to' => $to ) );
			return;
		}
		$p = self::probe( $to );
		if ( $p['ok'] ) {
			self::set_state( 'ok', array( 'to' => $to ) );
			self::remove_guard();
			return;
		}
		self::set_state( 'installing', array( 'to' => $to ) );
		$r = self::restore( 'probe_failed: ' . $p['detail'], $run['backup'] );
		if ( ! $r['ok'] && 'rollback_failed' !== ( self::state()['state'] ?? '' ) ) {
			self::set_state( 'rollback_failed', array( 'reason' => 'probe_failed: ' . $p['detail'] . '; ' . $r['reason'] ) );
		}
		self::remove_guard();
	}

	/**
	 * Effective tier T3 only (the caller checks): run a swap that puts a new copy of this plugin in
	 * its folder inside the same protection the native update path has: lock, previous version copied
	 * aside, load guard, loopback probe of the new code, rollback when it fails. The swap itself is a
	 * callable so this class never unpacks or deletes anything.
	 *
	 * @param callable():(true|WP_Error) $swap
	 * @return array<string,mixed>|WP_Error The settled self-update state.
	 */
	public static function guarded_replace( callable $swap ): array|WP_Error {
		self::load_upgrader_files();
		if ( ! WP_Upgrader::create_lock( self::LOCK, 900 ) ) {
			return new WP_Error( 'ovp_update_busy', 'Another update of this plugin is running.' );
		}
		try {
			$hx  = array( 'plugin' => self::basename() );
			$pre = self::before_install( true, $hx );
			if ( is_wp_error( $pre ) ) {
				return $pre;
			}
			$run       = self::$run;
			self::$run = null;
			if ( null === $run ) {
				return new WP_Error( 'ovp_update_backup_failed', 'No backup was made; nothing was changed.' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$r = $swap();
			if ( is_wp_error( $r ) ) {
				self::set_state( 'failed', array( 'reason' => $r->get_error_code() ) );
				self::remove_guard();
				return $r;
			}
			self::settle( self::disk_version(), $run );
			return self::state();
		} finally {
			WP_Upgrader::release_lock( self::LOCK );
		}
	}

	private static function plugin_active(): bool {
		if ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::basename() ) ) {
			return true;
		}
		return (bool) is_plugin_active( self::basename() );
	}

	/* ------------------------------------------------------------------ probe */

	/** @return array{ok:bool,detail:string} */
	public static function probe( string $expected ): array {
		if ( '' === $expected ) {
			return array( 'ok' => false, 'detail' => 'new version unreadable on disk' );
		}
		$sslverify = (bool) apply_filters( 'https_local_ssl_verify', false );
		$get       = static function ( string $url, ?string $token ) use ( $sslverify ): array|WP_Error {
			return wp_remote_get(
				$url,
				array(
					'timeout'     => 30,
					'sslverify'   => $sslverify,
					'redirection' => 3,
					'headers'     => null !== $token ? array( self::HEADER => $token ) : array(),
				)
			);
		};
		$json = static function ( array|WP_Error $r, string $label ) use ( $expected ): string {
			if ( $r instanceof WP_Error ) {
				return $label . ' unreachable: ' . $r->get_error_message();
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( 200 !== $code ) {
				return $label . ' answered HTTP ' . $code;
			}
			$d = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( ! is_array( $d ) || ( $d['version'] ?? null ) !== $expected ) {
				return $label . ' did not report version ' . $expected;
			}
			return '';
		};

		$fail = $json( $get( rest_url( 'ovp/v1/selfcheck' ), self::new_token() ), 'rest selfcheck' );
		if ( '' !== $fail ) {
			return array( 'ok' => false, 'detail' => $fail );
		}
		$fail = $json( $get( admin_url( 'admin-ajax.php?action=ovp_selfcheck' ), self::new_token() ), 'ajax selfcheck' );
		if ( '' !== $fail ) {
			return array( 'ok' => false, 'detail' => $fail );
		}
		$home = $get( home_url( '/' ), null );
		delete_option( self::OPT_CHECK );
		if ( $home instanceof WP_Error ) {
			return array( 'ok' => false, 'detail' => 'front page unreachable: ' . $home->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $home );
		$body = (string) wp_remote_retrieve_body( $home );
		if ( $code >= 500 || $code < 100 ) {
			return array( 'ok' => false, 'detail' => 'front page answered HTTP ' . $code );
		}
		if ( str_contains( $body, 'wp-die-message' ) || str_contains( $body, 'There has been a critical error' ) ) {
			return array( 'ok' => false, 'detail' => 'front page shows a critical error' );
		}
		return array( 'ok' => true, 'detail' => '' );
	}

	/* ------------------------------------------------------------------ token + endpoints */

	private static function new_token(): string {
		$t = bin2hex( random_bytes( 32 ) );
		// The loopback child deletes the row in another process; this request's object cache would
		// still hold the old value and make update_option() a no-op UPDATE. Drop it, then add fresh.
		wp_cache_delete( self::OPT_CHECK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		delete_option( self::OPT_CHECK );
		wp_cache_delete( self::OPT_CHECK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		add_option( self::OPT_CHECK, array( 'h' => hash( 'sha256', $t ), 'exp' => time() + self::TOKEN_TTL ), '', false );
		return $t;
	}

	/**
	 * Single use on success: the stored token is deleted when it matches, or when it has expired.
	 * A wrong or empty guess does NOT burn it, so an unauthenticated visitor hitting the selfcheck
	 * endpoints during an update window cannot make the probe fail (and force a rollback).
	 */
	public static function token_ok( string $presented ): bool {
		$o = get_option( self::OPT_CHECK, false );
		if ( ! is_array( $o ) ) {
			return false;
		}
		if ( (int) ( $o['exp'] ?? 0 ) < time() ) {
			delete_option( self::OPT_CHECK );
			return false;
		}
		if ( '' === $presented || ! is_string( $o['h'] ?? null ) || ! hash_equals( $o['h'], hash( 'sha256', $presented ) ) ) {
			return false;
		}
		delete_option( self::OPT_CHECK );
		return true;
	}

	public static function register_selfcheck_route(): void {
		register_rest_route(
			'ovp/v1',
			'/selfcheck',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => array( 'version' => OVP_FLEET_VERSION ),
				'permission_callback' => static function ( $request ): bool {
					return self::token_ok( is_object( $request ) ? (string) $request->get_header( self::HEADER ) : '' );
				},
			)
		);
	}

	public static function ajax_selfcheck(): void {
		if ( ! self::token_ok( (string) ( $_SERVER['HTTP_X_OVP_SELFCHECK'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security
			wp_send_json( array( 'error' => 'forbidden' ), 403 );
			return;
		}
		wp_send_json( array( 'version' => OVP_FLEET_VERSION ) );
	}

	/* ------------------------------------------------------------------ restore */

	/** @return array{ok:bool,reason:string,version:string} */
	public static function restore( string $reason, ?array $backup = null ): array {
		$b = $backup ?? self::latest_backup();
		if ( null === $b ) {
			return array( 'ok' => false, 'reason' => 'no_lower_backup', 'version' => '' );
		}
		$stage = OVP_Upload::sub_dir( 'self-update' );
		if ( $stage instanceof WP_Error ) {
			self::set_state( 'rollback_failed', array( 'reason' => $reason . '; staging unavailable' ) );
			return array( 'ok' => false, 'reason' => 'staging_unavailable', 'version' => $b['version'] );
		}
		$tmp = $stage . '/restore-' . bin2hex( random_bytes( 5 ) );
		if ( ! @mkdir( $tmp, 0700, true ) || ! OVP_Upload::copy_tree( $b['dir'] . '/' . self::SLUG, $tmp . '/' . self::SLUG ) ) {
			OVP_Upload::rrmdir( $tmp );
			if ( self::already_restored( $b ) ) {
				return self::finish_already_restored( $reason, $b );
			}
			self::set_state( 'rollback_failed', array( 'reason' => $reason . '; could not copy the backup' ) );
			return array( 'ok' => false, 'reason' => 'copy_failed', 'version' => $b['version'] );
		}
		$sw = OVP_Upload::swap_dir( $tmp . '/' . self::SLUG, self::plugin_dir(), 'plugin', self::SLUG );
		OVP_Upload::rrmdir( $tmp );
		if ( $sw instanceof WP_Error ) {
			if ( self::already_restored( $b ) ) {
				return self::finish_already_restored( $reason, $b );
			}
			self::set_state( 'rollback_failed', array( 'reason' => $reason . '; ' . $sw->get_error_code() ) );
			return array( 'ok' => false, 'reason' => $sw->get_error_code(), 'version' => $b['version'] );
		}
		OVP_Upload::opcache_invalidate_dir( self::plugin_dir() );
		self::set_state( 'rolled_back', array( 'reason' => $reason, 'backup' => $b['version'] ) );
		self::remove_guard();
		return array( 'ok' => true, 'reason' => $reason, 'version' => $b['version'] );
	}

	/**
	 * The mu-plugin guard restores by moving the backup into place and may have done so while this
	 * process was still probing. When the disk already holds the backup's version, the site is
	 * restored: do not record rollback_failed over the guard's verdict.
	 *
	 * @param array{version:string} $b
	 */
	private static function already_restored( array $b ): bool {
		return '' !== $b['version'] && self::disk_version() === $b['version'];
	}

	/**
	 * @param array{version:string} $b
	 * @return array{ok:bool,reason:string,version:string}
	 */
	private static function finish_already_restored( string $reason, array $b ): array {
		OVP_Upload::opcache_invalidate_dir( self::plugin_dir() );
		self::set_state( 'rolled_back', array( 'reason' => $reason . '; already restored by the guard', 'backup' => $b['version'] ) );
		self::remove_guard();
		return array( 'ok' => true, 'reason' => $reason, 'version' => $b['version'] );
	}

	/* ------------------------------------------------------------------ remote routes */

	public const LOCK = 'ovp_self_update';

	/** Core admin files the native upgrader needs; a REST request has none of them loaded. */
	private static function load_upgrader_files(): void {
		foreach ( array( 'file.php', 'misc.php', 'plugin.php', 'class-wp-upgrader.php' ) as $f ) {
			require_once ABSPATH . 'wp-admin/includes/' . $f;
		}
	}

	/** @return array{file_mods:bool,fs_direct:bool,file_mods_code:string} */
	private static function fs_capability(): array {
		$mods = ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		if ( $mods && function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'plugin_update' ) ) {
			$mods = false;
		}
		$direct = function_exists( 'get_filesystem_method' ) && 'direct' === get_filesystem_method();
		return array( 'file_mods' => $mods, 'fs_direct' => $direct, 'file_mods_code' => $mods ? '' : 'ovp_update_file_mods_disabled' );
	}

	/**
	 * Signed fleet/self-update (design 7.1). Pinned to this site's own verified release index: the
	 * body can only name the release the site itself sees as the latest signed one.
	 *
	 * Runs the native BULK upgrader, exactly what the wp-admin "Update now" link does. It never
	 * calls Plugin_Upgrader::upgrade(): outside of cron that method silently deactivates the plugin
	 * and nothing would reactivate it.
	 *
	 * @param array<string,mixed> $body Already shape-validated by the route.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function remote_update( array $body ): array|WP_Error {
		$want_v   = (string) ( $body['version'] ?? '' );
		$want_sha = strtolower( (string) ( $body['sha256'] ?? '' ) );
		self::load_upgrader_files();
		if ( ! WP_Upgrader::create_lock( self::LOCK, 900 ) ) {
			return new WP_Error( 'ovp_update_busy', 'Another update of this plugin is running.' );
		}
		try {
			$fs = self::fs_capability();
			if ( ! $fs['file_mods'] ) {
				return new WP_Error( 'ovp_update_file_mods_disabled', 'File modifications are disabled on this site.' );
			}
			if ( ! $fs['fs_direct'] ) {
				return new WP_Error( 'ovp_update_fs_not_direct', 'The filesystem method is not direct; a signed request cannot enter FTP credentials.' );
			}
			$rel = Ovp_Release::current( true );
			if ( empty( $rel['verified'] ) || ! is_array( $rel['index'] ) ) {
				return new WP_Error( 'ovp_update_index_unverified', 'This site has no verified release index.' );
			}
			$idx = $rel['index'];
			if ( ! hash_equals( (string) ( $idx['version'] ?? '' ), $want_v ) || ! hash_equals( strtolower( (string) ( $idx['sha256'] ?? '' ) ), $want_sha ) ) {
				return new WP_Error( 'ovp_update_index_mismatch', 'The requested release is not the latest signed release this site sees.' );
			}
			global $wp_version;
			$d = Ovp_Release::offer_decision( $idx, (string) OVP_FLEET_VERSION, PHP_VERSION, is_string( $wp_version ) ? $wp_version : '0' );
			if ( ! $d['ok'] ) {
				return new WP_Error( 'not_newer' === $d['reason'] ? 'ovp_update_not_newer' : 'ovp_update_requirements', 'Release refused: ' . $d['reason'] . '.' );
			}
			self::inject_offer( $idx );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$t0       = time();
			$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
			// bulk_upgrade(), never upgrade(): see the method comment.
			$results = $upgrader->bulk_upgrade( array( self::basename() ) );
			$res     = is_array( $results ) ? ( $results[ self::basename() ] ?? null ) : null;
			$st      = self::state();
			$settled = (int) ( $st['at'] ?? 0 ) >= $t0 && in_array( $st['state'] ?? '', array( 'ok', 'failed', 'rolled_back', 'rollback_failed', 'installed_inactive' ), true );
			if ( is_wp_error( $res ) && ! $settled ) {
				self::set_state( 'failed', array( 'from' => (string) OVP_FLEET_VERSION, 'to' => $want_v, 'reason' => $res->get_error_code() ) );
			} elseif ( ! $settled ) {
				self::set_state( 'failed', array( 'from' => (string) OVP_FLEET_VERSION, 'to' => $want_v, 'reason' => false === $results ? 'ovp_update_fs_unavailable' : 'ovp_update_no_result' ) );
			}
			return self::state();
		} finally {
			WP_Upgrader::release_lock( self::LOCK );
		}
	}

	/** Put the verified offer into WordPress's own update_plugins bookkeeping. */
	private static function inject_offer( array $idx ): void {
		$v    = (string) $idx['version'];
		$t    = get_site_transient( 'update_plugins' );
		$t    = is_object( $t ) ? $t : new stdClass();
		$resp = isset( $t->response ) && is_array( $t->response ) ? $t->response : array();
		$resp[ self::basename() ] = (object) array(
			'id'           => 'github.com/' . Ovp_Release::REPO,
			'slug'         => Ovp_Release::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $v,
			'url'          => 'https://github.com/' . Ovp_Release::REPO . '/releases/tag/v' . $v,
			'package'      => Ovp_Release::zip_url( $v ),
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
			'requires'     => (string) ( $idx['requires_wp'] ?? '' ),
			'requires_php' => (string) ( $idx['requires_php'] ?? '' ),
			'tested'       => (string) ( $idx['tested_wp'] ?? '' ),
		);
		$t->response = $resp;
		if ( isset( $t->no_update ) && is_array( $t->no_update ) ) {
			unset( $t->no_update[ self::basename() ] );
		}
		$t->last_checked = time();
		set_site_transient( 'update_plugins', $t );
	}

	/**
	 * Signed fleet/self-rollback: back to a copy this site itself ran before. Downloads nothing.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|WP_Error
	 */
	public static function remote_rollback( array $body ): array|WP_Error {
		if ( ! hash_equals( (string) OVP_FLEET_VERSION, (string) ( $body['from_version'] ?? '' ) ) ) {
			return new WP_Error( 'ovp_rollback_version_mismatch', 'from_version is not the running version.' );
		}
		self::load_upgrader_files();
		if ( ! WP_Upgrader::create_lock( self::LOCK, 900 ) ) {
			return new WP_Error( 'ovp_update_busy', 'Another update of this plugin is running.' );
		}
		try {
			$r = self::restore( 'remote' );
			if ( ! $r['ok'] ) {
				return new WP_Error( 'no_lower_backup' === $r['reason'] ? 'ovp_rollback_no_backup' : 'ovp_rollback_failed', 'Rollback not done (' . $r['reason'] . ').' );
			}
			$p = self::probe( $r['version'] );
			if ( ! $p['ok'] ) {
				self::set_state( 'rolled_back', array( 'reason' => 'remote; probe after restore failed: ' . $p['detail'] ) );
			}
			return self::state();
		} finally {
			WP_Upgrader::release_lock( self::LOCK );
		}
	}

	/**
	 * The self_update block of fleet/status (design 7.4).
	 *
	 * @return array<string,mixed>
	 */
	public static function status_block(): array {
		static $fs = null;
		if ( null === $fs ) {
			$f = ABSPATH . 'wp-admin/includes/file.php';
			if ( ! function_exists( 'get_filesystem_method' ) && is_file( $f ) ) {
				require_once $f;
			}
			$fs = self::fs_capability();
		}
		$st  = self::state();
		$bk  = self::latest_backup();
		$rel = class_exists( 'Ovp_Release' ) ? Ovp_Release::cached() : array();
		$idx = is_array( $rel['index'] ?? null ) ? $rel['index'] : array();
		return array(
			'capable'           => true,
			'state'             => (string) ( $st['state'] ?? 'idle' ),
			'from'              => (string) ( $st['from'] ?? '' ),
			'to'                => (string) ( $st['to'] ?? '' ),
			'at'                => (int) ( $st['at'] ?? 0 ),
			'reason'            => (string) ( $st['reason'] ?? '' ),
			'backup_version'    => null === $bk ? '' : $bk['version'],
			'auto_update'       => class_exists( 'Ovp_Updater' ) && Ovp_Updater::auto_enabled(),
			'file_mods_allowed' => $fs['file_mods'],
			'fs_direct'         => $fs['fs_direct'],
			'release'           => array(
				'version'    => (string) ( $idx['version'] ?? '' ),
				'verified'   => ! empty( $rel['verified'] ),
				'key_id'     => (string) ( $rel['key_id'] ?? '' ),
				'checked_at' => (int) ( $rel['checked_at'] ?? 0 ),
			),
		);
	}

	/**
	 * Settings button: wp-admin only (never a remote-signed request), nonce checked.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function admin_restore( string $nonce ): array {
		if ( Ovp_Proto::remote_seen() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return array( 'ok' => false, 'message' => 'Not allowed.' );
		}
		if ( ! function_exists( 'wp_verify_nonce' ) || false === wp_verify_nonce( $nonce, self::NONCE ) ) {
			return array( 'ok' => false, 'message' => 'Security check failed.' );
		}
		$r = self::restore( 'admin' );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'message' => 'no_lower_backup' === $r['reason'] ? 'No earlier version is available to restore.' : 'Restore failed (' . $r['reason'] . ').' );
		}
		$p = self::probe( $r['version'] );
		if ( ! $p['ok'] ) {
			self::set_state( 'rolled_back', array( 'reason' => 'admin; probe after restore failed: ' . $p['detail'] ) );
			return array( 'ok' => false, 'message' => 'Restored ' . $r['version'] . ' but the site check failed: ' . $p['detail'] );
		}
		return array( 'ok' => true, 'message' => 'Restored version ' . $r['version'] . '.' );
	}
}
