/**
 * Materialize the exact first-party Studio block and pattern coordinates the
 * pinned browser module compiles in, so PHP can lock the same catalog into a
 * contextual authoring session and register the same coordinates for
 * published rendering without running Node.js at request time.
 *
 * The record is deployment evidence, not its own trust root: `--check` proves
 * the committed file still equals the installed exact `@kumwe/studio-core`
 * package, and `tools/verify-studio-release.mjs` requires that proof.
 *
 * Usage:
 *
 *   node tools/generate-studio-core-catalog.mjs          # rewrite the record
 *   node tools/generate-studio-core-catalog.mjs --check  # verify without writing
 */

import { readFile, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

import {
  createCoreProductionBlockDefinitions,
  createCoreProductionPatterns,
} from "@kumwe/studio-core";

const repositoryRoot = fileURLToPath(new URL("../", import.meta.url));
const releasePath = join(repositoryRoot, "resources/studio-contract/studio-release.json");
const catalogPath = join(repositoryRoot, "resources/studio-contract/core-catalog.json");
const check = process.argv.includes("--check");

const release = JSON.parse(await readFile(releasePath, "utf8"));
if (release.kind !== "studio-release" || typeof release.release !== "string") {
  throw new Error("The canonical Studio release record is malformed.");
}

const catalog = {
  kind: "studio-core-catalog",
  release: release.release,
  blocks: createCoreProductionBlockDefinitions()
    .map((definition) => ({
      type: definition.type,
      version: definition.version,
      revision: definition.revision,
    }))
    .sort((left, right) => left.type.localeCompare(right.type)),
  patterns: createCoreProductionPatterns()
    .map((pattern) => ({
      id: pattern.id,
      version: pattern.version,
      revision: pattern.revision,
    }))
    .sort((left, right) => left.id.localeCompare(right.id)),
};
const expected = `${JSON.stringify(catalog, null, 2)}\n`;

if (check) {
  let actual;
  try {
    actual = await readFile(catalogPath, "utf8");
  } catch {
    process.stderr.write("resources/studio-contract/core-catalog.json is missing.\n");
    process.exit(1);
  }
  if (actual !== expected) {
    process.stderr.write(
      "resources/studio-contract/core-catalog.json differs from the installed exact @kumwe/studio-core " +
        "catalog; run `node tools/generate-studio-core-catalog.mjs`.\n",
    );
    process.exit(1);
  }
  process.stdout.write(
    `Studio core catalog verified: ${catalog.blocks.length} block and ${catalog.patterns.length} pattern ` +
      `coordinates for release ${catalog.release}.\n`,
  );
} else {
  await writeFile(catalogPath, expected);
  process.stdout.write(
    `Wrote ${catalog.blocks.length} block and ${catalog.patterns.length} pattern coordinates for release ` +
      `${catalog.release}.\n`,
  );
}
