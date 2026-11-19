#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
version="${1:-}"
main="rooted-roaming-form-entries.php"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Usage: bash scripts/package.sh MAJOR.MINOR.PATCH" >&2; exit 1
fi
[[ -f "$main" ]] || { echo "Missing main plugin file: $main" >&2; exit 1; }
php -r '$f=file_get_contents($argv[1]); if (!preg_match("/^[ \t*#@\/]*Version:\\s*([^\r\n]+)/mi", substr($f,0,8192),$m) || trim($m[1])!==$argv[2]) {fwrite(STDERR,"Plugin header version does not match release tag\n"); exit(1);}' "$main" "$version"
bash scripts/check-repository.sh
command -v zip >/dev/null || { echo "zip required" >&2; exit 1; }
mkdir -p dist
name="rooted-roaming-form-entries"
out="dist/${name}-${version}.zip"
output_path="$(pwd)/$out"
rm -f "$out"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/$name"
# Only include runtime plugin paths that actually exist; update this list after source import.
for path in "$main" readme.txt LICENSE license.txt includes admin public assets languages templates vendor; do
  [[ -e "$path" ]] || continue
  cp -R "$path" "$tmp/$name/"
done
# Remove known local/sensitive/build artifacts from copied allowlisted directories.
find "$tmp/$name" -type d \( -name .git -o -name node_modules -o -name tests -o -name test -o -name .github -o -name secrets -o -name backups -o -name exports -o -name submissions \) -prune -exec rm -rf {} +
find "$tmp/$name" -type f \( -name '*.log' -o -name '*.sql' -o -name '*.csv' -o -name '*.xlsx' -o -name '*.env' -o -name '.env*' -o -name 'wp-config.php' -o -name 'auth.json' \) -delete
(cd "$tmp" && zip -q -r "$output_path" "$name")
unzip -t "$out" >/dev/null
echo "Created $out"
