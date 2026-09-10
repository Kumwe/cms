#!/usr/bin/env bash
# Provisioning only: build the pinned native release and record its expected tuple before loading it.
set -euo pipefail

native_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
native_prefix="${1:-/usr/local/lib/kumwe-native}"
native_manifest="$native_root/resources/native-runtime/source.json"

case "$native_prefix" in
    /*) ;;
    *) echo 'The native installation prefix must be absolute.' >&2; exit 64 ;;
esac
for native_command in php phpize php-config cmake make cc c++ curl tar sha256sum; do
    command -v "$native_command" >/dev/null || { echo "Required native build tool missing: $native_command" >&2; exit 69; }
done
php -n -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5 || PHP_OS_FAMILY !== "Linux"
    || php_uname("m") !== "x86_64" || PHP_INT_SIZE !== 8) { throw new RuntimeException("The native release requires PHP 8.5 on Linux x86_64."); }'
getconf GNU_LIBC_VERSION >/dev/null

native_tmp="$(mktemp -d)"
trap 'rm -rf -- "$native_tmp"' EXIT
readarray -t native_coordinates < <(php -n -r '
    $record = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    foreach (["url", "sha256"] as $field) { echo $record["binding"][$field], "\n"; }
' "$native_manifest")
if [ -n "${2:-}" ]; then
    # An offline release cache is allowed only under the same mandatory, reviewed SHA-256 check.
    cp -- "$2" "$native_tmp/source.tar.gz"
else
    curl --fail --location --silent --show-error --retry 3 --connect-timeout 20 --max-time 180 --proto '=https' --tlsv1.2 \
        "${native_coordinates[0]}" --output "$native_tmp/source.tar.gz"
fi
printf '%s  %s\n' "${native_coordinates[1]}" "$native_tmp/source.tar.gz" | sha256sum --check --status
tar -xzf "$native_tmp/source.tar.gz" -C "$native_tmp"
cd "$native_tmp/kumwe-engine-php"
php -n tools/verify-engine.php
php -n -r '
    $pin = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents("resources/engine-lock.json"), true, 64, JSON_THROW_ON_ERROR);
    if ($lock["commit"] !== $pin["engine"]["commit"] || $lock["archive_sha256"] !== $pin["engine"]["sha256"]
        || $lock["version"] !== $pin["engine"]["version"]) { throw new RuntimeException("The embedded Engine does not match the reviewed source pin."); }
' "$native_manifest"
phpize
./configure --enable-kumwe_engine
make -j2
# This tool reads source, CMake output and build-identity.json; it never queries the loaded extension.
php -n tools/expected-tuple.php > "$native_tmp/native-expected-tuple.json"
php -n -r '
    $pin = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    $tuple = json_decode(file_get_contents($argv[2]), true, 64, JSON_THROW_ON_ERROR);
    if ($tuple["extension_version"] !== $pin["binding"]["version"]
        || $tuple["embedded_engine_commit"] !== $pin["engine"]["commit"]
        || $tuple["embedded_source_sha256"] !== $pin["engine"]["sha256"]) {
        throw new RuntimeException("The built native identity does not match the reviewed source pin.");
    }
' "$native_manifest" "$native_tmp/native-expected-tuple.json"
install -d -m 0755 "$native_prefix"
install -m 0755 modules/kumwe_engine.so "$native_prefix/kumwe_engine.so"
install -m 0644 "$native_tmp/native-expected-tuple.json" "$native_prefix/native-expected-tuple.json"
printf 'extension=%s/kumwe_engine.so\n' "$native_prefix" > "$native_prefix/kumwe_engine.ini"
chmod 0644 "$native_prefix/kumwe_engine.ini"
php -n -d "extension=$native_prefix/kumwe_engine.so" -r '
    $expected = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    $actual = (new Kumwe\Engine\Runtime())->capabilities();
    foreach (["extension_version", "embedded_engine_commit", "embedded_source_sha256", "binding_build_digest"] as $field) {
        if ($expected[$field] !== $actual[$field]) { throw new RuntimeException("The installed native identity differs from its independent build record."); }
    }
    if ($expected["capabilities"] !== $actual["computation"] || $expected["binding_features"] !== $actual["binding_features"]) {
        throw new RuntimeException("The installed native capabilities differ from the independent build record.");
    }
' "$native_prefix/native-expected-tuple.json"
printf 'Installed native Engine 1.0.1; independent tuple: %s/native-expected-tuple.json\n' "$native_prefix"
