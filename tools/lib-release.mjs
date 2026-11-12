// Shared helpers of the release tools. Node built-ins only. Mirrors server/src/release-index.ts and
// Ovp_Release (PHP) in the private repo: same message bytes, same validation.
import { createPublicKey, sign, verify } from "node:crypto";
import { readFileSync } from "node:fs";
import path from "node:path";

export const SLUG = "overlord-wp-fleet";
export const REPO = "ovld-team/overlord-wp-fleet-releases";
const SPKI_ED25519_PREFIX = Buffer.from("302a300506032b6570032100", "hex");

export const zipUrl = (version) => `https://github.com/${REPO}/releases/download/v${version}/${SLUG}-${version}.zip`;

/** Exact signed bytes (docs/AUTO-UPDATE.md 3.2): lines joined with "\n", no trailing newline, ASCII. */
export function releaseMessage(idx) {
  const s = (v) => (v === undefined || v === null ? "" : String(v));
  return Buffer.from(["OVP2-RELEASE", SLUG, s(idx.version), s(idx.sha256), s(idx.size), s(idx.requires_php), s(idx.requires_wp)].join("\n"), "ascii");
}

const STD_B64 = /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/;
function b64(raw, len) {
  if (typeof raw !== "string" || !STD_B64.test(raw)) return null;
  const b = Buffer.from(raw, "base64");
  return b.length === len ? b : null;
}

/** Structural validation. Returns the problem string or null. Unknown fields are ignored. */
export function validateIndex(idx) {
  if (typeof idx !== "object" || idx === null || Array.isArray(idx)) return "index_not_object";
  const i = idx;
  if (i.schema !== 1) return "bad_schema";
  if (i.slug !== SLUG) return "bad_slug";
  if (typeof i.version !== "string" || !/^\d+\.\d+\.\d+$/.test(i.version)) return "bad_version";
  if (typeof i.sha256 !== "string" || !/^[0-9a-f]{64}$/.test(i.sha256)) return "bad_sha256";
  if (typeof i.size !== "number" || !Number.isInteger(i.size) || i.size < 1 || i.size > 67108864) return "bad_size";
  for (const f of ["requires_php", "requires_wp"]) {
    if (typeof i[f] !== "string" || !/^\d+(\.\d+){0,2}$/.test(i[f])) return `bad_${f}`;
  }
  if (i.zip_url !== zipUrl(i.version)) return "bad_zip_url";
  if (!Array.isArray(i.signatures) || i.signatures.length < 1 || i.signatures.length > 4) return "bad_signatures";
  for (const s of i.signatures) {
    if (typeof s !== "object" || s === null) return "bad_signatures";
    if (typeof s.key_id !== "string" || !/^[a-z0-9-]{3,32}$/.test(s.key_id)) return "bad_key_id";
    if (!b64(s.sig, 64)) return "bad_sig";
  }
  return null;
}

/** keysJson: {keys:{id:base64 raw pubkey}, revoked:[id]}. Returns {verified, keyId, error?}. */
export function verifyIndex(idx, keysJson) {
  const bad = validateIndex(idx);
  if (bad) return { verified: false, keyId: null, error: bad };
  const keys = (keysJson && keysJson.keys) || {};
  const revoked = Array.isArray(keysJson && keysJson.revoked) ? keysJson.revoked : [];
  if (Object.keys(keys).length === 0) return { verified: false, keyId: null, error: "no_pinned_keys" };
  const msg = releaseMessage(idx);
  for (const s of idx.signatures) {
    if (revoked.includes(s.key_id)) continue;
    if (!Object.prototype.hasOwnProperty.call(keys, s.key_id)) continue;
    const raw = b64(keys[s.key_id], 32);
    if (!raw) continue;
    try {
      const pub = createPublicKey({ key: Buffer.concat([SPKI_ED25519_PREFIX, raw]), format: "der", type: "spki" });
      if (verify(null, msg, pub, Buffer.from(s.sig, "base64"))) return { verified: true, keyId: s.key_id };
    } catch {
      /* try the next signature */
    }
  }
  return { verified: false, keyId: null, error: "no_valid_signature" };
}

/** Raw 32-byte public key, standard base64, from a KeyObject (private or public). */
export function rawPubFromKeyObject(keyObject) {
  const pub = keyObject.type === "private" ? createPublicKey(keyObject) : keyObject;
  const x = pub.export({ format: "jwk" }).x;
  return Buffer.from(x.replace(/-/g, "+").replace(/_/g, "/"), "base64").toString("base64");
}

/** Ed25519 signature over msg, standard base64. */
export function signMessage(privKeyObject, msg) {
  return sign(null, msg, privKeyObject).toString("base64");
}

/** Tiny argv parser: --name value pairs and bare --flags. */
export function parseArgs(argv) {
  const out = {};
  for (let k = 0; k < argv.length; k++) {
    const a = argv[k];
    if (!a.startsWith("--")) continue;
    const name = a.slice(2);
    const next = argv[k + 1];
    if (next !== undefined && !next.startsWith("--")) {
      out[name] = next;
      k++;
    } else out[name] = true;
  }
  return out;
}

/** Compare dotted numeric versions: <0, 0, >0. */
export function cmpVersion(a, b) {
  const x = a.split(".").map(Number);
  const y = b.split(".").map(Number);
  for (let k = 0; k < 3; k++) if ((x[k] || 0) !== (y[k] || 0)) return (x[k] || 0) - (y[k] || 0);
  return 0;
}

/**
 * Pinned release keys as the plugin source pins them: `Ovp_Release::RELEASE_KEYS` ('id' => 'base64 raw key') and
 * `REVOKED_KEY_IDS` in includes/class-ovp-release.php. Comments are ignored. Returns {keys, revoked} or throws.
 */
export function parseReleaseKeysFromPhp(php) {
  // Strip comments but keep string literals intact (base64 keys contain "/").
  const code = php.replace(/'(?:[^'\\]|\\.)*'|\/\*[\s\S]*?\*\/|\/\/[^\n]*|#[^\n]*/g, (m) => (m.startsWith("'") ? m : " "));
  const grab = (name) => {
    const m = new RegExp(`const\\s+${name}\\s*=\\s*array\\(([^;]*?)\\)\\s*;`, "s").exec(code);
    if (!m) throw new Error(`${name} not found in class-ovp-release.php`);
    return m[1];
  };
  const keys = {};
  for (const m of grab("RELEASE_KEYS").matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)) keys[m[1]] = m[2];
  const revoked = [...grab("REVOKED_KEY_IDS").matchAll(/'([^']+)'/g)].map((m) => m[1]);
  return { keys, revoked };
}

/** Read the pinned keys from a plugin source root (the folder that holds overlord-wp-fleet.php). */
export function readReleaseKeysFromSrc(srcDir) {
  return parseReleaseKeysFromPhp(readFileSync(path.join(srcDir, "includes", "class-ovp-release.php"), "utf8"));
}

/** Same keys, same ids, same revoked set (order ignored). */
export function sameKeyset(a, b) {
  const ka = Object.keys(a.keys || {}).sort();
  const kb = Object.keys(b.keys || {}).sort();
  if (ka.length !== kb.length || ka.some((k, i) => k !== kb[i] || a.keys[k] !== b.keys[k])) return false;
  const ra = [...(a.revoked || [])].sort();
  const rb = [...(b.revoked || [])].sort();
  return ra.length === rb.length && ra.every((v, i) => v === rb[i]);
}
