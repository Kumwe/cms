# Native computation runtime

Kumwe requires PHP 8.5 and `ext-kumwe_engine` 1.0.1 on Linux x86_64 with glibc. The PHP CLI and
FPM images include this runtime. Each image builds against its exact PHP patch, headers and toolchain;
the source release is pinned by SHA-256 in `resources/native-runtime/source.json`.

For a source checkout on a compatible host, install the PHP 8.5 development headers, the validated GCC 13 C++20 toolchain,
Autoconf, Make, CMake 3.25 or newer, pkg-config, curl and tar. Before running Composer:

```bash
sudo bash tools/install-native-engine.sh
```

Enable `/usr/local/lib/kumwe-native/kumwe_engine.ini` in the CLI and FPM configuration scan directories.
The installer emits `/usr/local/lib/kumwe-native/native-expected-tuple.json`. Keep this file read-only to
the application account. Set `KUMWE_NATIVE_EXPECTED_TUPLE` if installing it elsewhere; the images use
`/usr/local/etc/kumwe/native-expected-tuple.json`. Restart FPM after enabling the extension.

The installer verifies the pinned source archive and all embedded Engine source files, builds the
extension, and records the expected compatibility tuple directly from source and build metadata before
loading the module. It then compares the loaded module to that independent record. Container boot uses
the public `kumwe/computation` compatibility checks and supplies the shared native canonical encoder to
core and extension consumers. Missing metadata or an incompatible build refuses boot.

Rebuild the extension and regenerate the independent tuple whenever PHP, the native release or its build
toolchain changes. Do not generate the expected record from the loaded extension's capabilities. Runtime
requests never compile, download a replacement, invoke a subprocess or select a PHP semantic fallback.

An offline deployment may supply a cached release archive as the second installer argument, following an
absolute installation prefix. The same pinned SHA-256 check applies to cached bytes. A Composer-project
installation needs the native runtime provisioned before `composer create-project`; use the source
installer and pin from the matching reviewed App release on the provisioning machine.
