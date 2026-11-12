// MIRRORED FILE. Do not edit here: this is a verbatim copy of scripts/lib/plugin-zip.mjs from the private
// ovld-team/overlord-wp-fleet repository, pushed by the private release workflow. Edit it there.
// Deterministic ZIP builder for the WordPress plugin. Node builtins only (runs on Hostinger during `npm run build`).
// Same input tree -> same bytes -> same sha256, so the published hash only changes when the plugin changes.
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync, renameSync } from "node:fs";
import path from "node:path";
import { crc32 } from "node:zlib";

export const PLUGIN_SLUG = "overlord-wp-fleet";

/** Paths (relative to the plugin dir, posix) that never ship: dev tooling, tests, VCS and OS junk. */
const EXCLUDE_TOP = new Set(["vendor", "tests", "node_modules", ".git", ".github", "composer.json", "composer.lock", "phpstan.neon", "phpstan-baseline.neon", "phpunit.xml", "phpunit.xml.dist", ".phpcs.xml", ".editorconfig", ".gitignore", ".gitattributes"]);
const EXCLUDE_NAME = /^(\.DS_Store|Thumbs\.db|__MACOSX|\._.*|\.phpunit\.result\.cache|.*\.(log|orig|rej|swp|bak))$/;

function walk(root, rel = "") {
  const out = [];
  const entries = readdirSync(path.join(root, rel), { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
  for (const e of entries) {
    const r = rel ? `${rel}/${e.name}` : e.name;
    if (!rel && EXCLUDE_TOP.has(e.name)) continue;
    if (EXCLUDE_NAME.test(e.name)) continue;
    if (e.isSymbolicLink()) continue; // never ship links
    if (e.isDirectory()) out.push(...walk(root, r));
    else if (e.isFile()) out.push(r);
  }
  return out;
}

/**
 * The files that ship, relative posix paths, sorted by raw bytes. When `pluginDir` is inside a git work tree the list is
 * `git ls-files` (tracked files only: an untracked editor file or build leftover on one machine cannot change the zip);
 * otherwise (a host checkout without .git, a test fixture) it is a directory walk. The same exclusions apply to both.
 */
export function listPluginFiles(pluginDir) {
  let tracked = null;
  try {
    const out = execFileSync("git", ["-C", pluginDir, "ls-files", "-z", "--", "."], { encoding: "buffer", stdio: ["ignore", "pipe", "ignore"], timeout: 15_000 });
    const rel = out.toString("utf8").split("\0").filter(Boolean);
    if (rel.length > 0) tracked = rel;
  } catch {
    /* no git, not a work tree: walk the disk */
  }
  let files;
  if (tracked) {
    files = tracked.filter((f) => {
      const parts = f.split("/");
      if (EXCLUDE_TOP.has(parts[0])) return false;
      return !parts.some((x) => EXCLUDE_NAME.test(x));
    });
    for (const f of files) {
      let st;
      try {
        st = statSync(path.join(pluginDir, f));
      } catch {
        throw new Error(`tracked file is missing from the working tree: ${f}`);
      }
      if (!st.isFile()) throw new Error(`tracked path is not a regular file: ${f}`);
    }
  } else {
    files = walk(pluginDir);
  }
  return [...new Set(files)].sort((a, b) => Buffer.compare(Buffer.from(a, "utf8"), Buffer.from(b, "utf8")));
}

/** Read `Version:` from the main plugin file header. */
export function readPluginVersion(pluginDir) {
  const head = readFileSync(path.join(pluginDir, `${PLUGIN_SLUG}.php`), "utf8").slice(0, 8192);
  const m = /^[ \t/*#@]*Version:[ \t]*([0-9A-Za-z][0-9A-Za-z.+_~-]{0,39})[ \t]*$/m.exec(head);
  if (!m) throw new Error("plugin header has no Version");
  return m[1];
}

/**
 * Migration capabilities of a plugin source tree, found by text scan so the master can refuse a migration with a plugin
 * that would break a live legacy connection (docs/PROTOCOL.md section 13):
 *   autopair               consumes the one-shot `ovp_autopair` option
 *   legacy-retire          serves POST /ovp/v1/fleet/legacy-retire
 *   self-update            includes/class-ovp-self-update.php serves fleet/self-update (signed native upgrade)
 *   autopair-keeps-legacy  autopair does not deactivate the legacy plugin by itself
 */
export function detectCapabilities(pluginDir, files) {
  const php = files.filter((f) => f.endsWith(".php")).map((f) => readFileSync(path.join(pluginDir, f), "utf8"));
  const all = php.join("\n");
  const caps = [];
  const autopair = all.includes("ovp_autopair");
  if (autopair) caps.push("autopair");
  if (all.includes("legacy-retire")) caps.push("legacy-retire");
  const su = path.join(pluginDir, "includes/class-ovp-self-update.php");
  if (files.includes("includes/class-ovp-self-update.php") && readFileSync(su, "utf8").includes("fleet/self-update")) caps.push("self-update");
  if (autopair && !/\$out\[\s*['"]legacy['"]\s*\]\s*=\s*self::retire_legacy\(\s*\)/.test(all)) caps.push("autopair-keeps-legacy");
  return caps;
}

// Reproducible archive. Everything that could differ between two builds of the same tree is pinned:
//   - entry order: sorted by the raw UTF-8 bytes of the name (not locale, not readdir order)
//   - timestamps: DOS epoch 1980-01-01 00:00:00 for every entry (file mtimes, the clock and SOURCE_DATE_EPOCH are ignored on
//     purpose: the hash the hosted master publishes must equal a rebuild on any other machine and in any environment)
//   - modes: files 0644, directories 0755 (the checkout's umask / chmod never shows)
//   - no extra fields, no comments, fixed "made by" (unix) and version
//   - every entry is STORED (method 0), not deflated: DEFLATE output depends on the zlib bundled with the running Node
//     (version, SIMD/zlib-ng builds), so the Mac and Hostinger could publish different hashes for the same tree. The plugin is
//     under 1 MB; the crc32 is the only computed field and it is defined by the data.
//   - the file list is `git ls-files` when available (see listPluginFiles), never a stray untracked file
const DOS_TIME = 0;
const DOS_DATE = ((1980 - 1980) << 9) | (1 << 5) | 1;
const nameOrder = (a, b) => Buffer.compare(Buffer.from(a.name, "utf8"), Buffer.from(b.name, "utf8"));

export function buildZipBuffer(entries0) {
  const entries = [...entries0].sort(nameOrder);
  const chunks = [];
  const central = [];
  let off = 0;
  for (const e of entries) {
    const data = e.data;
    const comp = e.dir ? Buffer.alloc(0) : data;
    const method = 0;
    const crc = e.dir ? 0 : crc32(data) >>> 0;
    const name = Buffer.from(e.name, "utf8");
    const lh = Buffer.alloc(30);
    lh.writeUInt32LE(0x04034b50, 0);
    lh.writeUInt16LE(20, 4);
    lh.writeUInt16LE(0x800, 6);
    lh.writeUInt16LE(method, 8);
    lh.writeUInt16LE(DOS_TIME, 10);
    lh.writeUInt16LE(DOS_DATE, 12);
    lh.writeUInt32LE(crc, 14);
    lh.writeUInt32LE(comp.length, 18);
    lh.writeUInt32LE(e.dir ? 0 : data.length, 22);
    lh.writeUInt16LE(name.length, 26);
    chunks.push(lh, name, comp);
    const ch = Buffer.alloc(46);
    ch.writeUInt32LE(0x02014b50, 0);
    ch.writeUInt16LE((3 << 8) | 20, 4); // made by unix
    ch.writeUInt16LE(20, 6);
    ch.writeUInt16LE(0x800, 8);
    ch.writeUInt16LE(method, 10);
    ch.writeUInt16LE(DOS_TIME, 12);
    ch.writeUInt16LE(DOS_DATE, 14);
    ch.writeUInt32LE(crc, 16);
    ch.writeUInt32LE(comp.length, 20);
    ch.writeUInt32LE(e.dir ? 0 : data.length, 24);
    ch.writeUInt16LE(name.length, 28);
    ch.writeUInt32LE(((e.dir ? 0o040755 : 0o100644) << 16) >>> 0, 38);
    ch.writeUInt32LE(off, 42);
    central.push(ch, name);
    off += 30 + name.length + comp.length;
  }
  const cd = Buffer.concat(central);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06054b50, 0);
  eocd.writeUInt16LE(entries.length, 8);
  eocd.writeUInt16LE(entries.length, 10);
  eocd.writeUInt32LE(cd.length, 12);
  eocd.writeUInt32LE(off, 16);
  return Buffer.concat([...chunks, cd, eocd]);
}

/** The zip bytes of `pluginDir` as `<slug>/...`, plus its manifest. Pure: nothing is written, cwd and clock are not read. */
export function buildPluginZip({ pluginDir }) {
  pluginDir = path.resolve(pluginDir);
  const version = readPluginVersion(pluginDir);
  const files = listPluginFiles(pluginDir);
  if (!files.includes(`${PLUGIN_SLUG}.php`)) throw new Error("main plugin file missing from the file list");
  const dirs = new Set();
  for (const f of files) {
    const parts = f.split("/");
    for (let i = 1; i < parts.length; i++) dirs.add(parts.slice(0, i).join("/"));
  }
  const entries = [{ name: `${PLUGIN_SLUG}/`, dir: true }];
  for (const d of [...dirs].sort()) entries.push({ name: `${PLUGIN_SLUG}/${d}/`, dir: true });
  for (const f of files) entries.push({ name: `${PLUGIN_SLUG}/${f}`, data: readFileSync(path.join(pluginDir, f)) });
  const zip = buildZipBuffer(entries);
  const sha256 = createHash("sha256").update(zip).digest("hex");
  const filename = `${PLUGIN_SLUG}-${version}.zip`;
  const manifest = { slug: PLUGIN_SLUG, version, filename, sha256, size: zip.length, files: files.length, capabilities: detectCapabilities(pluginDir, files) };
  return { zip, manifest };
}

/**
 * Zip `pluginDir` as `<slug>/...` into `<outDir>/<slug>-<version>.zip` and write `<outDir>/plugin-release.json`
 * ({slug, version, filename, sha256, size, files, capabilities}). Returns that manifest. The server build
 * (scripts/build-plugin-zip.mjs) and scripts/migrate-local.mjs both go through here, so a local rebuild of an identical
 * plugin tree has the same sha256 as the hosted zip.
 */
export function buildPluginRelease({ pluginDir, outDir }) {
  const { zip, manifest } = buildPluginZip({ pluginDir });
  const { filename } = manifest;
  mkdirSync(outDir, { recursive: true });
  const tmp = path.join(outDir, `.${filename}.tmp`);
  writeFileSync(tmp, zip);
  renameSync(tmp, path.join(outDir, filename));
  for (const old of readdirSync(outDir)) {
    if (old !== filename && old.startsWith(`${PLUGIN_SLUG}-`) && old.endsWith(".zip")) rmSync(path.join(outDir, old), { force: true });
  }
  const mtmp = path.join(outDir, ".plugin-release.json.tmp");
  writeFileSync(mtmp, `${JSON.stringify(manifest, null, 2)}\n`);
  renameSync(mtmp, path.join(outDir, "plugin-release.json"));
  return manifest;
}

export function dirExists(p) {
  try {
    return statSync(p).isDirectory();
  } catch {
    return false;
  }
}
