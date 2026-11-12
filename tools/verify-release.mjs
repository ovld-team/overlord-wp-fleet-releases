#!/usr/bin/env node
// Usage: node tools/verify-release.mjs --index release-index.json --zip overlord-wp-fleet-X.Y.Z.zip --src src/overlord-wp-fleet [--keys keys/release-keys.json]
// Keys are the ones the plugin source pins (RELEASE_KEYS / REVOKED_KEY_IDS in includes/class-ovp-release.php). --keys, when given, must equal them.
// Exit 0 when the index validates, a pinned unrevoked key's signature verifies, and the zip matches sha256 and size. Else exit 1.
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { parseArgs, readReleaseKeysFromSrc, sameKeyset, validateIndex, verifyIndex } from "./lib-release.mjs";

const a = parseArgs(process.argv.slice(2));
const fail = (m) => {
  console.error(`FAIL: ${m}`);
  process.exit(1);
};
if (typeof a.index !== "string" || typeof a.zip !== "string" || typeof a.src !== "string" || (a.keys !== undefined && typeof a.keys !== "string")) fail("usage: verify-release.mjs --index <file> --zip <file> --src <plugin source dir> [--keys <file>]");
let idx, keys, zip;
try {
  idx = JSON.parse(readFileSync(a.index, "utf8"));
  keys = readReleaseKeysFromSrc(a.src);
  zip = readFileSync(a.zip);
  if (typeof a.keys === "string") {
    const file = JSON.parse(readFileSync(a.keys, "utf8"));
    if (!sameKeyset(keys, { keys: file.keys, revoked: Array.isArray(file.revoked) ? file.revoked : [] })) throw new Error("--keys file differs from the keys pinned in --src");
  }
} catch (e) {
  fail(`cannot read inputs: ${e instanceof Error ? e.message : "error"}`);
}
const bad = validateIndex(idx);
if (bad) fail(`index invalid: ${bad}`);
const v = verifyIndex(idx, keys);
if (!v.verified) fail(`signature: ${v.error}`);
if (zip.length !== idx.size) fail(`size mismatch: zip ${zip.length}, index ${idx.size}`);
const sha = createHash("sha256").update(zip).digest("hex");
if (sha !== idx.sha256) fail(`sha256 mismatch: zip ${sha}, index ${idx.sha256}`);
console.log(`OK: ${idx.slug} ${idx.version} sha256=${sha} size=${zip.length} verified by key ${v.keyId}`);
