#!/usr/bin/env bash

set -euo pipefail

PHP_VERSION="${1:-}"
PM_VERSION_MAJOR="${2:-5}"
INSTALL_PATH="${3:-.php-bin}"
COMPOSER_VERSION='2.11.1'
COMPOSER_SHA256='6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c'

if [[ ! "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
  echo "PHP version must use the major.minor format." >&2
  exit 2
fi
if [[ ! "$PM_VERSION_MAJOR" =~ ^[0-9]+$ ]]; then
  echo "Axolotl-PM version major must be numeric." >&2
  exit 2
fi

if ! command -v curl >/dev/null 2>&1 || ! command -v node >/dev/null 2>&1 || ! command -v tar >/dev/null 2>&1 || ! command -v sha256sum >/dev/null 2>&1; then
  echo "The PHP setup fallback requires curl, Node.js, tar, and sha256sum." >&2
  exit 2
fi

RELEASE_TAG="pm${PM_VERSION_MAJOR}-php-${PHP_VERSION}-latest"
ASSET_NAME="PHP-${PHP_VERSION}-Linux-x86_64-PM${PM_VERSION_MAJOR}.tar.gz"
API_BASE='https://api.github.com/repos/axolotl-pm/PHP-Binaries'
RELEASE_URL="${API_BASE}/releases/tags/${RELEASE_TAG}"
INSTALL_PATH="$(realpath -m "$INSTALL_PATH")"
TEMP_DIRECTORY="$(mktemp -d)"
trap 'rm -rf -- "$TEMP_DIRECTORY"' EXIT

API_HEADERS=(
  -H 'Accept: application/vnd.github+json'
  -H 'X-GitHub-Api-Version: 2022-11-28'
)
DOWNLOAD_HEADERS=()
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
  API_HEADERS+=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
  DOWNLOAD_HEADERS+=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
fi

echo "The direct PHP release download was unavailable; retrying through the GitHub release API."
release_json="$(curl --fail --silent --show-error --location \
  --retry 5 --retry-all-errors --retry-delay 2 --connect-timeout 15 --max-time 120 \
  "${API_HEADERS[@]}" "$RELEASE_URL")"
asset_details="$(node -e 'const fs = require("fs"); const name = process.argv[1]; const release = JSON.parse(fs.readFileSync(0, "utf8")); const asset = release.assets.find((candidate) => candidate.name === name); if (!asset || typeof asset.url !== "string" || typeof asset.digest !== "string") process.exit(1); process.stdout.write(`${asset.url}\t${asset.digest}\n`);' "$ASSET_NAME" <<<"$release_json")" || {
  echo "Could not find ${ASSET_NAME} in ${RELEASE_TAG}." >&2
  exit 1
}
IFS=$'\t' read -r asset_url asset_digest <<<"$asset_details"
if [[ ! "$asset_digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
  echo "The GitHub release does not provide a valid SHA-256 digest for ${ASSET_NAME}." >&2
  exit 1
fi
expected_asset_digest="${asset_digest#sha256:}"

tarball="${TEMP_DIRECTORY}/${ASSET_NAME}"
curl --fail --silent --show-error --location \
  --retry 5 --retry-all-errors --retry-delay 2 --connect-timeout 15 --max-time 300 \
  "${DOWNLOAD_HEADERS[@]}" -H 'Accept: application/octet-stream' "$asset_url" -o "$tarball"
actual_asset_digest="$(sha256sum "$tarball")"
actual_asset_digest="${actual_asset_digest%% *}"
if [[ "$actual_asset_digest" != "$expected_asset_digest" ]]; then
  echo "SHA-256 verification failed for ${ASSET_NAME}." >&2
  exit 1
fi

mkdir -p "$INSTALL_PATH"
tar -xzf "$tarball" -C "$INSTALL_PATH"

extension_parent="${INSTALL_PATH}/bin/php7/lib/php/extensions"
mapfile -t extension_directories < <(find "$extension_parent" -mindepth 1 -maxdepth 1 -type d -name '*debug-zts*' -print)
if (( ${#extension_directories[@]} > 1 )); then
  echo "Expected at most one PHP extension directory, found ${#extension_directories[@]}." >&2
  exit 1
fi
if (( ${#extension_directories[@]} == 1 )); then
  php_ini="${INSTALL_PATH}/bin/php7/bin/php.ini"
  extension_directory="${extension_directories[0]}"
  if grep -q '^extension_dir' "$php_ini"; then
    sed -i "s|^extension_dir.*|extension_dir=\"${extension_directory}\"|" "$php_ini"
  else
    printf '\nextension_dir="%s"\n' "$extension_directory" >> "$php_ini"
  fi
fi

composer_path="${INSTALL_PATH}/bin/php7/bin/composer"
curl --fail --silent --show-error --location \
  --retry 5 --retry-all-errors --retry-delay 2 --connect-timeout 15 --max-time 120 \
  "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar" -o "$composer_path"
actual_composer_digest="$(sha256sum "$composer_path")"
actual_composer_digest="${actual_composer_digest%% *}"
if [[ "$actual_composer_digest" != "$COMPOSER_SHA256" ]]; then
  echo "SHA-256 verification failed for Composer ${COMPOSER_VERSION}." >&2
  exit 1
fi
chmod +x "$composer_path"
echo "${INSTALL_PATH}/bin/php7/bin" >> "${GITHUB_PATH:?GITHUB_PATH is required in GitHub Actions}"
echo "Axolotl-PM PHP ${PHP_VERSION} was installed through the GitHub release API."
