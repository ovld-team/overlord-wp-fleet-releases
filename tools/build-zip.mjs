#!/usr/bin/env node
// Usage: node tools/build-zip.mjs --src <plugin dir> --out <dir>
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import path from "node:path";
import { buildPluginRelease } from "./plugin-zip.mjs";
import { parseArgs } from "./lib-release.mjs";

const a = parseArgs(process.argv.slice(2));
if (typeof a.src !== "string" || typeof a.out !== "string") {
  console.error("usage: build-zip.mjs --src <dir> --out <dir>");
  process.exit(2);
}
try {
  const m = buildPluginRelease({ pluginDir: path.resolve(a.src), outDir: path.resolve(a.out) });
  const sha = createHash("sha256").update(readFileSync(path.join(path.resolve(a.out), m.filename))).digest("hex");
  console.log(`filename: ${m.filename}`);
  console.log(`size: ${m.size}`);
  console.log(`sha256: ${sha}`);
} catch (e) {
  console.error(`build failed: ${e instanceof Error ? e.message : "error"}`);
  process.exit(1);
}
