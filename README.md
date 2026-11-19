# Rooted & Roaming — Form Entries

WordPress plugin repository for the Rooted & Roaming Form Entries workflow.

> **Bootstrap status:** This repository contains project standards and CI/CD scaffolding only. The existing plugin source still needs to be imported and validated before a release can be generated. Do not install this repository as a plugin yet.

## Installation (once plugin source has been imported)

1. Download an installation ZIP from a published GitHub Release (not GitHub's automatic *Source code* archive).
2. In WordPress Admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload `rooted-roaming-form-entries-<version>.zip` and select **Install Now**.
4. Activate **Rooted & Roaming — Form Entries**.
5. Follow the plugin's own settings/instructions after its existing functionality has been reviewed and documented.

No feature-level setup instructions are documented yet because the actual PHP source has not been imported. Database migrations, permissions, form integrations, and retention behavior must be verified from the source first.

## Development

Clone this repository into your local WordPress installation:

```powershell
cd C:\MAMP\htdocs\<site>\wp-content\plugins
git clone https://github.com/kylejhagel/rooted-roaming-form-entries.git
```

If the existing plugin directory already contains working files, **back it up and reconcile it with the clone before replacing anything**. Never overwrite a working site just to initialize Git.

Branch convention: `main` for reviewed, releasable changes; `feature/*`, `fix/*`, `refactor/*` for pull requests.

## Versioning

Use **SemVer** (`MAJOR.MINOR.PATCH`). The WordPress main plugin PHP header's `Version:` field is the source of truth. When plugin source is imported, align any PHP version constant and readme.txt stable tag (if present) to that same version.

- PATCH: fixes without intentional compatibility changes.
- MINOR: backward-compatible features.
- MAJOR: incompatible changes or migrations.

Release tag: `v1.2.3`. CI verifies that the tag matches the main PHP plugin header before publishing.

## CI/CD decision

**GitHub Actions, with manual approval by tag, and GitHub Releases as the distribution channel. No automatic deployment to a production WordPress site.**

- Pull requests and pushes to `main`: PHP syntax check and repository safety checks.
- Tags matching `v*`: validate, package, publish an installation-ready ZIP as a GitHub Release asset.
- No release is possible until `rooted-roaming-form-entries.php` exists and contains a valid plugin header.
- If the existing plugin's entry file has a different name, adjust the packaging/validation scripts after import.

## Privacy and credentials

Do not commit form submission exports, database backups, actual entries, credentials, `.env` files, or uploads. `.gitignore` is a guardrail, not a security boundary: files that were previously committed remain tracked. Check `git status` and staged diffs before pushing. If sensitive data was committed, rotate compromised secrets and remove it from Git history using an appropriate remediation process.

The release packaging script explicitly selects allowed plugin paths and excludes development and sensitive files. Review the allowlist when adding runtime assets or dependencies.

## Packaging

Run `bash scripts/package.sh 1.2.3` after the plugin source is present. The ZIP will be written to `dist/`. The script requires the main plugin PHP file with matching header version and fails rather than emitting an incomplete ZIP.

## Release checklist

1. Review/test the plugin locally with a backed-up WordPress installation.
2. Update the plugin header version; document any migrations or compatibility changes.
3. Merge reviewed PR into `main`; confirm CI passed.
4. Create and push an annotated `vX.Y.Z` tag **pointing to the reviewed main commit**.
5. Verify GitHub Actions completed and download/test the GitHub Release ZIP on staging before production installation.
