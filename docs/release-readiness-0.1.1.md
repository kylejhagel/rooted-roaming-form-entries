# Release-readiness review — 0.1.1

Reviewed against the source imported in commit `85ca5f1`. This document is a code-review checklist, **not** a claim that integration or release tests have passed.

## Verified by source inspection

- Main plugin entry file is `rooted-roaming-form-entries.php`; its header and `RRFE_VERSION` constant both say `0.1.1`.
- Plugin requires PHP 7.4 and registers a Contact Form 7 hook for the configured form ID.
- Admin subscribers page, export, and delete endpoints require `manage_options`; export/delete additionally require nonces.
- Exported CSV values are prefixed when formula-like. Plugin integrates with WordPress's privacy exporter and eraser.
- The release package allowlist includes the plugin entry file, `includes/`, and `assets/`.

## Remaining release gates

1. **Functional test with WordPress + Contact Form 7.** Submit successful, invalid, duplicate, and spam-flagged registrations on the configured form; check record counts and displayed outcomes.
2. **Failure handling:** `capture()` may return without setting `$abort` if data is invalid or a database insert fails. Verify that CF7 does not report a successful subscription when persistence fails; implement a controlled error path if necessary.
3. **Consent:** confirm actual CF7 form wording requires explicit affirmative consent and that the consent version matches the published disclosure. Capture is single opt-in, not confirmed double opt-in.
4. **Privacy:** establish a documented subscriber retention/deletion policy and test WordPress personal-data export and erasure. Consider whether deleting subscriber records entirely conflicts with keeping an opt-out suppression list.
5. **Database:** test activation with both a fresh site and the existing site, including the unique email-key constraint, UTF8MB4 table generation, and data preservation across upgrades.
6. **Distribution:** run CI, inspect the ZIP, install and activate on staging, and verify all runtime assets load and settings survive an upgrade.
7. **Docs:** reconcile `README-local-backup.md` from the local machine; it is not committed and cannot be reviewed from GitHub.
8. **Workflow permissions:** verify repository Actions settings permit release creation with `GITHUB_TOKEN`. Do not tag a release until CI and staging smoke tests pass.

## Release procedure

Merge release-readiness PR after review and a passing CI run; test installation ZIP on staging. Tag the reviewed `main` commit with `v0.1.1` and confirm GitHub Releases publishes `rooted-roaming-form-entries-0.1.1.zip`.

## CI scope

CI validates PHP syntax against 7.4 / 8.2 / 8.3 and verifies archive structure. It does **not** provide WordPress integration tests, CF7 behavior tests, or proof of production safety.
