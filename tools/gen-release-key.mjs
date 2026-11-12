#!/usr/bin/env node
// Usage: node tools/gen-release-key.mjs --out <path outside any git work tree> --key-id <id>
// Run by the owner on an offline machine. Writes base64 PKCS8 DER (mode 0600). Prints only the key id, the raw public key
// and next steps. The private key is never printed.
import { generateKeyPairSync } from "node:crypto";
import { closeSync, existsSync, mkdirSync, openSync, writeSync } from "node:fs";
import { execFileSync } from "node:child_process";
import path from "node:path";
import { parseArgs, rawPubFromKeyObject } from "./lib-release.mjs";

const a = parseArgs(process.argv.slice(2));
const fail = (m) => {
  console.error(`gen-release-key: ${m}`);
  process.exit(1);
};
if (typeof a.out !== "string" || typeof a["key-id"] !== "string") fail("usage: --out <path> --key-id <id>");
const keyId = a["key-id"];
if (!/^[a-z0-9-]{3,32}$/.test(keyId)) fail("key id must match ^[a-z0-9-]{3,32}$");
const out = path.resolve(a.out);
if (existsSync(out)) fail(`refusing: ${out} already exists`);
const dir = path.dirname(out);
mkdirSync(dir, { recursive: true });
let inside = false;
try {
  inside = execFileSync("git", ["-C", dir, "rev-parse", "--is-inside-work-tree"], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim() === "true";
} catch {
  inside = false;
}
if (inside) fail(`refusing: ${dir} is inside a git work tree`);

const { privateKey } = generateKeyPairSync("ed25519");
const b64 = privateKey.export({ format: "der", type: "pkcs8" }).toString("base64");
let fd;
try {
  fd = openSync(out, "wx", 0o600);
} catch {
  fail(`cannot create ${out} (exists or not writable)`);
}
try {
  writeSync(fd, `${b64}\n`);
} finally {
  closeSync(fd);
}
const pub = rawPubFromKeyObject(privateKey);
console.log(`key id:      ${keyId}`);
console.log(`public key:  ${pub}`);
console.log("");
console.log("Add this to keys/release-keys.json (and to Ovp_Release::RELEASE_KEYS and server/src/release-keys.ts):");
console.log(`  "${keyId}": "${pub}"`);
console.log("");
console.log("Next steps:");
console.log(`  1. gh secret set OVP_RELEASE_SIGNING_KEY --env release -R ovld-team/overlord-wp-fleet-releases < ${out}`);
console.log(`  2. gh variable set OVP_RELEASE_KEY_ID --body ${keyId} -R ovld-team/overlord-wp-fleet-releases`);
console.log("  3. Move the file to an offline backup (password manager), then delete it from this machine.");
