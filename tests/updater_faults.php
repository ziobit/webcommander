<?php
declare(strict_types=1);

/*
 * Test-only filesystem faults, loaded by isolated copies in updater.js.
 * Namespace fallback intercepts native calls without any production test hooks.
 */
namespace WebCommanderUpdaterRegression;

function updater_fault(): string {
  $data = \json_decode((string)\file_get_contents(__DIR__ . '/fault.json'), true);
  return (string)($data['fault'] ?? '');
}

function updater_target(): string {
  return __DIR__ . '/webcommander.php';
}

function rename(string $from, string $to): bool {
  if ($to === updater_target()) {
    $attempt = ($GLOBALS['updater_renames'] ?? 0) + 1;
    $GLOBALS['updater_renames'] = $attempt;
    if (updater_fault() === 'replace-fail' || (updater_fault() === 'rollback-fail' && $attempt > 1)) {
      return false;
    }
  }
  return \rename($from, $to);
}

function hash_file(string $algorithm, string $path, bool $binary = false) {
  $fault = updater_fault();
  if ($path === updater_target() && ($GLOBALS['updater_renames'] ?? 0) > 0 &&
      \in_array($fault, ['verify-fail', 'rollback-fail', 'rollback-verify-fail'], true)) {
    $checks = ($GLOBALS['updater_post_checks'] ?? 0) + 1;
    $GLOBALS['updater_post_checks'] = $checks;
    if ($checks === 1 || $fault === 'rollback-verify-fail') {
      return false;
    }
  }
  if ($fault === 'stage-hash-fail' && \substr($path, -4) === '.tmp') {
    return false;
  }
  return \hash_file($algorithm, $path, $binary);
}

function stream_copy_to_stream($from, $to, ?int $length = null, int $offset = 0) {
  $metadata = \stream_get_meta_data($from);
  $fault = updater_fault();
  if (($fault === 'stage-short' && $metadata['uri'] !== updater_target()) ||
      ($fault === 'backup-short' && $metadata['uri'] === updater_target())) {
    return \stream_copy_to_stream($from, $to, 100);
  }
  $copied = $length === null ? \stream_copy_to_stream($from, $to) : \stream_copy_to_stream($from, $to, $length, $offset);
  if ($fault === 'current-changed' && $metadata['uri'] === updater_target()) {
    \file_put_contents(updater_target(), "\n<!-- concurrent edit -->\n", FILE_APPEND);
  }
  return $copied;
}

function fwrite($file, string $data, ?int $length = null) {
  if (updater_fault() === 'download-write-fail') {
    return false;
  }
  // Exercise the production write loop, rather than assuming one full fwrite().
  if (updater_fault() === 'download-short-writes') {
    return \fwrite($file, $data, \min(97, \strlen($data)));
  }
  return $length === null ? \fwrite($file, $data) : \fwrite($file, $data, $length);
}
