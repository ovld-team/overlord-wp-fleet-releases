import { test } from "node:test";
import assert from "node:assert/strict";
import { generateKeyPairSync } from "node:crypto";
import { execFileSync, spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parseReleaseKeysFromPhp, rawPubFromKeyObject, releaseMessage, validateIndex, verifyIndex, zipUrl } from "../tools/lib-release.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tool = (n) => path.join(ROOT, "tools", n);
const tmp = () => mkdtempSync(path.join(tmpdir(), "ovp-rel-"));
const b64Priv = (k) => k.export({ format: "der", type: "pkcs8" }).toString("base64");
const run = (script, args, env = {}) => spawnSync("node", [tool(script), ...args], { encoding: "utf8", env: { PATH: process.env.PATH, ...env } });

function fixture(version = "9.9.9") {
  const d = tmp();
  const src = path.join(d, "src", "overlord-wp-fleet");
  mkdirSync(path.join(src, "includes"), { recursive: true });
  writeFileSync(path.join(src, "overlord-wp-fleet.php"), `<?php\n/**\n * Plugin Name: Overlord WP Fleet\n * Version:           ${version}\n * Requires at least: 6.0\n * Requires PHP:      8.3\n */\n`);
  writeFileSync(path.join(src, "includes", "a.php"), "<?php\n// a\n");
  writeFileSync(path.join(src, "readme.txt"), `=== Overlord WP Fleet ===\nTested up to: 6.8\nStable tag: ${version}\n\n== Changelog ==\n\n= ${version} =\n* Fixture change.\n\n= 1.0.0 =\n* Old.\n`);
  return { d, src };
}

/** Write includes/class-ovp-release.php into a fixture source with the given pinned keys (the shape the real file has). */
function pinKeys(src, keys) {
  const pairs = Object.entries(keys.keys).map(([id, k]) => `\t\t'${id}' => '${k}',`).join("\n");
  const rev = (keys.revoked || []).map((id) => `'${id}'`).join(", ");
  writeFileSync(
    path.join(src, "includes", "class-ovp-release.php"),
    `<?php\nclass Ovp_Release {\n\t// RELEASE_KEYS = array( 'decoy' => 'x' );\n\tpublic const RELEASE_KEYS = array(\n${pairs}\n\t);\n\t/** Key ids that no longer count. */\n\tpublic const REVOKED_KEY_IDS = array( ${rev} );\n}\n`
  );
}

function signedFixture(extra = false) {
  const f = fixture();
  const k1 = generateKeyPairSync("ed25519").privateKey;
  const k2 = generateKeyPairSync("ed25519").privateKey;
  const out = path.join(f.d, "dist");
  const b = run("build-zip.mjs", ["--src", f.src, "--out", out]);
  assert.equal(b.status, 0, b.stderr);
  const zip = path.join(out, "overlord-wp-fleet-9.9.9.zip");
  const env = { OVP_RELEASE_SIGNING_KEY: b64Priv(k1), OVP_RELEASE_KEY_ID: "test-key-a" };
  if (extra) {
    env.OVP_RELEASE_EXTRA_SIGNING_KEYS = JSON.stringify([{ key_id: "test-key-b", key_env_name: "SECOND_KEY" }]);
    env.SECOND_KEY = b64Priv(k2);
  }
  const index = path.join(out, "release-index.json");
  const s = run("sign-release.mjs", ["--zip", zip, "--src", f.src, "--out", index], env);
  assert.equal(s.status, 0, s.stderr);
  const keys = { keys: { "test-key-a": rawPubFromKeyObject(k1), "test-key-b": rawPubFromKeyObject(k2) }, revoked: [] };
  const keysPath = path.join(f.d, "keys.json");
  writeFileSync(keysPath, JSON.stringify(keys));
  pinKeys(f.src, keys);
  const verify = () => run("verify-release.mjs", ["--index", index, "--zip", zip, "--src", f.src, "--keys", keysPath]);
  const verifySrcOnly = () => run("verify-release.mjs", ["--index", index, "--zip", zip, "--src", f.src]);
  return { ...f, verifySrcOnly, keysPath2: keysPath, k1, k2, out, zip, index, keys, keysPath, verify, env, s };
}

test("round trip: sign then verify exits 0, notes and header fields", () => {
  const t = signedFixture();
  assert.equal(t.verify().status, 0);
  const idx = JSON.parse(readFileSync(t.index, "utf8"));
  assert.equal(idx.requires_php, "8.3");
  assert.equal(idx.requires_wp, "6.0");
  assert.equal(idx.tested_wp, "6.8");
  assert.equal(idx.version, "9.9.9");
  assert.equal(idx.zip_url, zipUrl("9.9.9"));
  assert.equal(validateIndex(idx), null);
  const notes = readFileSync(path.join(t.out, "notes.md"), "utf8");
  assert.match(notes, /Fixture change/);
  assert.doesNotMatch(notes, /Old\./);
});

test("sign output never contains the key", () => {
  const t = signedFixture();
  assert.ok(!t.s.stdout.includes(t.env.OVP_RELEASE_SIGNING_KEY) && !t.s.stderr.includes(t.env.OVP_RELEASE_SIGNING_KEY));
  assert.ok(!readFileSync(t.index, "utf8").includes(t.env.OVP_RELEASE_SIGNING_KEY));
  const bad = run("sign-release.mjs", ["--zip", t.zip, "--src", t.src, "--out", t.index], { OVP_RELEASE_SIGNING_KEY: "not-a-key-SECRETVALUE", OVP_RELEASE_KEY_ID: "test-key-a" });
  assert.equal(bad.status, 1);
  assert.ok(!bad.stderr.includes("SECRETVALUE"));
});

test("sign refuses without env", () => {
  const t = signedFixture();
  assert.equal(run("sign-release.mjs", ["--zip", t.zip, "--src", t.src, "--out", t.index], {}).status, 1);
});

test("message bytes match the design", () => {
  const m = releaseMessage({ version: "2.0.7", sha256: "a".repeat(64), size: 10, requires_php: "8.3", requires_wp: "6.0" });
  assert.equal(m.toString("ascii"), `OVP2-RELEASE\noverlord-wp-fleet\n2.0.7\n${"a".repeat(64)}\n10\n8.3\n6.0`);
});

test("zipUrl", () => {
  assert.equal(zipUrl("2.0.7"), "https://github.com/ovld-team/overlord-wp-fleet-releases/releases/download/v2.0.7/overlord-wp-fleet-2.0.7.zip");
});

test("tamper cases all fail", () => {
  const t = signedFixture();
  const orig = JSON.parse(readFileSync(t.index, "utf8"));
  const cases = {
    version: (i) => (i.version = "9.9.8"),
    sha256: (i) => (i.sha256 = "0".repeat(64)),
    size: (i) => (i.size += 1),
    requires_php: (i) => (i.requires_php = "7.0"),
    requires_wp: (i) => (i.requires_wp = "5.0"),
    zip_url: (i) => (i.zip_url = "https://evil.example/x.zip"),
    sig: (i) => (i.signatures[0].sig = Buffer.alloc(64, 1).toString("base64")),
    key_id: (i) => (i.signatures[0].key_id = "unknown-key"),
    no_sigs: (i) => (i.signatures = []),
    slug: (i) => (i.slug = "other"),
  };
  for (const [name, mut] of Object.entries(cases)) {
    const i = structuredClone(orig);
    mut(i);
    writeFileSync(t.index, JSON.stringify(i));
    assert.equal(t.verify().status, 1, `case ${name} must fail`);
  }
  writeFileSync(t.index, JSON.stringify(orig));
  assert.equal(t.verify().status, 0);
  // zip tampering
  const z = readFileSync(t.zip);
  z[40] ^= 1;
  writeFileSync(t.zip, z);
  assert.equal(t.verify().status, 1, "tampered zip");
  writeFileSync(t.zip, Buffer.concat([z, Buffer.from("x")]));
  assert.equal(t.verify().status, 1, "size changed");
});

test("revoked key and empty key list fail", () => {
  const t = signedFixture();
  const idx = JSON.parse(readFileSync(t.index, "utf8"));
  assert.equal(verifyIndex(idx, t.keys).verified, true);
  assert.equal(verifyIndex(idx, { ...t.keys, revoked: ["test-key-a"] }).verified, false);
  assert.equal(verifyIndex(idx, { keys: {}, revoked: [] }).error, "no_pinned_keys");
  writeFileSync(t.keysPath, JSON.stringify({ ...t.keys, revoked: ["test-key-a"] }));
  assert.equal(t.verify().status, 1, "keys file differs from the source pins");
  pinKeys(t.src, { ...t.keys, revoked: ["test-key-a"] });
  assert.equal(t.verify().status, 1, "revoked in both");
  assert.equal(t.verifySrcOnly().status, 1, "revoked in the source");
});

test("verify uses the keys pinned in src, not a separate keys file", () => {
  const t = signedFixture();
  assert.equal(t.verifySrcOnly().status, 0);
  // A keys file that trusts the signing key does not help when the source pins another key.
  pinKeys(t.src, { keys: { "test-key-a": rawPubFromKeyObject(generateKeyPairSync("ed25519").privateKey) }, revoked: [] });
  assert.equal(t.verifySrcOnly().status, 1, "wrong key pinned in src");
  assert.equal(t.verify().status, 1, "keys file differs from src");
  pinKeys(t.src, { keys: {}, revoked: [] });
  assert.equal(t.verifySrcOnly().status, 1, "no key pinned in src");
  rmSync(path.join(t.src, "includes", "class-ovp-release.php"));
  assert.equal(t.verifySrcOnly().status, 1, "no class-ovp-release.php");
});

test("parseReleaseKeysFromPhp ignores comments and keeps base64 slashes", () => {
  const php = "<?php\n// const RELEASE_KEYS = array('c' => 'd');\nclass X {\n public const RELEASE_KEYS = array( /* x */ 'k1' => 'ab//cd+/ef==', # y\n 'k2' => 'q' );\n public const REVOKED_KEY_IDS = array( 'k2' );\n}\n";
  assert.deepEqual(parseReleaseKeysFromPhp(php), { keys: { k1: "ab//cd+/ef==", k2: "q" }, revoked: ["k2"] });
  assert.throws(() => parseReleaseKeysFromPhp("<?php\n"));
});

test("dual signature: either key verifies, revoking one still passes via the other", () => {
  const t = signedFixture(true);
  const idx = JSON.parse(readFileSync(t.index, "utf8"));
  assert.equal(idx.signatures.length, 2);
  assert.deepEqual(idx.signatures.map((s) => s.key_id), ["test-key-a", "test-key-b"]);
  assert.equal(verifyIndex(idx, t.keys).verified, true);
  const onlyB = verifyIndex(idx, { keys: { "test-key-b": t.keys.keys["test-key-b"] }, revoked: [] });
  assert.equal(onlyB.verified, true);
  assert.equal(onlyB.keyId, "test-key-b");
  assert.equal(verifyIndex(idx, { ...t.keys, revoked: ["test-key-a"] }).keyId, "test-key-b");
  assert.equal(verifyIndex(idx, { ...t.keys, revoked: ["test-key-a", "test-key-b"] }).verified, false);
  assert.equal(t.verify().status, 0);
});

test("build determinism", () => {
  const f = fixture();
  const o1 = path.join(f.d, "o1");
  const o2 = path.join(f.d, "o2");
  const a = run("build-zip.mjs", ["--src", f.src, "--out", o1]);
  const b = run("build-zip.mjs", ["--src", f.src, "--out", o2]);
  assert.equal(a.status, 0, a.stderr);
  const sha = (s) => /sha256: ([0-9a-f]{64})/.exec(s)[1];
  assert.equal(sha(a.stdout), sha(b.stdout));
  assert.match(a.stdout, /filename: overlord-wp-fleet-9\.9\.9\.zip/);
});

test("preflight --offline", () => {
  const f = fixture();
  const root = f.d;
  const pubA = rawPubFromKeyObject(generateKeyPairSync("ed25519").privateKey);
  const good = { keys: { "test-key-a": pubA }, revoked: [] };
  writeFileSync(path.join(root, "SOURCE.json"), JSON.stringify({ version: "9.9.9", expected_sha256: null, size: null }));
  mkdirSync(path.join(root, "keys"));
  const kp = path.join(root, "keys", "release-keys.json");
  writeFileSync(kp, JSON.stringify(good));
  pinKeys(f.src, good);
  const pf = (tag, keyId = "test-key-a") => run("preflight.mjs", ["--tag", tag, "--root", root, "--offline"], keyId === null ? {} : { OVP_RELEASE_KEY_ID: keyId });
  assert.equal(pf("v9.9.9").status, 0);
  assert.equal(pf("v9.9.8").status, 1, "tag differs from SOURCE.json");
  assert.equal(pf("9.9.9").status, 1, "tag format");
  writeFileSync(path.join(root, "SOURCE.json"), JSON.stringify({ version: "9.9.8" }));
  assert.equal(pf("v9.9.9").status, 1);
  writeFileSync(path.join(root, "SOURCE.json"), JSON.stringify({ version: "9.9.9" }));
  // keys file empty
  writeFileSync(kp, JSON.stringify({ keys: {}, revoked: [] }));
  assert.equal(pf("v9.9.9").status, 1, "empty keys file");
  // keys file non-empty but the shipped source pins nothing
  writeFileSync(kp, JSON.stringify(good));
  pinKeys(f.src, { keys: {}, revoked: [] });
  assert.equal(pf("v9.9.9").status, 1, "source pins no key");
  // source pins a different key than the keys file
  pinKeys(f.src, { keys: { "test-key-a": rawPubFromKeyObject(generateKeyPairSync("ed25519").privateKey) }, revoked: [] });
  assert.equal(pf("v9.9.9").status, 1, "keys file differs from source");
  // keys file with an extra key
  pinKeys(f.src, good);
  writeFileSync(kp, JSON.stringify({ keys: { ...good.keys, "test-key-b": pubA }, revoked: [] }));
  assert.equal(pf("v9.9.9").status, 1, "extra key in the keys file only");
  writeFileSync(kp, JSON.stringify(good));
  assert.equal(pf("v9.9.9").status, 0);
  // signing key id must be set and pinned
  assert.equal(pf("v9.9.9", null).status, 1, "OVP_RELEASE_KEY_ID unset");
  assert.equal(pf("v9.9.9", "other-key").status, 1, "OVP_RELEASE_KEY_ID not pinned");
  // revoked signing key
  pinKeys(f.src, { ...good, revoked: ["test-key-a"] });
  writeFileSync(kp, JSON.stringify({ ...good, revoked: ["test-key-a"] }));
  assert.equal(pf("v9.9.9").status, 1, "signing key revoked");
  // missing source file
  rmSync(path.join(f.src, "includes", "class-ovp-release.php"));
  writeFileSync(kp, JSON.stringify(good));
  assert.equal(pf("v9.9.9").status, 1, "class-ovp-release.php missing");
  pinKeys(f.src, good);
  writeFileSync(path.join(f.src, "overlord-wp-fleet.php"), "<?php\n/**\n * Version: 9.9.0\n */\n");
  assert.equal(pf("v9.9.9").status, 1, "header differs");
});

test("repo keys file is well formed", () => {
  const k = JSON.parse(readFileSync(path.join(ROOT, "keys/release-keys.json"), "utf8"));
  assert.equal(typeof k.keys, "object");
  assert.ok(Array.isArray(k.revoked));
});

test("git is available for helper checks", () => {
  assert.ok(execFileSync("git", ["--version"], { encoding: "utf8" }).startsWith("git version"));
});
