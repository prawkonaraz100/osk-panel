#!/usr/bin/env bash
set -euo pipefail

release_sha="${1:-}"
if [[ ! "$release_sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Usage: $0 <40-char-git-sha>" >&2
  exit 2
fi

if [[ ! -d vendor || ! -f vendor/autoload.php ]]; then
  echo "Production vendor dependencies are missing." >&2
  exit 1
fi
if [[ ! -d public/build ]]; then
  echo "Frontend production build is missing." >&2
  exit 1
fi

mkdir -p dist
artifact="dist/osk-panel-${release_sha}.tar.gz"
checksum="${artifact}.sha256"
manifest="dist/osk-panel-${release_sha}.manifest.json"

rm -f "$artifact" "$checksum" "$manifest"

tar -czf "$artifact" \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  app \
  artisan \
  bootstrap \
  composer.json \
  composer.lock \
  config \
  database \
  public \
  resources \
  routes \
  storage \
  vendor

sha256sum "$artifact" > "$checksum"
artifact_sha256="$(awk '{print $1}' "$checksum")"

printf '{"release_sha":"%s","artifact":"%s","sha256":"%s","contains_env_file":false}\n' \
  "$release_sha" \
  "$(basename "$artifact")" \
  "$artifact_sha256" > "$manifest"

if tar -tzf "$artifact" | grep -Eq '(^|/)\.env($|\.)'; then
  echo "Release artifact unexpectedly contains an environment file." >&2
  exit 1
fi

echo "RELEASE_ARTIFACT=PASS"
echo "release_sha=$release_sha"
echo "sha256=$artifact_sha256"
