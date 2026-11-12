#!/usr/bin/env node
// Usage: node tools/preflight.mjs --tag vX.Y.Z [--root <repo dir>] [--offline]
// --offline skips the two `gh` checks (tests only). In CI GH_TOKEN and the repo come from the runner.
// OVP_RELEASE_KEY_ID (the signing key id) must be set and pinned in src/overlord-wp-fleet/includes/class-ovp-release.php.
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";
import { REPO, SLUG, cmpVersion, parseArgs, readReleaseKeysFromSrc, sameKeyset } from "./lib-release.mjs";

const a = parseArgs(process.argv.slice(2));
const root = path.resolve(typeof a.root === "string" ? a.root : path.join(path.dirname(new URL(import.meta.url).pathname), ".."));
const fail = (m) => {
  console.error(`preflight FAIL: ${m}`);
  process.exit(1);
};
if (typeof a.tag !== "string") fail("usage: preflight.mjs --tag vX.Y.Z");
const m = /^v(\d+\.\d+\.\d+)$/.exec(a.tag);
if (!m) fail(`tag ${a.tag} is not vX.Y.Z`);
const version = m[1];

let source, keys, head;
try {
  source = JSON.parse(readFileSync(path.join(root, "SOURCE.json"), "utf8"));
  keys = JSON.parse(readFileSync(path.join(root, "keys/release-keys.json"), "utf8"));
  head = readFileSync(path.join(root, "src", SLUG, `${SLUG}.php`), "utf8").slice(0, 8192);
} catch (e) {
  fail(`cannot read repository files: ${e instanceof Error ? e.message : "error"}`);
}
if (source.version !== version) fail(`SOURCE.json version ${source.version} != tag ${version}`);
const hv = /^[ \t/*#@]*Version:[ \t]*(\S+)[ \t]*$/m.exec(head);
if (!hv || hv[1] !== version) fail(`source header Version ${hv ? hv[1] : "missing"} != tag ${version}`);
if (!keys || typeof keys.keys !== "object" || keys.keys === null || Object.keys(keys.keys).length === 0) fail("keys/release-keys.json has no keys");
// The keys the shipped plugin pins are the ones that matter: fail closed unless they equal the keys file and contain the signing key id.
let pinned;
try {
  pinned = readReleaseKeysFromSrc(path.join(root, "src", SLUG));
} catch (e) {
  fail(`cannot read the pinned keys from the mirrored source: ${e instanceof Error ? e.message : "error"}`);
}
if (Object.keys(pinned.keys).length === 0) fail("the mirrored plugin source pins no release key (RELEASE_KEYS is empty)");
if (!sameKeyset(pinned, { keys: keys.keys, revoked: Array.isArray(keys.revoked) ? keys.revoked : [] })) fail("keys/release-keys.json differs from RELEASE_KEYS / REVOKED_KEY_IDS in the mirrored plugin source");
const keyId = process.env.OVP_RELEASE_KEY_ID;
if (typeof keyId !== "string" || keyId === "") fail("OVP_RELEASE_KEY_ID is not set");
if (!Object.prototype.hasOwnProperty.call(pinned.keys, keyId)) fail(`OVP_RELEASE_KEY_ID ${keyId} is not pinned in the mirrored plugin source`);
if (pinned.revoked.includes(keyId)) fail(`OVP_RELEASE_KEY_ID ${keyId} is revoked in the mirrored plugin source`);

if (!a.offline) {
  const gh = (args) => execFileSync("gh", args, { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] });
  let exists = true;
  try {
    gh(["release", "view", a.tag, "-R", REPO]);
  } catch {
    exists = false;
  }
  if (exists) fail(`release ${a.tag} already exists`);
  let latest = null;
  try {
    latest = JSON.parse(gh(["release", "view", "--json", "tagName", "-R", REPO])).tagName;
  } catch {
    latest = null; // no release yet
  }
  const lm = latest && /^v(\d+\.\d+\.\d+)$/.exec(latest);
  if (lm && cmpVersion(version, lm[1]) <= 0) fail(`version ${version} is not greater than latest release ${latest}`);
}
console.log(`preflight OK: ${a.tag}`);
