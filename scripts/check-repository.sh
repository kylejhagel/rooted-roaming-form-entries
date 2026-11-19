#!/usr/bin/env bash
set -euo pipefail
# Prevent accidental inclusion of common sensitive paths in tracked files.
while IFS= read -r file; do
  case "$file" in
    .env|.env.*|*/.env|*/.env.*|wp-config.php|*/wp-config.php|wp-config-local.php|*/wp-config-local.php|*.sql|*.sql.gz|*.sqlite|*.sqlite3|*.db|*.csv|*.tsv|*.xlsx|*.xls|*.jsonl|*.ndjson|*.pem|*.key|auth.json|*/auth.json|*.zip|dist/*|build/*|node_modules/*|vendor/*|exports/*|export/*|entries/*|submissions/*|private/*|backups/*|secrets/*|*/secrets/*)
      echo "Disallowed tracked file: $file" >&2
      exit 1
      ;;
  esac
done < <(git ls-files)
echo "Tracked-file safety checks passed."
