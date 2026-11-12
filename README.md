# Overlord WP Fleet releases

Signed, versioned releases of the **Overlord WP Fleet** WordPress plugin. Licensed GPL-2.0-or-later (see `LICENSE`).

The plugin source in `src/overlord-wp-fleet/` is mirrored from the private development repository by its release workflow. Do not send pull requests that edit `src/`; they would be overwritten. The tools, workflows, tests and `keys/` live here only.

## How a site verifies a release

Each plugin build pins the Ed25519 public keys it trusts (`Ovp_Release::RELEASE_KEYS`) and a revoked list. A site:

1. fetches `https://github.com/ovld-team/overlord-wp-fleet-releases/releases/latest/download/release-index.json` (HTTPS, official hosts only, at most 4 redirects, at most 16 KB);
2. validates the index and builds the zip URL itself (`.../releases/download/v{version}/overlord-wp-fleet-{version}.zip`); an index cannot point anywhere else;
3. verifies that at least one signature comes from a pinned, non-revoked key over this message (lines joined by `\n`, no trailing newline, ASCII):

```
OVP2-RELEASE
overlord-wp-fleet
<version>
<sha256 lowercase hex>
<size decimal>
<requires_php>
<requires_wp>
```

4. downloads the zip and checks its size and sha256 against the index;
5. refuses downgrades, same-version installs, unmet PHP or WordPress minimums, a wrong folder name or a main file whose `Version:` differs.

`tested_wp`, `released_at`, `changelog` and `source_commit` are informational and not signed.

## Verify a release by hand

```bash
gh release download v2.0.7 -R ovld-team/overlord-wp-fleet-releases -D /tmp/ovp-release
node tools/verify-release.mjs \
  --index /tmp/ovp-release/release-index.json \
  --zip   /tmp/ovp-release/overlord-wp-fleet-2.0.7.zip \
  --src   src/overlord-wp-fleet \
  --keys  keys/release-keys.json
```

The keys are read from the plugin source (`RELEASE_KEYS` and `REVOKED_KEY_IDS` in `includes/class-ovp-release.php`); `--keys` is optional and, when given, must equal them. Exit 0 means the index is valid, a pinned key signed it, and the zip matches size and sha256. Exit 1 means it did not. The tool needs only Node 22 or newer.

## `release-index.json`

Schema: `release-index.schema.json`.

```json
{
  "schema": 1,
  "slug": "overlord-wp-fleet",
  "version": "2.0.7",
  "zip_url": "https://github.com/ovld-team/overlord-wp-fleet-releases/releases/download/v2.0.7/overlord-wp-fleet-2.0.7.zip",
  "sha256": "<64 lowercase hex>",
  "size": 812345,
  "requires_php": "8.3",
  "requires_wp": "6.0",
  "tested_wp": "6.8",
  "released_at": "2026-11-20T10:00:00Z",
  "source_commit": "<40 hex>",
  "changelog": "Plain text, at most 4000 characters.",
  "signatures": [ { "key_id": "ovp-rel-2026a", "sig": "<base64 of 64 bytes>" } ]
}
```

## How releases are produced

1. A human tags the private repository. Its workflow runs the checks, then pushes the exact shipped files to `src/`, plus `tools/plugin-zip.mjs` and `SOURCE.json` (version, private commit, expected sha256, size), to `main` of this repository.
2. The same workflow pushes the tag `vX.Y.Z` here.
3. `.github/workflows/release.yml` runs: preflight, `php -l`, an independent zip build whose sha256 must equal `SOURCE.json`, signing, verification, and `gh release create`.

No secret is in code. The signing key exists only as the `OVP_RELEASE_SIGNING_KEY` secret of the protected `release` environment (base64 PKCS#8 DER). The key id is the variable `OVP_RELEASE_KEY_ID`. The tools never print the key.

## Keys and rotation

`keys/release-keys.json` (`{"keys":{"<id>":"<base64 raw public key>"},"revoked":[]}`) **must equal the list pinned in the plugin** (`Ovp_Release::RELEASE_KEYS` and `REVOKED_KEY_IDS`). It is empty until the first key is created.

Generate a key offline, outside any git work tree:

```bash
node tools/gen-release-key.mjs --out ~/offline/ovp-rel-2026a.key --key-id ovp-rel-2026a
```

It prints the key id, the public key, the line for `keys/release-keys.json` and the next steps (store the secret, then move the file to an offline backup and delete it).

Planned rotation: release N pins `{old, new}` and is signed by `old`. Later releases carry both signatures (`OVP_RELEASE_EXTRA_SIGNING_KEYS`) while sites below N may exist. Then a release drops `old`. If a key leaks: publish a release signed by the next key that adds the leaked id to the revoked list, replace the secret, and roll out with Fleet. See `docs/AUTO-UPDATE.md` section 4 in the private repository.

## Security

Report problems privately to: `security@TODO-owner-to-fill.example` (placeholder, not yet set).

## Bootstrap: sites on 2.0.5 or 2.0.6

Those versions cannot self-update. Replace them once in place: Plugins > Add New > Upload Plugin > "Replace current with uploaded". Never delete the plugin first. From 2.0.7 on, updates arrive through the normal WordPress Plugins screen or Fleet.

## Tests

```bash
node --test tests/*.test.mjs
```
