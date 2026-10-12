# Automatic update regressions

Run from the repository root:

```sh
node tests/updater.js
php tests/metadata_symlinks.php
node tests/keyboard_navigation.js
```

The updater suite requires Node.js 18+, OpenSSL CLI, and PHP 7.4+ with
tokenizer, openssl, cURL and allow_url_fopen. Set PHP_BINARY and, optionally,
PHP_INI to select a PHP installation. The browser suite additionally uses
Playwright/Chromium; these are development dependencies only.

The updater tests create disposable application copies, sessions, download
directories and a local HTTPS server with an explicitly trusted test certificate.
They exercise both cURL and PHP streams, real authentication/CSRF handling,
3 MiB installation, the exact 16 MiB boundary, over-limit transfers, chunked and
lengthless responses, truncation, invalid source/syntax/version definitions,
version/hash changes, TLS hostname verification, redirects, disk write failures,
atomic rename failures, verified rollback, retained recovery files, concurrency,
external edits and insufficient memory. Namespaced filesystem fault functions
are confined to test copies; the production application has no test hooks.

## Release and hosting requirements

- Deploy only webcommander.php. Embedded browser assets remain in that file;
  the test files are never required by the deployed application.
- v1.11 raises the download cap from 2 MiB to 16 MiB. It must itself stay below
  the legacy 2 MiB cap so v1.10 installations can receive this transition.
  Installations that skip this transition and later encounter a release above
  2 MiB will need a manual update.
- Keep the final line `<!-- WebCommander update end -->`, including its newline,
  in future release files. Releases newer than 1.10 require it. Older sources
  can still be checked, but cannot be installed as a downgrade.
- Downloads use a disk-backed temporary stream and bounded writes. cURL retains
  its 8-second connection timeout and allows 120 seconds for the transfer.
  Streams have a 20-second read timeout and a 120-second transfer deadline.
  HTTP redirects, partial responses and compressed/ambiguous encodings fail
  closed. HTTPS certificate and hostname verification remain enabled.
- Syntax validation still needs the source and PHP tokens in memory. Before
  reading the source, the updater requires available memory of at least eight
  times the release size plus 32 MiB. A 16 MiB release therefore requires
  160 MiB of headroom beyond current request usage; a 256 MiB memory_limit is a
  practical starting point for such embedded-asset releases. Parser/token
  density and PHP version affect actual memory use; PHP's configured limit
  remains in force. Insufficient headroom gives an actionable error. Validation
  completes before any live-file replacement, and the old source is never
  buffered alongside the update for backup or restoration.
- Allow temporary disk space for the download, a staged release, the old source
  backup and (if recovery is needed) a staged copy of the old source. Staging and
  recovery files use exclusive creation, random names and private permissions,
  and reside on the application's filesystem for atomic renames.
- The updater keeps a protected application-specific lock file. Its advisory
  lock is released when the request ends; the empty file is reused rather than
  unlinked, so another request cannot acquire a different inode's lock.
- Installation requires the checked version and SHA-256. The downloaded file,
  staged file and installed file are verified. SHA-256 binds the check to the
  installation; authenticity continues to rely on the fixed official HTTPS
  source and TLS validation.
- Failed replacement leaves the live file intact. Failed post-install
  verification attempts an atomic restoration and verifies the restored hash.
  If recovery cannot be verified, the private backup is kept and the
  authenticated error gives its full path for manual recovery. The updater
  never reports restoration merely because it attempted to write old bytes.
  A process termination after replacement can also leave this verified backup;
  the installed file was already fully staged and validated before the rename.
