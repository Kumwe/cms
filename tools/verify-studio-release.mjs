/**
 * Hold the administrator build, installed packages, and release evidence to one
 * exact Studio release coordinate.
 *
 * A semantic range is not a qualification record. The App declares every
 * Studio package at its exact published version, PIN.json records the registry,
 * the official tarball URL, the tarball SHA-256 and the SHA-512 integrity of
 * each package, and the lockfile must resolve every package to that same
 * registry tarball with that same integrity. No package bytes are committed:
 * the registry and Producer's provenance record are the byte authorities, and
 * the materialized first-party catalog must equal what the installed exact
 * packages compile in.
 */

import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { execFileSync } from "node:child_process";

const repositoryRoot = fileURLToPath(new URL("../", import.meta.url));
const contractRoot = join(repositoryRoot, "resources/studio-contract");
const releasePath = join(contractRoot, "studio-release.json");
const pinPath = join(contractRoot, "PIN.json");
const protocolPackageRoot = fileURLToPath(
  new URL("../", import.meta.resolve("@kumwe/studio-protocol")),
);
const testkitPackageRoot = fileURLToPath(
  new URL("../", import.meta.resolve("@kumwe/studio-testkit")),
);
const corpusManifestPath = join(testkitPackageRoot, "corpus-manifest.json");
const packagePath = join(repositoryRoot, "package.json");
const lockPath = join(repositoryRoot, "package-lock.json");

const packageNames = Object.freeze([
  "@kumwe/studio-core",
  "@kumwe/studio-media",
  "@kumwe/studio-preview",
  "@kumwe/studio-protocol",
  "@kumwe/studio-renderer-web",
  "@kumwe/studio-rich-text",
  "@kumwe/studio",
  "@kumwe/studio-testkit",
]);
const requiredDependencies = packageNames;
const semanticVersion =
  /^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/;

const errors = [];
const releaseBytes = await readFile(releasePath);
const corpusManifestBytes = await readFile(corpusManifestPath);
const release = decode(
  releaseBytes,
  "resources/studio-contract/studio-release.json",
);
const pin = decode(
  await readFile(pinPath),
  "resources/studio-contract/PIN.json",
);
const manifest = decode(await readFile(packagePath), "package.json");
const lock = decode(await readFile(lockPath), "package-lock.json");

verifyReleaseRecord();
await verifyReleaseCopies();
await verifyPin();
verifyCoreCatalog();
verifyDependencyManifest();
verifyLockfile();

if (errors.length > 0) {
  process.stderr.write("The App does not consume one exact Studio release:\n");
  for (const error of errors) {
    process.stderr.write(` - ${error}\n`);
  }
  process.exit(1);
}

process.stdout.write(
  `Studio release ${release.release} verified: ${packageNames.length} exact packages, ` +
    "three identical release records, registry-pinned integrity, a materialized first-party catalog, and a matching lockfile.\n",
);

/** Verify the closed release-record shape and coordinated versions. */
function verifyReleaseRecord() {
  const expectedMembers = [
    "browserArtifacts",
    "claimedProfiles",
    "contractVersion",
    "corpusManifestDigest",
    "kind",
    "packages",
    "protocolVersion",
    "release",
  ];
  if (
    JSON.stringify(Object.keys(release).sort()) !==
    JSON.stringify(expectedMembers)
  ) {
    errors.push(
      "studio-release.json contains an unknown member or omits a required member.",
    );
  }
  if (
    release.kind !== "studio-release" ||
    typeof release.release !== "string" ||
    !semanticVersion.test(release.release)
  ) {
    errors.push(
      "studio-release.json must be a Studio release record with a release name.",
    );
    return;
  }
  if (!isPlainObject(release.packages)) {
    errors.push("studio-release.json must carry its package map.");
    return;
  }

  const actualNames = Object.keys(release.packages).sort();
  if (
    JSON.stringify(actualNames) !== JSON.stringify([...packageNames].sort())
  ) {
    errors.push(
      "studio-release.json must name exactly the eight public Studio packages.",
    );
  }
  for (const name of packageNames) {
    const version = release.packages[name];
    if (version !== release.release) {
      errors.push(
        `${name} is ${String(version)} in the release record, not ${release.release}.`,
      );
    }
  }
  if (
    typeof release.protocolVersion !== "string" ||
    typeof release.corpusManifestDigest !== "string" ||
    !Array.isArray(release.claimedProfiles)
  ) {
    errors.push(
      "studio-release.json lacks its protocol, corpus digest, or profile claims.",
    );
  }
  if (release.corpusManifestDigest !== sriSha256(corpusManifestBytes)) {
    errors.push(
      "studio-release.json corpusManifestDigest does not match the vendored corpus manifest bytes.",
    );
  }
  const expectedBrowserArtifacts = {
    authoringArchive: {
      archiveStem: `studio-browser-${release.release}`,
      assetRole: "browser-module",
      loading: "module",
    },
    enhancementRuntime: {
      assetRole: "enhancement-runtime",
      loading: "defer",
      package: "@kumwe/studio-renderer-web",
      packageBasePath: "dist/browser/",
    },
    manifest: {
      name: "studio-assets.json",
      schema:
        "https://schemas.kumwe.org/studio/v1/studio-browser-assets.schema.json",
    },
  };
  if (
    canonical(release.browserArtifacts) !== canonical(expectedBrowserArtifacts)
  ) {
    errors.push(
      "studio-release.json browserArtifacts must pin the exact coordinated browser locators.",
    );
  }
}

/**
 * Serialize one JSON value with recursively sorted object members.
 *
 * @param {unknown} value Decoded JSON value.
 * @returns {string} Canonical serialization for exact comparison.
 */
function canonical(value) {
  if (Array.isArray(value)) {
    return `[${value.map(canonical).join(",")}]`;
  }
  if (isPlainObject(value)) {
    const members = Object.keys(value)
      .sort()
      .map((name) => `${JSON.stringify(name)}:${canonical(value[name])}`);
    return `{${members.join(",")}}`;
  }
  return JSON.stringify(value);
}

/** Prove the exact installed protocol, testkit, and host name the same release bytes. */
async function verifyReleaseCopies() {
  for (const [label, path] of [
    [
      "installed @kumwe/studio-protocol release record",
      join(protocolPackageRoot, "studio-release.json"),
    ],
    [
      "installed @kumwe/studio-testkit release record",
      join(testkitPackageRoot, "studio-release.json"),
    ],
  ]) {
    let bytes;
    try {
      bytes = await readFile(path);
    } catch {
      errors.push(`${label} is missing.`);
      continue;
    }
    if (!bytes.equals(releaseBytes)) {
      errors.push(
        `${label} is not byte-identical to the App release record.`,
      );
    }
  }
}

/** Verify the registry coordinates and record digest named by PIN.json. */
async function verifyPin() {
  const releasePin = pin.release_record;
  if (!isPlainObject(releasePin)) {
    errors.push("PIN.json lacks release_record evidence.");
  } else {
    if (
      releasePin.release !== release.release ||
      releasePin.file !== "studio-release.json"
    ) {
      errors.push(
        "PIN.json release_record does not identify the vendored release.",
      );
    }
    if (releasePin.sha256 !== sha256(releaseBytes)) {
      errors.push(
        "PIN.json release-record digest does not match the vendored bytes.",
      );
    }
  }

  const registry = pin.registry;
  if (typeof registry !== "string" || !/^https:\/\/[a-z0-9.-]+$/.test(registry)) {
    errors.push("PIN.json must name one HTTPS npm registry origin.");
    return;
  }
  if (!isPlainObject(pin.pinned)) {
    errors.push("PIN.json lacks its package pin map.");
    return;
  }
  for (const name of packageNames) {
    const entry = pin.pinned[name];
    if (!isPlainObject(entry)) {
      errors.push(`PIN.json does not pin ${name}.`);
      continue;
    }
    const version = release.packages?.[name];
    if (entry.version !== version) {
      errors.push(
        `PIN.json version for ${name} differs from studio-release.json.`,
      );
    }
    const unscoped = name.slice("@kumwe/".length);
    const tarball = `${registry}/${name}/-/${unscoped}-${String(version)}.tgz`;
    if (entry.tarball !== tarball) {
      errors.push(`PIN.json must name the official registry tarball ${tarball} for ${name}.`);
    }
    if (
      typeof entry.npm_tarball_sha256 !== "string" ||
      !/^[0-9a-f]{64}$/.test(entry.npm_tarball_sha256)
    ) {
      errors.push(`PIN.json must record the lowercase SHA-256 of the ${name} tarball.`);
    }
    if (
      typeof entry.integrity !== "string" ||
      !/^sha512-[A-Za-z0-9+/]{86}==$/.test(entry.integrity)
    ) {
      errors.push(`PIN.json must record the SHA-512 integrity of the ${name} tarball.`);
    }
    const installed = lock.packages?.[`node_modules/${name}`];
    if (!isPlainObject(installed)) {
      continue;
    }
    if (installed.resolved !== tarball) {
      errors.push(
        `package-lock.json resolves ${name} to ${String(installed.resolved)}, not the pinned registry tarball.`,
      );
    }
    if (installed.integrity !== entry.integrity) {
      errors.push(`package-lock.json integrity for ${name} differs from PIN.json.`);
    }
  }
  try {
    const packagesDirectory = join(contractRoot, "packages");
    await readFile(packagesDirectory);
    errors.push("resources/studio-contract/packages must not exist: packages resolve from the registry.");
  } catch (failure) {
    if (failure?.code === "EISDIR") {
      errors.push("resources/studio-contract/packages must not exist: packages resolve from the registry.");
    }
  }
}

/** Require the materialized first-party catalog to equal the installed exact packages. */
function verifyCoreCatalog() {
  try {
    execFileSync(
      process.execPath,
      [join(repositoryRoot, "tools/generate-studio-core-catalog.mjs"), "--check"],
      { stdio: ["ignore", "ignore", "pipe"] },
    );
  } catch (failure) {
    const detail = failure?.stderr?.toString?.().trim();
    errors.push(
      detail && detail !== ""
        ? detail
        : "resources/studio-contract/core-catalog.json does not match the installed @kumwe/studio-core.",
    );
  }
}

/** Require every Studio dependency to be exact and part of the release set. */
function verifyDependencyManifest() {
  const declarations = new Map();
  for (const section of [
    "dependencies",
    "devDependencies",
    "optionalDependencies",
    "peerDependencies",
  ]) {
    const values = manifest[section];
    if (!isPlainObject(values)) {
      continue;
    }
    for (const [name, specifier] of Object.entries(values)) {
      if (!name.startsWith("@kumwe/studio")) {
        continue;
      }
      if (declarations.has(name)) {
        errors.push(`${name} is declared in more than one dependency section.`);
      }
      declarations.set(name, specifier);
    }
  }

  for (const name of requiredDependencies) {
    if (!declarations.has(name)) {
      errors.push(
        `${name} is required by the Studio integration but is not declared.`,
      );
    }
  }
  for (const [name, specifier] of declarations) {
    if (!packageNames.includes(name)) {
      errors.push(`${name} is not part of the coordinated Studio release.`);
      continue;
    }
    const version = release.packages?.[name];
    if (specifier !== version) {
      errors.push(
        `${name} must use exact ${String(version)}, not ${String(specifier)}.`,
      );
    }
  }
}

/** Require npm's root declaration and installed package entries to agree. */
function verifyLockfile() {
  if (!isPlainObject(lock.packages) || !isPlainObject(lock.packages[""])) {
    errors.push("package-lock.json lacks its root package record.");
    return;
  }
  const root = lock.packages[""];
  const rootDependencies = {
    ...(root.dependencies ?? {}),
    ...(root.devDependencies ?? {}),
    ...(root.optionalDependencies ?? {}),
    ...(root.peerDependencies ?? {}),
  };

  const declaredNames = new Set();
  for (const section of [
    "dependencies",
    "devDependencies",
    "optionalDependencies",
    "peerDependencies",
  ]) {
    for (const name of Object.keys(manifest[section] ?? {})) {
      if (name.startsWith("@kumwe/studio")) {
        declaredNames.add(name);
      }
    }
  }

  for (const name of [...declaredNames].sort()) {
    const manifestSpecifier =
      manifest.dependencies?.[name] ??
      manifest.devDependencies?.[name] ??
      manifest.optionalDependencies?.[name] ??
      manifest.peerDependencies?.[name];
    if (rootDependencies[name] !== manifestSpecifier) {
      errors.push(
        `package-lock.json root specifier for ${name} differs from package.json.`,
      );
    }
    const installed = lock.packages[`node_modules/${name}`];
    if (
      !isPlainObject(installed) ||
      installed.version !== release.packages?.[name]
    ) {
      errors.push(
        `package-lock.json does not install ${name}@${String(release.packages?.[name])}.`,
      );
    }
  }
}

/** Decode one JSON document while retaining a useful file identity. */
function decode(bytes, label) {
  try {
    const value = JSON.parse(bytes.toString("utf8"));
    if (!isPlainObject(value)) {
      throw new Error("root is not an object");
    }
    return value;
  } catch (cause) {
    throw new Error(`${label} is not a JSON object.`, { cause });
  }
}

/** Return a lower-case hexadecimal SHA-256 digest. */
function sha256(bytes) {
  return createHash("sha256").update(bytes).digest("hex");
}

/** Return the SRI SHA-256 digest used by the Studio release record. */
function sriSha256(bytes) {
  return `sha256-${createHash("sha256").update(bytes).digest("base64")}`;
}

/** Distinguish JSON objects from arrays and null. */
function isPlainObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}
