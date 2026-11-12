import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync, spawnSync } from "node:child_process";
import { existsSync, mkdtempSync, readFileSync, statSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const TOOL = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "tools", "gen-release-key.mjs");
const gen = (out, id = "test-key-a") => spawnSync("node", [TOOL, "--out", out, "--key-id", id], { encoding: "utf8" });
const tmp = () => mkdtempSync(path.join(tmpdir(), "ovp-gen-"));

test("writes 0600 key, stdout has no private material", () => {
  const out = path.join(tmp(), "k.key");
  const r = gen(out);
  assert.equal(r.status, 0, r.stderr);
  assert.equal(statSync(out).mode & 0o777, 0o600);
  const priv = readFileSync(out, "utf8").trim();
  assert.ok(priv.length > 40);
  assert.ok(!r.stdout.includes(priv) && !r.stderr.includes(priv));
  assert.match(r.stdout, /test-key-a/);
  assert.match(r.stdout, /gh secret set OVP_RELEASE_SIGNING_KEY --env release -R ovld-team\/overlord-wp-fleet-releases < /);
  const pub = /public key:\s+(\S+)/.exec(r.stdout)[1];
  assert.equal(Buffer.from(pub, "base64").length, 32);
});

test("refuses an existing file and leaves it untouched", () => {
  const out = path.join(tmp(), "k.key");
  assert.equal(gen(out).status, 0);
  const before = readFileSync(out, "utf8");
  const r = gen(out);
  assert.equal(r.status, 1);
  assert.equal(readFileSync(out, "utf8"), before);
});

test("refuses a dir inside a git work tree", () => {
  const repo = tmp();
  execFileSync("git", ["init", "-q", repo]);
  const out = path.join(repo, "k.key");
  const r = gen(out);
  assert.equal(r.status, 1);
  assert.match(r.stderr, /git work tree/);
  assert.ok(!existsSync(out));
});

test("rejects a bad key id", () => {
  assert.equal(gen(path.join(tmp(), "k.key"), "BAD ID").status, 1);
});
