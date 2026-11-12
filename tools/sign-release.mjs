#!/usr/bin/env node
// Usage: node tools/sign-release.mjs --zip <zip> --src <plugin dir> --out <release-index.json>
// Env:   OVP_RELEASE_SIGNING_KEY (base64 PKCS8 DER), OVP_RELEASE_KEY_ID,
//        OVP_RELEASE_EXTRA_SIGNING_KEYS (optional JSON [{key_id, key_env_name}], dual-signing during rotation).
// The key is read from the environment only. It is never printed, never written to disk, and error output never includes it.
import { createHash, createPrivateKey } from "node:crypto";
import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { parseArgs, releaseMessage, signMessage, validateIndex, zipUrl, SLUG } from "./lib-release.mjs";

class Fail extends Error {}

function loadKey(b64, label) {
  try {
    const der = Buffer.from(String(b64).trim(), "base64");
    const k = createPrivateKey({ key: der, format: "der", type: "pkcs8" });
    if (k.asymmetricKeyType !== "ed25519") throw new Error("type");
    return k;
  } catch {
    throw new Fail(`${label} is not a valid base64 PKCS8 Ed25519 key`);
  }
}

function header(src, name) {
  const head = readFileSync(path.join(src, `${SLUG}.php`), "utf8").slice(0, 8192);
  const m = new RegExp(`^[ \\t/*#@]*${name}:[ \\t]*(.+?)[ \\t]*$`, "m").exec(head);
  return m ? m[1] : null;
}

function readmeInfo(src, version) {
  let txt = "";
  try {
    txt = readFileSync(path.join(src, "readme.txt"), "utf8");
  } catch {
    /* no readme */
  }
  const tested = /^Tested up to:[ \t]*(\S+)/m.exec(txt);
  let notes = "";
  const sec = /^== Changelog ==\s*$/m.exec(txt);
  if (sec) {
    const body = txt.slice(sec.index + sec[0].length);
    const re = new RegExp(`^=[ \\t]*${version.replace(/\./g, "\\.")}[ \\t]*=[ \\t]*$`, "m");
    const h = re.exec(body);
    if (h) {
      const rest = body.slice(h.index + h[0].length);
      const next = /^=[ \t]*\d+\.\d+\.\d+[ \t]*=[ \t]*$|^== /m.exec(rest);
      notes = (next ? rest.slice(0, next.index) : rest).trim();
    }
  }
  return { tested: tested ? tested[1] : null, notes };
}

try {
  const a = parseArgs(process.argv.slice(2));
  if (typeof a.zip !== "string" || typeof a.src !== "string" || typeof a.out !== "string") throw new Fail("usage: sign-release.mjs --zip <zip> --src <dir> --out <index.json>");
  const keyId = process.env.OVP_RELEASE_KEY_ID;
  if (!keyId || !/^[a-z0-9-]{3,32}$/.test(keyId)) throw new Fail("OVP_RELEASE_KEY_ID is missing or malformed");
  if (!process.env.OVP_RELEASE_SIGNING_KEY) throw new Fail("OVP_RELEASE_SIGNING_KEY is not set");
  const signers = [{ id: keyId, key: loadKey(process.env.OVP_RELEASE_SIGNING_KEY, "OVP_RELEASE_SIGNING_KEY") }];
  if (process.env.OVP_RELEASE_EXTRA_SIGNING_KEYS) {
    let extra;
    try {
      extra = JSON.parse(process.env.OVP_RELEASE_EXTRA_SIGNING_KEYS);
    } catch {
      throw new Fail("OVP_RELEASE_EXTRA_SIGNING_KEYS is not valid JSON");
    }
    if (!Array.isArray(extra) || extra.length > 3) throw new Fail("OVP_RELEASE_EXTRA_SIGNING_KEYS must be an array of at most 3 entries");
    for (const e of extra) {
      if (!e || typeof e.key_id !== "string" || !/^[a-z0-9-]{3,32}$/.test(e.key_id) || typeof e.key_env_name !== "string" || !/^[A-Z][A-Z0-9_]*$/.test(e.key_env_name)) throw new Fail("bad entry in OVP_RELEASE_EXTRA_SIGNING_KEYS");
      if (signers.some((s) => s.id === e.key_id)) throw new Fail(`duplicate key id ${e.key_id}`);
      if (!process.env[e.key_env_name]) throw new Fail(`environment variable ${e.key_env_name} is not set`);
      signers.push({ id: e.key_id, key: loadKey(process.env[e.key_env_name], e.key_env_name) });
    }
  }

  const src = path.resolve(a.src);
  const version = header(src, "Version");
  const requiresPhp = header(src, "Requires PHP");
  const requiresWp = header(src, "Requires at least");
  if (!version || !requiresPhp || !requiresWp) throw new Fail("plugin header lacks Version, Requires PHP or Requires at least");
  const zipBuf = readFileSync(path.resolve(a.zip));
  const info = readmeInfo(src, version);
  let commit;
  try {
    commit = execFileSync("git", ["rev-parse", "HEAD"], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim();
  } catch {
    commit = null;
  }
  const idx = {
    schema: 1,
    slug: SLUG,
    version,
    zip_url: zipUrl(version),
    sha256: createHash("sha256").update(zipBuf).digest("hex"),
    size: zipBuf.length,
    requires_php: requiresPhp,
    requires_wp: requiresWp,
    ...(info.tested ? { tested_wp: info.tested } : {}),
    released_at: new Date().toISOString().replace(/\.\d+Z$/, "Z"),
    ...(commit && /^[0-9a-f]{40}$/.test(commit) ? { source_commit: commit } : {}),
    changelog: (info.notes || `Release ${version}`).slice(0, 4000),
  };
  const msg = releaseMessage(idx);
  idx.signatures = signers.map((s) => ({ key_id: s.id, sig: signMessage(s.key, msg) }));
  const bad = validateIndex(idx);
  if (bad) throw new Fail(`generated index is invalid: ${bad}`);
  const body = `${JSON.stringify(idx, null, 2)}\n`;
  if (Buffer.byteLength(body) > 16384) throw new Fail("index larger than 16 KB");
  const out = path.resolve(a.out);
  mkdirSync(path.dirname(out), { recursive: true });
  writeFileSync(out, body);
  writeFileSync(path.join(path.dirname(out), "notes.md"), `${info.notes || `Release ${version}`}\n`);
  console.log(`signed ${SLUG} ${version} sha256=${idx.sha256} size=${idx.size} key_ids=${signers.map((s) => s.id).join(",")}`);
} catch (e) {
  console.error(`sign failed: ${e instanceof Fail ? e.message : "unexpected error"}`);
  process.exit(1);
}
