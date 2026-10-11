<?php
declare(strict_types=1);

/*
 * Run: php tests/metadata_symlinks.php [path/to/webcommander.php]
 * Requires PHP 7.4+ CLI with proc_open, local sockets and symbolic link support.
 * Exercises the real authenticated HTTP actions in an isolated temporary root.
 * Enable fileinfo to also exercise regular-file MIME detection.
 */

function test_check(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function test_remove_tree(string $path): void {
  if (!is_link($path) && is_dir($path)) {
    foreach (scandir($path) as $name) {
      if ($name !== '.' && $name !== '..') {
        test_remove_tree($path . DIRECTORY_SEPARATOR . $name);
      }
    }
    rmdir($path);
  } elseif (is_link($path) || file_exists($path)) {
    unlink($path);
  }
}

function test_snapshot(string $path): array {
  clearstatcache(true);
  $stat = lstat($path);
  test_check($stat !== false, 'Cannot snapshot ' . $path);
  return array_intersect_key($stat, array_flip([
    'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'atime', 'mtime', 'ctime'
  ]));
}

function test_http(string $url, array $data, string &$cookie): array {
  $headers = "User-Agent: WebCommanderMetadataRegression/1.0\r\n";
  if ($cookie !== '') {
    $headers .= 'Cookie: ' . $cookie . "\r\n";
  }
  $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
  $context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => $headers,
    'content' => http_build_query($data),
    'ignore_errors' => true,
    'timeout' => 5
  ]]);
  $body = file_get_contents($url, false, $context);
  test_check($body !== false, 'HTTP request failed.');
  $status = 0;
  foreach ($http_response_header as $header) {
    if (preg_match('/^HTTP\/\S+ (\d+)/', $header, $match)) {
      $status = (int)$match[1];
    } elseif (preg_match('/^Set-Cookie:\s*(WEBCOMMANDERSESSID=[^;]+)/i', $header, $match)) {
      $cookie = $match[1];
    }
  }
  return [$status, $body];
}

function test_api(string $action, array $data = []): array {
  global $url, $cookie, $csrf;
  [$status, $body] = test_http($url . '?action=' . rawurlencode($action), $data + ['csrf' => $csrf], $cookie);
  $result = json_decode($body, true);
  test_check(is_array($result) && isset($result['success']), 'Invalid JSON response for ' . $action);
  return [$status, $result];
}

function test_case(string $name, callable $callback): void {
  global $passed, $failed;
  try {
    $callback();
    $passed++;
    echo 'PASS ' . $name . "\n";
  } catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n");
  }
}

$passed = 0;
$failed = 0;
$process = null;
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webcommander-metadata-' . bin2hex(random_bytes(8));

try {
  test_check(function_exists('proc_open') && function_exists('symlink'), 'proc_open and symlink are required.');
  $source = $argv[1] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'webcommander.php';
  test_check(is_file($source), 'WebCommander source not found.');
  $root = $base . '/root';
  // The shared root prefix also tests that confinement requires a path separator.
  $outside = $base . '/root-outside';
  mkdir($base, 0700);
  mkdir($root);
  mkdir($outside);
  mkdir($base . '/sessions', 0700);
  mkdir($root . '/fixtures');
  mkdir($root . '/fixtures/internal-directory');
  copy($source, $root . '/webcommander.php');
  $insideContent = "ordinary in-root file\n";
  $nestedContent = "nested in-root file\n";
  $externalContent = str_repeat('external content must never be read or changed ', 100);
  file_put_contents($root . '/fixtures/regular.txt', $insideContent);
  file_put_contents($root . '/fixtures/internal-directory/nested.txt', $nestedContent);
  file_put_contents($outside . '/target.txt', $externalContent);
  chmod($outside . '/target.txt', 0644);
  touch($outside . '/target.txt', 978307200, 946684800);
  $links = [
    'external-file' => $outside . '/target.txt',
    'external-directory' => $outside,
    'relative-external' => '../../root-outside/target.txt',
    'chain' => 'external-file',
    'broken' => $outside . '/missing.txt',
    'internal-file' => $root . '/fixtures/regular.txt',
    'internal-dir' => $root . '/fixtures/internal-directory',
    'protected-file' => $root . '/webcommander.php'
  ];
  foreach ($links as $name => $target) {
    test_check(symlink($target, $root . '/fixtures/' . $name), 'Cannot create fixture symlink.');
  }
  symlink($root . '/fixtures', $root . '/alias');

  $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
  test_check($socket !== false, 'Cannot reserve a localhost port: ' . $error);
  $address = stream_socket_get_name($socket, false);
  fclose($socket);
  $url = 'http://' . $address . '/webcommander.php';
  $command = [PHP_BINARY];
  if (php_ini_loaded_file() !== false) {
    $command[] = '-c';
    $command[] = php_ini_loaded_file();
  }
  array_push($command, '-d', 'session.save_path=' . $base . '/sessions', '-S', $address, '-t', $root);
  $process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['file', $base . '/server.log', 'a'],
    2 => ['file', $base . '/server.log', 'a']
  ], $pipes);
  test_check(is_resource($process), 'Cannot start the PHP test server.');
  fclose($pipes[0]);
  $ready = false;
  for ($attempt = 0; $attempt < 100; $attempt++) {
    $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
    if ($connection !== false) {
      fclose($connection);
      $ready = true;
      break;
    }
    test_check(proc_get_status($process)['running'], 'PHP server exited: ' . file_get_contents($base . '/server.log'));
    usleep(50000);
  }
  test_check($ready, 'PHP server did not start.');
  $cookie = '';
  $password = bin2hex(random_bytes(16));
  [$status, $html] = test_http($url, [
    'auth_action' => 'setup', 'password' => $password, 'confirm_password' => $password
  ], $cookie);
  test_check($status === 200 && preg_match('/csrf:\s*"([a-f0-9]{64})"/', $html, $match) === 1, 'Setup did not produce an authenticated CSRF token.');
  $csrf = $match[1];
  $externalBefore = test_snapshot($outside . '/target.txt');
  $directoryBefore = test_snapshot($outside);

  foreach ($links as $name => $target) {
    test_case('Properties reports link metadata: ' . $name, function () use ($root, $outside, $name, $target): void {
      $linkStat = test_snapshot($root . '/fixtures/' . $name);
      $targetBefore = test_snapshot($outside . '/target.txt');
      [$status, $result] = test_api('properties', ['paths' => ['fixtures/' . $name]]);
      test_check($status === 200 && $result['success'], 'Properties failed.');
      test_check(test_snapshot($outside . '/target.txt') === $targetBefore, 'Properties read or changed the external target.');
      $info = $result['details'][0];
      test_check($info['type'] === 'link' && $info['mime'] === 'inode/symlink', 'Properties followed the target type or MIME.');
      test_check($info['size'] === $linkStat['size'] && $info['calculatedSize'] === $linkStat['size'], 'Properties reported target size instead of lstat size.');
      test_check($info['mtime'] === $linkStat['mtime'] && $info['ctime'] === $linkStat['ctime'], 'Properties reported target timestamps.');
      test_check($info['uid'] === $linkStat['uid'] && $info['gid'] === $linkStat['gid'], 'Properties reported target ownership.');
      test_check($info['mode'] === substr(sprintf('%o', $linkStat['mode']), -4), 'Properties reported target permissions.');
      test_check($info['linkTarget'] === $target && $info['itemCount'] === 1, 'Link text or item count is incorrect.');
      test_check($result['totalSize'] === $linkStat['size'] && $result['totalItems'] === 1, 'Properties totals do not use link metadata.');
      test_check($info['navigable'] === ($name === 'internal-dir'), 'Unsafe link navigation or lost in-root navigation.');
      if (in_array($name, ['external-file', 'external-directory', 'relative-external', 'chain', 'broken'], true)) {
        test_check(!$info['readable'] && !$info['writable'], 'External target access flags were exposed.');
      }
    });
  }

  test_case('Regular file and directory Properties remain correct', function () use ($insideContent, $nestedContent): void {
    [$status, $result] = test_api('properties', ['paths' => ['fixtures/regular.txt', 'fixtures/internal-directory']]);
    test_check($status === 200 && $result['success'], 'Properties failed for regular items.');
    test_check($result['details'][0]['calculatedSize'] === strlen($insideContent), 'Regular file size changed.');
    test_check($result['details'][1]['calculatedSize'] === strlen($nestedContent), 'Directory size changed.');
    $expectedMime = extension_loaded('fileinfo') ? 'text/plain' : 'application/octet-stream';
    test_check($result['details'][0]['mime'] === $expectedMime, 'Regular file MIME detection changed.');
  });

  foreach (['external-file', 'external-directory', 'relative-external', 'chain', 'broken'] as $name) {
    test_case('Touch rejects outside or broken target: ' . $name, function () use ($outside, $name): void {
      $targetBefore = test_snapshot($outside . '/target.txt');
      $directoryBefore = test_snapshot($outside);
      [$status, $result] = test_api('touch', ['paths' => ['fixtures/' . $name], 'time' => '2005-01-01 00:00:00 UTC']);
      test_check(test_snapshot($outside . '/target.txt') === $targetBefore, 'Touch changed the external file.');
      test_check(test_snapshot($outside) === $directoryBefore, 'Touch changed the external directory.');
      test_check(!file_exists($outside . '/missing.txt'), 'Touch created a broken link target outside the root.');
      test_check($status === 400 && !$result['success'], 'Touch did not reject the unsafe target.');
    });
  }

  test_case('Touch supports regular files, in-root links and parent aliases', function () use ($root): void {
    foreach (['fixtures/regular.txt', 'fixtures/internal-file', 'alias/regular.txt'] as $index => $path) {
      $timestamp = 1104537600 + $index * 60;
      [$status, $result] = test_api('touch', ['paths' => [$path], 'time' => gmdate('c', $timestamp)]);
      test_check($status === 200 && $result['success'] && $result['count'] === 1, 'Touch failed inside the root.');
      test_check(test_snapshot($root . '/fixtures/regular.txt')['mtime'] === $timestamp, 'In-root timestamp did not change.');
    }
  });

  test_case('Touch rejects a link to a protected application file', function () use ($root): void {
    $before = test_snapshot($root . '/webcommander.php');
    [$status, $result] = test_api('touch', ['paths' => ['fixtures/protected-file'], 'time' => '2005-01-01 UTC']);
    test_check($status === 400 && !$result['success'], 'Protected target alias was accepted.');
    test_check(test_snapshot($root . '/webcommander.php') === $before, 'Protected target changed.');
  });

  test_case('Metadata actions reject an external symlink in the parent path', function () use ($outside): void {
    $before = test_snapshot($outside . '/target.txt');
    foreach (['properties', 'permission_info', 'touch', 'checksum'] as $action) {
      [$status, $result] = test_api($action, ['paths' => ['fixtures/external-directory/target.txt']]);
      test_check($status === 400 && !$result['success'], $action . ' followed an external parent.');
    }
    test_check(test_snapshot($outside . '/target.txt') === $before, 'An external parent target changed.');
  });

  test_case('Permission info reports link metadata without external access flags', function (): void {
    [$status, $result] = test_api('permission_info', ['paths' => ['fixtures/external-file']]);
    $info = $result['details'][0] ?? [];
    test_check($status === 200 && $result['success'] && $info['type'] === 'link', 'Permission info failed.');
    test_check(!$info['readable'] && !$info['writable'], 'Permission info probed external target access.');
  });

  test_case('Folder summaries and content search skip link targets', function () use ($outside, $insideContent, $nestedContent): void {
    $before = test_snapshot($outside . '/target.txt');
    $expectedSize = strlen($insideContent) + strlen($nestedContent);
    [$status, $result] = test_api('properties', ['paths' => ['fixtures']]);
    test_check($status === 200 && $result['totalSize'] === $expectedSize, 'Folder size includes link targets.');
    [$status, $result] = test_api('tree', ['path' => 'fixtures']);
    test_check($status === 200 && $result['tree']['size'] === $expectedSize, 'Tree size includes link targets.');
    [$status, $result] = test_api('search', ['base' => 'fixtures', 'content' => 'external content must never']);
    test_check($status === 200 && $result['results'] === [], 'Content search read an external target.');
    test_check(test_snapshot($outside . '/target.txt') === $before, 'A folder summary or search read or changed the external target.');
  });

  test_case('Checksums reject external targets and preserve in-root links', function () use ($outside, $insideContent): void {
    $before = test_snapshot($outside . '/target.txt');
    [$status, $result] = test_api('checksum', ['paths' => ['fixtures/external-file']]);
    test_check($status === 400 && !$result['success'], 'Checksum accepted an external target.');
    [$status, $result] = test_api('checksum', ['paths' => ['fixtures/internal-file']]);
    test_check($status === 200 && $result['results'][0]['hash'] === hash('sha256', $insideContent), 'In-root checksum changed.');
    test_check(test_snapshot($outside . '/target.txt') === $before, 'Checksum read or changed the external target.');
  });

  test_case('Recursive chmod skips symlink targets', function () use ($outside): void {
    $before = test_snapshot($outside . '/target.txt');
    $directoryBefore = test_snapshot($outside);
    [$status, $result] = test_api('chmod_chown', ['paths' => ['fixtures'], 'mode' => '0700', 'recursive' => 1]);
    test_check($status === 200 && $result['success'], 'Recursive chmod failed.');
    test_check(test_snapshot($outside . '/target.txt') === $before, 'Recursive chmod changed the external file.');
    test_check(test_snapshot($outside) === $directoryBefore, 'Recursive chmod changed the external directory.');
  });

  if (function_exists('lchown') && function_exists('lchgrp')) {
    test_case('Ownership changes apply to the link rather than its external target', function () use ($outside): void {
      $before = test_snapshot($outside . '/target.txt');
      [$status, $result] = test_api('chmod_chown', [
        'paths' => ['fixtures/external-file'], 'mode' => '0600', 'owner' => (string)$before['uid'], 'group' => (string)$before['gid']
      ]);
      test_check($status === 200 && $result['success'], 'Link ownership change failed.');
      test_check(test_snapshot($outside . '/target.txt') === $before, 'Link ownership action changed the external target.');
    });
  }

  test_case('External target remains untouched across all metadata actions', function () use ($outside, $externalBefore, $directoryBefore, $externalContent): void {
    test_check(test_snapshot($outside . '/target.txt') === $externalBefore, 'External file metadata changed during the suite.');
    test_check(test_snapshot($outside) === $directoryBefore, 'External directory metadata changed during the suite.');
    test_check(!file_exists($outside . '/missing.txt'), 'A missing external target was created.');
    test_check(file_get_contents($outside . '/target.txt') === $externalContent, 'External file contents changed.');
  });

  test_case('HTTP actions produce no PHP warnings', function () use ($base): void {
    $log = file_get_contents($base . '/server.log');
    test_check(preg_match('/(?:PHP )?(?:Warning|Notice|Deprecated|Fatal error):/', $log) !== 1, 'PHP warning or error in server log: ' . $log);
  });
} catch (Throwable $error) {
  $failed++;
  fwrite(STDERR, 'FAIL test setup: ' . $error->getMessage() . "\n");
} finally {
  if (is_resource($process)) {
    proc_terminate($process);
    proc_close($process);
  }
  if (is_dir($base)) {
    test_remove_tree($base);
  }
}

echo $passed . ' passed, ' . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);
