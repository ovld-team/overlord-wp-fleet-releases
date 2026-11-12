=== Overlord WP Fleet ===
Contributors: overlordteam
Tags: rest-api, automation, ai, agent, management
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 2.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Signed, tiered remote management for WordPress. Pair the site with an Overlord WP Fleet master (or a local agent) using a one-time code.

== Security model (read before pairing) ==

* Every request to this site is signed with Ed25519 by the master you paired with, carries a timestamp and a one-time nonce, and is rejected if the signature, the clock window (60 seconds) or the nonce is wrong. Replays are rejected. Responses are signed too.
* There is no shared token, no password and no IP allowlist. A leaked database cannot sign requests: the site's private key is encrypted with a key derived from wp-config.php salts. The plugin refuses to create keys when the salts are missing or default.
* Pairing needs a one-time code from the Fleet panel, plus a master key fingerprint you paste with it. You see exactly what is sent (a consent screen) before it goes out. Re-pairing a paired site is refused: you must Unpair here AND revoke it in the master.
* A tier cap that you control limits what the master can do, whatever it asks for. You approve it once, on the pairing consent screen, which states the tier being granted; you can lower it, open or close a timed god window, or unpair in Settings > Overlord WP Fleet at any time:
  * T0 read only
  * T1 safe edits (content, media, SEO fields). Every change is a two-step confirm: the first call only returns a plan and a short-lived token, the second call with that token applies it.
  * T2 structural: deactivate or delete plugins and themes (never the legacy wp-mcp-bridge plugin, see Uninstall), users, options, search-replace, cron, cache purge
  * T3 full control, no limit: ZIP uploads and plugin or theme install and activate (they run uploaded PHP), raw SQL, read, write and delete any file (including PHP, mu-plugins and this plugin's own folder), replace or delete this plugin, administrator login links. The one thing T3 can still not do is delete or change the `mcp_bridge_service` account, because WordPress deletes everything a user authored when that user is deleted.
* After pairing the cap equals the tier you approved on the consent screen (a pairing without a tier gives T1). There is an optional timed god window (1 to 1440 minutes). The master may lower the cap remotely, and raise it back only up to the tier you approved, never above it; lowering it in wp-admin also stops the master from raising it back. Automatic updates are a separate setting that only wp-admin can change.
* Nothing is sent anywhere until you pair. After pairing the plugin sends a signed heartbeat (site facts and the plugin and theme list) every 6 hours to the master or relay you configured, and nothing else.
* Automatic updates and Fleet rollouts come only from the signed release index on GitHub: such a package is installed only if its Ed25519 signature verifies against a key pinned in the plugin, and it must be newer than the installed version. The one exception is effective tier T3: the master can also replace the plugin with any ZIP (no signature check; version 2.0.6 or newer, no uninstall.php that deletes users or posts, and only when the human asked for it). Since 2.0.8 this plugin (and only this plugin) updates itself automatically; an admin can turn that off in wp-admin, and nothing can change that setting remotely. "Check now" on the settings page only reads the signed index.

The plugin keeps a hidden administrator account named `mcp_bridge_service` that verified, tier-authorised requests run as for the duration of that one request, so WordPress capability checks behave like a logged-in administrator.

== Description ==

Connection modes: local (an agent on your own machine), remote (the master calls your site and your site calls the master), direct, and proxy (your site reaches the master only through a relay you host).

REST namespace: `/wp-json/ovp/v1/`. Every route verifies the signed envelope and the tier.

== Installation ==

1. Upload and activate the plugin.
2. Settings > Overlord WP Fleet: choose the mode and enter the master (or relay) URL, save.
3. In the Fleet panel, add the site and copy the pairing string. Paste it into the plugin, review, approve.
4. Check the tier on the consent screen before you approve; it is the cap from then on. Lower it here whenever you want.

== Upgrading from 1.x (Overlord Team Management interface) ==

Plugin folder and slug changed (`overlord-wp-fleet`), so WordPress treats this as a new plugin. Both plugins can be active at the same time (every class and constant here is `Ovp_*` / `OVP_*`, the old ones are `MCP_Bridge_*` / `WP_MCP_BRIDGE_*`), so a site can stay controlled by the old bridge while it is migrated. While the old plugin is active this plugin leaves its token, hop and enroll options and its drop-in files alone.

Manual migration: activate this plugin, pair it in Settings > Overlord WP Fleet, then deactivate and delete the old one. If the old plugin is not active, its bearer token, hop token and enroll state are deleted on first load, the old out-of-band hash files are removed, the old `mcp-bridge/v1` API answers 410, and the settings page asks you to pair. The emergency out-of-band endpoint of 1.x is disabled in 2.0.

Unattended migration (autopair): a master that already manages the site installs this plugin and stores ONE option, `ovp_autopair`, as a JSON string or array `{code, fp, master_url, expires, mode?, relay_url?, relay_token?}`. The pairing string parts are the usual one-time code and master fingerprint; `expires` is a Unix time at most 15 minutes ahead. On plugin activation, and on wp-admin requests at most once a minute, the option is consumed: it is deleted first (so it is one-shot, whatever happens), then the normal pairing runs with consent implied. Writing that option already needs administrator-level access to the site, so the consent screen is skipped; the fingerprint pin, proof of possession and master signature checks are unchanged, and a paired site is never re-bound. The code is never stored or logged; the outcome (without secrets) shows in the settings page status panel. Pairing never touches the old plugin: it keeps serving the site until the master retires it with the signed `fleet/legacy-retire` route (T3), which deactivates it, wipes its token, hop and enroll options, and deletes its files only when asked (never this plugin's own directory).

PHP: this plugin needs PHP 8.3. On an older PHP it loads nothing and shows an admin notice instead of crashing the site.

== Uninstall ==

Deleting the plugin removes only what it created: its own `ovp_*` options, its replay table, its scheduled events and, once the old 1.x plugin (wp-mcp-bridge) is no longer active, the 1.x credentials (`mcp_bridge_*` options) and the fatal-error drop-ins and state files that carry this plugin's marker. It never deletes, trashes or reassigns a post, page, attachment, menu, term or user. The `mcp_bridge_service` account and everything it authored stay; remove the account by hand in Users if you want to (WordPress then asks where its content goes). Deactivating the plugin only stops its scheduled events.

== Upgrade Notice ==

= 2.0.8 =
2.0.8: the plugin now updates itself automatically; turn it off in Settings > Overlord WP Fleet. Only this plugin is affected, and only signed newer releases are installed. Sites that never explicitly chose are switched on.

= 2.0.7 =
A site that was paired by 2.0.6 and has remote raising switched on but no stored ceiling is no longer allowed to be raised back to T3 by the master on its own: its ceiling is now its current cap. To allow T3 again, re-pair the site or set the tier in wp-admin (Settings > Overlord WP Fleet).

== Changelog ==

= 2.0.8 =
* Changed: this plugin, and only this plugin, now updates itself automatically by default, through WordPress's own unattended update path. Nothing else is automatic: WordPress core, other plugins, themes, translations and content are untouched, and the `auto_update_plugin` filter returns every other plugin's decision unchanged.
* Safety is unchanged: only a newer release with a valid Ed25519 signature from a pinned key, sha256 and size checks, host allowlist, PHP and WordPress requirement checks, folder and main-file checks, previous version kept outside the plugins directory, loopback probe and automatic rollback. An unsigned, tampered, older or otherwise unverifiable offer installs nothing.
* A site admin can turn it off (the button in Settings > Overlord WP Fleet, or the auto-updates controls on the Plugins screen) and the choice is respected until an admin turns it on again. Migration: only an explicit admin choice (stored with `set_by: human`) counts; a value written by 2.0.7 or no value means "never chose", so the default (on) applies.
* Fleet remote rollout tools are unchanged and remain a separate, human-requested path.

= 2.0.7 =
* Changed (upgrade): a 2.0.6 tier state with remote raising on and no stored ceiling now has its ceiling set to the current cap instead of the build maximum; a re-pair or a wp-admin set is needed to reach T3 again. `Ovp_Tiers::remote_set_cap()` no longer has a default signed tier.
* Changed (tiers): consent happens once, at pairing. The pairing string carries the tier (`code.fingerprint.t3`), the consent screen states it, the master repeats it under its Ed25519 signature, and the plugin cap becomes that tier. Nobody has to visit wp-admin afterwards to raise it. A wp-admin lower, a timed god window and Unpair work as before; the master can lower the cap and raise it back up to the granted tier only.
* Changed (tiers): T3 has no self-protection. At effective T3 the plugin's own folder and mu-plugins can be read and written, the plugin can be replaced in place (zip folder exactly `overlord-wp-fleet`; previous version kept outside the plugins directory, rolled back by itself if the new copy does not answer), deactivated and deleted (the folder is removed directly; `uninstall.php` and `delete_plugins()` are never run). Below T3 every guard stays. The `mcp_bridge_service` account stays protected at every tier.
* Signed self-update. Releases are published as a signed index on GitHub; the plugin checks the Ed25519 signature against keys pinned in the code and offers the update through the normal WordPress Plugins screen (Update URI). This signed path is for automatic updates and Fleet rollouts; the T3 in-place replace with an arbitrary ZIP does not check a signature.
* (Superseded in 2.0.8: automatic updates of this plugin are now on by default and an admin can turn them off.) In 2.0.7 they were an opt-in in wp-admin.
* Fleet can ask for the latest signed release or a rollback (signed, tier 3). It cannot supply a URL or a file; a rollout installs only the release the site verified itself, with the native WordPress upgrader. (Separately, at effective T3 the master can replace the plugin with any ZIP: no signature check, version floor 2.0.6, human-requested.)
* Every update keeps the previous version aside, probes the site afterwards and rolls back by itself if the new copy does not answer. A "Restore previous version" button is on the settings page.
* Updating, replacing or rolling back never deletes or changes content.
* Bootstrap: sites on 2.0.5 or 2.0.6 cannot self-update yet. Replace in place with Plugins > Add New > Upload Plugin > "Replace current with uploaded". Never delete the plugin first.

= 2.0.6 =
* Fixed (data loss): `uninstall.php` ended with `wp_delete_user( mcp_bridge_service )` without reassigning its content, so deleting the plugin (also when a migration replaced an older copy) deleted every page and attachment created through the bridge. Uninstall now never deletes, trashes or reassigns posts, pages, attachments, menus, terms or users, and touches no table except `wp_options` (own prefixes only) and its own replay table. While the legacy 1.x plugin is still active, its credentials, drop-ins and state files are left in place. Regression suite `tests/uninstall`.
* Changed: `plugins/delete` refuses the legacy wp-mcp-bridge plugin (also a renamed folder, by text domain) with `ovp_legacy_delete_unsafe` (409): its own uninstall.php deletes the service user and all content it authored. Its files are removed only by `fleet/legacy-retire` with `delete_files`, which does not run uninstall.php. A renamed copy of this plugin is refused like the plugin itself.

= 2.0.5 =
* Fixed: on hosts without the PHP sodium extension (WordPress falls back to the sodium_compat polyfill) pairing failed with `pair_error` and signing would have failed on every request: `sodium_memzero()` throws `SodiumException` in the polyfill. Key wiping is now best-effort (`Ovp_Keys::zero`). Found live on a Hostinger site (PHP 8.5.12, no ext-sodium).

= 2.0.4 =
* Changed: the secret-free autopair outcome is also stored as the option `ovp_autopair_result` (the transient alone is lost behind a persistent object cache, which left a failed pairing unexplained). `scripts/migrate-local.mjs` reads it.

= 2.0.3 =
* Fixed: `dropins/000-mcp-bridge-fatal.php` carried a `Plugin Name:` header. WordPress `get_plugins()` scans one folder level deep and `Plugin_Upgrader::plugin_info()` returns the first plugin sorted by Name, so the legacy bridge's `plugins/install` reported the drop-in ("Overlord MCP fatal probe" sorts before "Overlord WP Fleet") as the plugin file and activating it failed with "The plugin does not have a valid header". The drop-in no longer has a plugin header (the marker `MCP_BRIDGE_FATAL_DROPIN` is unchanged).

= 2.0.2 =
* Fixed: on a real WordPress every master-to-site response went out unsigned (Fleet reported `unverified_response`, inventory stayed empty). WordPress core re-runs the route's permission callbacks after dispatch (`rest_send_allow_header`, to build the `Allow` header); the second `authorize()` call was rejected as a nonce replay and cleared the verified state. `authorize()` is now idempotent per request: the byte-identical request (method, path, query, body, OVP headers) already verified in the same PHP request reuses the first verdict without touching the replay store, the tier gate is evaluated again for each caller, and the idempotency window closes once the signed response has been sent. A replay from another PHP request is still rejected.
* Tests: new `tests/reentry` suite drives the dispatch, post-dispatch filter and `rest_pre_serve_request` path including the second `authorize()` call.

= 2.0.1 =
* Fixed: autopair no longer deactivates the legacy 1.x plugin or wipes its credentials when it pairs (a live production connection was being cut). The old bridge keeps working until the operator finalizes the migration.
* New: signed T3 route `POST fleet/legacy-retire` ({cmd:legacy_retire, site_id, delete_files}) that deactivates the legacy plugin, wipes its mcp_bridge options, and deletes its files only when `delete_files` is true (realpath-contained in the plugins directory, never this plugin's directory). Idempotent. Answers {retired, deactivated, options_wiped, files_deleted}.

= 2.0.0 =
* New product: Overlord WP Fleet. Ed25519 signed requests and responses, replay table, tier cap with T1 two-call confirm, one-time-code pairing with consent screen, signed heartbeat with plugin/theme inventory, key rotation and signed revoke.
* Removed: bearer token, hop header, IP allowlist, shared enroll token, phone-home enrollment, the self-updater that fetched code from the old proxy, remote crash reporting, legacy `mcp-bridge/v1` routes, the 1.x out-of-band endpoint.
* Requires PHP 8.3 and ext-sodium (every PHP file declares strict_types and carries a GPL-2.0-or-later license header).
* Master-facing routes completed: `upload/begin|chunk|finalize|abort` (T3 for zips and files; abort is T2), `rotate-keys` (T2, plugin-initiated key rotation with a verified master ack), `inventory?kind=plugins|themes`.
* The T1 confirm token is accepted from the `X-OVP-Confirm` header, the JSON body field `confirm_token`, or the `confirm_token` query parameter.
* Reading files through the API now needs T2 (wp-config stays masked). Reading users is T0 with email addresses masked below T2. Installing the fatal-error drop-in (`debug/fatals`), `media/push`, media regeneration and the clone plan are tiered to match the master.
* Crash recovery of interrupted plugin activations no longer runs on every REST request: it runs on activation, from wp-admin (at most every 5 minutes) and in the daily maintenance event.
