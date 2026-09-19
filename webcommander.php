<?php
declare(strict_types=1);

/*
 * WebCommander - single-file, two-pane file manager for PHP 7.4+
 *
 * Change MC_ROOT below if you want to confine the manager to another folder.
 * The web-server user must have the appropriate filesystem permissions.
 */

define('MC_ROOT', __DIR__);
define('MC_VERSION', '1.3'); // Increment this for every published update.
define('MC_UPDATE_URL', 'https://raw.githubusercontent.com/ziobit/webcommander/main/webcommander.php');
define('MC_UPDATE_MAX_BYTES', 2 * 1024 * 1024);
define('MC_MAX_TREE_ITEMS', 200000);
define('MC_MAX_TREE_FOLDERS', 10000);
define('MC_MAX_TREE_DEPTH', 64);
define('MC_SESSION_TIMEOUT', 1800);
define('MC_MAX_EDIT_BYTES', 5 * 1024 * 1024);
define('MC_MAX_SEARCH_RESULTS', 500);
define('MC_MAX_ARCHIVE_ENTRIES', 10000);
define('MC_MAX_ARCHIVE_BYTES', 1024 * 1024 * 1024);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
session_name('WEBCOMMANDERSESSID');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain' => '',
  'secure' => $https,
  'httponly' => true,
  'samesite' => 'Strict'
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$rootReal = realpath(MC_ROOT);
if ($rootReal === false || !is_dir($rootReal)) {
  http_response_code(500);
  exit('MC_ROOT is not a valid directory.');
}
define('MC_ROOT_REAL', rtrim($rootReal, DIRECTORY_SEPARATOR));
define('MC_CONFIG_FILE', __DIR__ . DIRECTORY_SEPARATOR . '.webcommander-' . substr(hash('sha256', __FILE__), 0, 12) . '.php');

function mc_h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mc_config_read(): ?array {
  if (!is_file(MC_CONFIG_FILE)) {
    return null;
  }
  $raw = file_get_contents(MC_CONFIG_FILE);
  if ($raw === false) {
    throw new RuntimeException('Cannot read the password configuration file.');
  }
  $pos = strpos($raw, "\n");
  if ($pos === false) {
    throw new RuntimeException('The password configuration file is invalid.');
  }
  $decoded = base64_decode(trim(substr($raw, $pos + 1)), true);
  $config = $decoded === false ? null : json_decode($decoded, true);
  if (!is_array($config) || empty($config['password_hash'])) {
    throw new RuntimeException('The password configuration file is invalid.');
  }
  return $config;
}

function mc_config_write(array $config): void {
  $json = json_encode($config, JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Cannot encode the password configuration.');
  }
  $data = "<?php exit; ?>\n" . base64_encode($json) . "\n";
  $tmp = MC_CONFIG_FILE . '.tmp-' . bin2hex(random_bytes(6));
  $written = file_put_contents($tmp, $data, LOCK_EX);
  if ($written === false || $written !== strlen($data)) {
    if (is_file($tmp)) {
      unlink($tmp);
    }
    throw new RuntimeException('Cannot write the password configuration. Check directory permissions.');
  }
  chmod($tmp, 0600);
  if (!rename($tmp, MC_CONFIG_FILE)) {
    unlink($tmp);
    throw new RuntimeException('Cannot activate the password configuration.');
  }
  chmod(MC_CONFIG_FILE, 0600);
}

function mc_password_validate(string $password): ?string {
  if (strlen($password) < 10) {
    return 'The password must contain at least 10 characters.';
  }
  if (strlen($password) > 1024) {
    return 'The password is too long.';
  }
  return null;
}

function mc_new_csrf(): string {
  $_SESSION['mc_csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['mc_csrf'];
}

function mc_login_session(): void {
  session_regenerate_id(true);
  $_SESSION['mc_logged_in'] = true;
  $_SESSION['mc_last_activity'] = time();
  $_SESSION['mc_user_agent'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  $_SESSION['mc_login_failures'] = 0;
  mc_new_csrf();
}

function mc_is_authenticated(): bool {
  if (empty($_SESSION['mc_logged_in'])) {
    return false;
  }
  $last = (int)($_SESSION['mc_last_activity'] ?? 0);
  $agent = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  if ($last < time() - MC_SESSION_TIMEOUT || !hash_equals((string)($_SESSION['mc_user_agent'] ?? ''), $agent)) {
    $_SESSION = [];
    session_destroy();
    return false;
  }
  $_SESSION['mc_last_activity'] = time();
  return true;
}

function mc_json(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function mc_ok(array $data = []): void {
  mc_json(array_merge(['success' => true], $data));
}

function mc_fail(string $message, int $status = 400, array $extra = []): void {
  mc_json(array_merge(['success' => false, 'message' => $message], $extra), $status);
}

function mc_request_data(): array {
  $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  if (strpos($type, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    return is_array($data) ? $data : [];
  }
  return $_POST;
}

function mc_require_csrf(array $data): void {
  $provided = (string)($data['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  $expected = (string)($_SESSION['mc_csrf'] ?? '');
  if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    mc_fail('The security token is invalid or expired. Reload the page.', 403);
  }
}

function mc_update_download(): string {
  $url = MC_UPDATE_URL . '?cache=' . rawurlencode((string)time());
  $body = false;
  $status = 0;

  if (function_exists('curl_init')) {
    $curl = curl_init($url);
    if ($curl === false) {
      throw new RuntimeException('Cannot initialize the update connection.');
    }
    curl_setopt_array($curl, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_USERAGENT => 'WebCommander/' . MC_VERSION,
      CURLOPT_HTTPHEADER => ['Accept: text/plain', 'Cache-Control: no-cache']
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) {
      throw new RuntimeException('Cannot download the update' . ($error !== '' ? ': ' . $error : '.'));
    }
  } else {
    $context = stream_context_create([
      'http' => [
        'method' => 'GET',
        'timeout' => 20,
        'ignore_errors' => true,
        'header' => "User-Agent: WebCommander/" . MC_VERSION . "\r\nAccept: text/plain\r\nCache-Control: no-cache\r\n"
      ],
      'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true
      ]
    ]);
    $body = @file_get_contents($url, false, $context, 0, MC_UPDATE_MAX_BYTES + 1);
    foreach (($http_response_header ?? []) as $header) {
      if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches)) {
        $status = (int)$matches[1];
      }
    }
    if ($body === false) {
      throw new RuntimeException('Cannot download the update. Enable cURL or allow_url_fopen and permit outbound HTTPS.');
    }
  }

  if ($status < 200 || $status >= 300) {
    throw new RuntimeException('The update server returned HTTP ' . $status . '.');
  }
  if (!is_string($body) || $body === '') {
    throw new RuntimeException('The update server returned an empty file.');
  }
  if (strlen($body) > MC_UPDATE_MAX_BYTES) {
    throw new RuntimeException('The update file exceeds the allowed size.');
  }
  return $body;
}

function mc_update_source_info(string $source): array {
  if (strncmp($source, '<?php', 5) !== 0 || strpos($source, 'WebCommander - single-file') === false) {
    throw new RuntimeException('The downloaded file is not a valid WebCommander source file.');
  }
  $pattern = '/define\(\s*[\'"]MC_VERSION[\'"]\s*,\s*[\'"]([0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z.-]+)?)[\'"]\s*\)\s*;/';
  if (!preg_match($pattern, $source, $matches)) {
    throw new RuntimeException('The downloaded source does not contain a valid version.');
  }
  try {
    token_get_all($source, TOKEN_PARSE);
  } catch (ParseError $e) {
    throw new RuntimeException('The downloaded update failed PHP syntax validation: ' . $e->getMessage());
  }
  return [
    'version' => $matches[1],
    'sha256' => hash('sha256', $source),
    'bytes' => strlen($source)
  ];
}

function mc_update_can_install(): bool {
  return is_file(__FILE__) && is_readable(__FILE__) && is_writable(dirname(__FILE__));
}

function mc_update_install(string $expectedVersion): array {
  if (!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $expectedVersion)) {
    throw new RuntimeException('Invalid expected update version.');
  }
  if (!mc_update_can_install()) {
    throw new RuntimeException('The directory containing WebCommander is not writable by PHP.');
  }

  $source = mc_update_download();
  $info = mc_update_source_info($source);
  if (!hash_equals($expectedVersion, (string)$info['version'])) {
    throw new RuntimeException('The available version changed. Check for updates again.');
  }
  if (!version_compare((string)$info['version'], MC_VERSION, '>')) {
    throw new RuntimeException('No newer version is available.');
  }

  $target = __FILE__;
  $directory = dirname($target);
  $previous = file_get_contents($target);
  if ($previous === false) {
    throw new RuntimeException('Cannot read the current WebCommander source before updating.');
  }

  $temporary = $directory . DIRECTORY_SEPARATOR . '.webcommander-update-' . bin2hex(random_bytes(8)) . '.tmp';
  try {
    $written = file_put_contents($temporary, $source, LOCK_EX);
    if ($written === false || $written !== strlen($source)) {
      throw new RuntimeException('Cannot write the temporary update file.');
    }

    $permissions = fileperms($target);
    if ($permissions !== false) {
      @chmod($temporary, $permissions & 0777);
    }

    $installed = mc_try_fs(function () use ($temporary, $target): bool {
      return rename($temporary, $target);
    });

    if (!$installed) {
      $written = file_put_contents($target, $source, LOCK_EX);
      @unlink($temporary);
      if ($written === false || $written !== strlen($source)) {
        @file_put_contents($target, $previous, LOCK_EX);
        throw new RuntimeException('Cannot replace the current WebCommander source.');
      }
    }

    clearstatcache(true, $target);
    $installedHash = hash_file('sha256', $target);
    if ($installedHash === false || !hash_equals((string)$info['sha256'], $installedHash)) {
      @file_put_contents($target, $previous, LOCK_EX);
      throw new RuntimeException('Update verification failed; the previous source was restored.');
    }
  } catch (Throwable $e) {
    if (is_file($temporary)) {
      @unlink($temporary);
    }
    throw $e;
  }

  if (function_exists('opcache_invalidate')) {
    @opcache_invalidate($target, true);
  }

  return [
    'previousVersion' => MC_VERSION,
    'version' => $info['version'],
    'sha256' => $info['sha256']
  ];
}

function mc_normalize_rel(string $path): string {
  if (strpos($path, "\0") !== false) {
    throw new RuntimeException('Invalid path.');
  }
  $path = str_replace('\\', '/', $path);
  $path = trim($path, '/');
  if ($path === '') {
    return '';
  }
  $clean = [];
  foreach (explode('/', $path) as $part) {
    if ($part === '' || $part === '.') {
      continue;
    }
    if ($part === '..') {
      throw new RuntimeException('Parent-path traversal is not allowed.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $part)) {
      throw new RuntimeException('The path contains control characters.');
    }
    $clean[] = $part;
  }
  return implode('/', $clean);
}

function mc_raw_path(string $rel): string {
  $rel = mc_normalize_rel($rel);
  return $rel === '' ? MC_ROOT_REAL : MC_ROOT_REAL . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

function mc_is_within_root(string $path): bool {
  $root = MC_ROOT_REAL;
  if (DIRECTORY_SEPARATOR === '\\') {
    $path = strtolower($path);
    $root = strtolower($root);
  }
  return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
}

function mc_existing_path(string $rel, bool $follow = true): string {
  $rel = mc_normalize_rel($rel);
  $raw = mc_raw_path($rel);
  if ($rel === '') {
    return MC_ROOT_REAL;
  }
  if (!file_exists($raw) && !is_link($raw)) {
    throw new RuntimeException('Path not found: ' . $rel);
  }
  if ($follow) {
    $real = realpath($raw);
    if ($real === false || !mc_is_within_root($real)) {
      throw new RuntimeException('The path resolves outside the allowed root.');
    }
    return $real;
  }
  $parent = realpath(dirname($raw));
  if ($parent === false || !mc_is_within_root($parent)) {
    throw new RuntimeException('The parent directory is outside the allowed root.');
  }
  return $raw;
}

function mc_destination_path(string $rel): string {
  $rel = mc_normalize_rel($rel);
  if ($rel === '') {
    throw new RuntimeException('The root cannot be used as a new item.');
  }
  $raw = mc_raw_path($rel);
  $parent = realpath(dirname($raw));
  if ($parent === false || !is_dir($parent) || !mc_is_within_root($parent)) {
    throw new RuntimeException('The destination directory is invalid.');
  }
  return $raw;
}

function mc_rel_from_path(string $path): string {
  if (!mc_is_within_root($path)) {
    throw new RuntimeException('Path is outside the allowed root.');
  }
  if ($path === MC_ROOT_REAL) {
    return '';
  }
  return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(MC_ROOT_REAL) + 1));
}

function mc_validate_name(string $name): string {
  if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, '\\') !== false || strpos($name, "\0") !== false) {
    throw new RuntimeException('Invalid file or directory name.');
  }
  if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
    throw new RuntimeException('The name contains control characters.');
  }
  return $name;
}

function mc_join_rel(string $dir, string $name): string {
  $dir = mc_normalize_rel($dir);
  $name = mc_validate_name($name);
  return $dir === '' ? $name : $dir . '/' . $name;
}

function mc_protected_paths(): array {
  $paths = [];
  $script = realpath(__FILE__);
  if ($script !== false) {
    $paths[] = $script;
  }
  $paths[] = MC_CONFIG_FILE;
  return $paths;
}

function mc_is_protected(string $path, bool $includeChildren = false): bool {
  $path = rtrim($path, DIRECTORY_SEPARATOR);
  foreach (mc_protected_paths() as $protected) {
    $protected = rtrim($protected, DIRECTORY_SEPARATOR);
    if ($path === $protected) {
      return true;
    }
    if ($includeChildren && is_dir($path) && strpos($protected, $path . DIRECTORY_SEPARATOR) === 0) {
      return true;
    }
  }
  return false;
}

function mc_fs(string $message, callable $callback) {
  $error = null;
  set_error_handler(function (int $severity, string $text) use (&$error): bool {
    $error = $text;
    return true;
  });
  try {
    $result = $callback();
  } finally {
    restore_error_handler();
  }
  if ($result === false) {
    throw new RuntimeException($message . ($error ? ': ' . $error : ''));
  }
  return $result;
}

function mc_try_fs(callable $callback): bool {
  set_error_handler(function (): bool { return true; });
  try {
    return $callback() !== false;
  } finally {
    restore_error_handler();
  }
}

function mc_file_type(string $path): string {
  if (is_link($path)) {
    return 'link';
  }
  if (is_dir($path)) {
    return 'dir';
  }
  if (is_file($path)) {
    return 'file';
  }
  return 'other';
}

function mc_permissions(int $perms): string {
  $type = (($perms & 0xC000) === 0xC000) ? 's' : ((($perms & 0xA000) === 0xA000) ? 'l' : ((($perms & 0x8000) === 0x8000) ? '-' : ((($perms & 0x6000) === 0x6000) ? 'b' : ((($perms & 0x4000) === 0x4000) ? 'd' : ((($perms & 0x2000) === 0x2000) ? 'c' : ((($perms & 0x1000) === 0x1000) ? 'p' : 'u'))))));
  $map = [0x0100 => 'r', 0x0080 => 'w', 0x0040 => 'x', 0x0020 => 'r', 0x0010 => 'w', 0x0008 => 'x', 0x0004 => 'r', 0x0002 => 'w', 0x0001 => 'x'];
  foreach ($map as $bit => $char) {
    $type .= ($perms & $bit) ? $char : '-';
  }
  return $type;
}

function mc_owner_name(int $uid): string {
  if (function_exists('posix_getpwuid')) {
    $info = posix_getpwuid($uid);
    if (is_array($info) && isset($info['name'])) {
      return (string)$info['name'];
    }
  }
  return (string)$uid;
}

function mc_group_name(int $gid): string {
  if (function_exists('posix_getgrgid')) {
    $info = posix_getgrgid($gid);
    if (is_array($info) && isset($info['name'])) {
      return (string)$info['name'];
    }
  }
  return (string)$gid;
}

function mc_mime(string $path): string {
  if (is_dir($path)) {
    return 'inode/directory';
  }
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
      $mime = finfo_file($finfo, $path);
      finfo_close($finfo);
      if (is_string($mime) && $mime !== '') {
        return $mime;
      }
    }
  }
  return 'application/octet-stream';
}

function mc_item_info(string $path, string $name): array {
  $lstat = lstat($path);
  if ($lstat === false) {
    throw new RuntimeException('Cannot read file information for ' . $name);
  }
  $type = mc_file_type($path);
  $target = null;
  $navigable = $type === 'dir';
  if ($type === 'link') {
    $target = readlink($path);
    $real = realpath($path);
    $navigable = $real !== false && mc_is_within_root($real) && is_dir($real);
  }
  return [
    'name' => $name,
    'path' => mc_rel_from_path($path),
    'type' => $type,
    'navigable' => $navigable,
    'size' => $type === 'file' ? (int)$lstat['size'] : null,
    'mtime' => (int)$lstat['mtime'],
    'ctime' => (int)$lstat['ctime'],
    'mode' => substr(sprintf('%o', (int)$lstat['mode']), -4),
    'permissions' => mc_permissions((int)$lstat['mode']),
    'owner' => mc_owner_name((int)$lstat['uid']),
    'group' => mc_group_name((int)$lstat['gid']),
    'uid' => (int)$lstat['uid'],
    'gid' => (int)$lstat['gid'],
    'readable' => is_readable($path),
    'writable' => is_writable($path),
    'protected' => mc_is_protected($path, true),
    'linkTarget' => $target
  ];
}

function mc_list_directory(string $rel): array {
  $rel = mc_normalize_rel($rel);
  $dir = mc_existing_path($rel);
  if (!is_dir($dir)) {
    throw new RuntimeException('The selected path is not a directory.');
  }
  $names = scandir($dir);
  if ($names === false) {
    throw new RuntimeException('Cannot read this directory.');
  }
  $items = [];
  foreach ($names as $name) {
    if ($name === '.' || $name === '..') {
      continue;
    }
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if ($path === MC_CONFIG_FILE) {
      continue;
    }
    try {
      $items[] = mc_item_info($path, $name);
    } catch (Throwable $e) {
      $items[] = [
        'name' => $name,
        'path' => ($rel === '' ? '' : $rel . '/') . $name,
        'type' => 'other',
        'navigable' => false,
        'size' => null,
        'mtime' => 0,
        'mode' => '----',
        'permissions' => '??????????',
        'owner' => '?',
        'group' => '?',
        'readable' => false,
        'writable' => false,
        'protected' => false,
        'linkTarget' => null,
        'error' => $e->getMessage()
      ];
    }
  }
  usort($items, function (array $a, array $b): int {
    $ad = ($a['type'] === 'dir' || !empty($a['navigable'])) ? 0 : 1;
    $bd = ($b['type'] === 'dir' || !empty($b['navigable'])) ? 0 : 1;
    return $ad === $bd ? strnatcasecmp((string)$a['name'], (string)$b['name']) : ($ad <=> $bd);
  });
  $parent = '';
  if ($rel !== '') {
    $pos = strrpos($rel, '/');
    $parent = $pos === false ? '' : substr($rel, 0, $pos);
  }
  return [
    'path' => $rel,
    'parent' => $parent,
    'items' => $items,
    'readable' => is_readable($dir),
    'writable' => is_writable($dir)
  ];
}

function mc_unique_path(string $path): string {
  if (!file_exists($path) && !is_link($path)) {
    return $path;
  }
  $dir = dirname($path);
  $name = basename($path);
  $ext = pathinfo($name, PATHINFO_EXTENSION);
  $stem = $ext === '' ? $name : substr($name, 0, -strlen($ext) - 1);
  for ($i = 1; $i < 10000; $i++) {
    $candidate = $dir . DIRECTORY_SEPARATOR . $stem . ' (' . $i . ')' . ($ext === '' ? '' : '.' . $ext);
    if (!file_exists($candidate) && !is_link($candidate)) {
      return $candidate;
    }
  }
  throw new RuntimeException('Cannot find an unused destination name.');
}

function mc_delete_node(string $path): void {
  if (mc_is_protected($path, true)) {
    throw new RuntimeException('A protected application file cannot be deleted.');
  }
  if (is_link($path) || is_file($path)) {
    mc_fs('Cannot delete ' . basename($path), function () use ($path) { return unlink($path); });
    return;
  }
  if (is_dir($path)) {
    $names = scandir($path);
    if ($names === false) {
      throw new RuntimeException('Cannot read directory before deletion: ' . basename($path));
    }
    foreach ($names as $name) {
      if ($name !== '.' && $name !== '..') {
        mc_delete_node($path . DIRECTORY_SEPARATOR . $name);
      }
    }
    mc_fs('Cannot delete directory ' . basename($path), function () use ($path) { return rmdir($path); });
    return;
  }
  throw new RuntimeException('Unsupported item type: ' . basename($path));
}

function mc_safe_link_target(string $linkPath): string {
  $real = realpath($linkPath);
  if ($real === false || !mc_is_within_root($real)) {
    throw new RuntimeException('An external or broken symbolic link cannot be copied.');
  }
  return $real;
}

function mc_copy_node(string $source, string $destination): void {
  if (is_link($source)) {
    $target = mc_safe_link_target($source);
    $relativeTarget = mc_rel_from_path($target);
    $linkText = mc_raw_path($relativeTarget);
    mc_fs('Cannot create symbolic link', function () use ($linkText, $destination) { return symlink($linkText, $destination); });
    return;
  }
  if (is_file($source)) {
    mc_fs('Cannot copy ' . basename($source), function () use ($source, $destination) { return copy($source, $destination); });
    $mode = fileperms($source);
    if ($mode !== false) {
      mc_try_fs(function () use ($destination, $mode) { return chmod($destination, $mode & 0777); });
    }
    $mtime = filemtime($source);
    if ($mtime !== false) {
      mc_try_fs(function () use ($destination, $mtime) { return touch($destination, $mtime); });
    }
    return;
  }
  if (is_dir($source)) {
    $sourceReal = realpath($source);
    $destinationParent = realpath(dirname($destination));
    if ($sourceReal !== false && $destinationParent !== false && ($destinationParent === $sourceReal || strpos($destinationParent, $sourceReal . DIRECTORY_SEPARATOR) === 0)) {
      throw new RuntimeException('A directory cannot be copied into itself.');
    }
    mc_fs('Cannot create directory ' . basename($destination), function () use ($destination) { return mkdir($destination, 0775); });
    $names = scandir($source);
    if ($names === false) {
      throw new RuntimeException('Cannot read source directory ' . basename($source));
    }
    foreach ($names as $name) {
      if ($name !== '.' && $name !== '..') {
        mc_copy_node($source . DIRECTORY_SEPARATOR . $name, $destination . DIRECTORY_SEPARATOR . $name);
      }
    }
    $mode = fileperms($source);
    if ($mode !== false) {
      mc_try_fs(function () use ($destination, $mode) { return chmod($destination, $mode & 0777); });
    }
    return;
  }
  throw new RuntimeException('Unsupported item type: ' . basename($source));
}

function mc_prepare_collision(string $destination, string $collision): ?string {
  if (!file_exists($destination) && !is_link($destination)) {
    return $destination;
  }
  if ($collision === 'skip') {
    return null;
  }
  if ($collision === 'rename') {
    return mc_unique_path($destination);
  }
  if ($collision === 'overwrite') {
    mc_delete_node($destination);
    return $destination;
  }
  throw new RuntimeException('Invalid collision option.');
}

function mc_transfer(array $paths, string $destinationDirRel, string $operation, string $collision): array {
  $destinationDir = mc_existing_path($destinationDirRel);
  if (!is_dir($destinationDir) || !is_writable($destinationDir)) {
    throw new RuntimeException('The destination directory is not writable.');
  }
  $results = [];
  foreach ($paths as $relValue) {
    $rel = mc_normalize_rel((string)$relValue);
    if ($rel === '') {
      throw new RuntimeException('The root directory cannot be transferred.');
    }
    $source = mc_existing_path($rel, false);
    if ($operation === 'move' && mc_is_protected($source, true)) {
      throw new RuntimeException('A protected application file cannot be moved.');
    }
    $destination = $destinationDir . DIRECTORY_SEPARATOR . basename($source);
    if (rtrim($source, DIRECTORY_SEPARATOR) === rtrim($destination, DIRECTORY_SEPARATOR)) {
      $results[] = ['path' => $rel, 'status' => 'skipped', 'message' => 'Source and destination are the same.'];
      continue;
    }
    $prepared = mc_prepare_collision($destination, $collision);
    if ($prepared === null) {
      $results[] = ['path' => $rel, 'status' => 'skipped', 'message' => 'Destination exists.'];
      continue;
    }
    $destination = $prepared;
    if ($operation === 'move') {
      if (!mc_try_fs(function () use ($source, $destination) { return rename($source, $destination); })) {
        mc_copy_node($source, $destination);
        mc_delete_node($source);
      }
    } else {
      mc_copy_node($source, $destination);
    }
    $results[] = ['path' => $rel, 'status' => 'done', 'destination' => mc_rel_from_path($destination)];
  }
  return $results;
}

function mc_walk(string $path, bool $includeRoot = true): Generator {
  if ($includeRoot && $path !== MC_CONFIG_FILE) {
    yield $path;
  }
  if (is_dir($path) && !is_link($path)) {
    $names = scandir($path);
    if ($names === false) {
      return;
    }
    foreach ($names as $name) {
      if ($name === '.' || $name === '..') {
        continue;
      }
      $child = $path . DIRECTORY_SEPARATOR . $name;
      if ($child === MC_CONFIG_FILE) {
        continue;
      }
      yield $child;
      if (is_dir($child) && !is_link($child)) {
        yield from mc_walk($child, false);
      }
    }
  }
}

function mc_directory_size(string $path, int &$items): int {
  $total = 0;
  foreach (mc_walk($path) as $node) {
    $items++;
    if (is_file($node) && !is_link($node)) {
      $size = filesize($node);
      if ($size !== false) {
        $total += $size;
      }
    }
    if ($items > 200000) {
      throw new RuntimeException('The item count is too large to calculate safely.');
    }
  }
  return $total;
}

function mc_directory_tree_node(
  string $path,
  string $rel,
  bool $includeHidden,
  int $depth,
  int &$scannedItems,
  int &$folderCount,
  int &$fileCount,
  int &$unreadableCount
): array {
  if ($depth > MC_MAX_TREE_DEPTH) {
    throw new RuntimeException('The directory tree is deeper than the safe limit.');
  }

  $folderCount++;
  if ($folderCount > MC_MAX_TREE_FOLDERS) {
    throw new RuntimeException('The tree contains too many folders. Start from a narrower directory.');
  }

  $name = $rel === '' ? '/' : basename(str_replace('/', DIRECTORY_SEPARATOR, $rel));
  $node = [
    'name' => $name,
    'path' => $rel,
    'size' => 0,
    'files' => 0,
    'folders' => 0,
    'unreadable' => false,
    'children' => []
  ];

  if (!is_readable($path)) {
    $node['unreadable'] = true;
    $unreadableCount++;
    return $node;
  }

  $names = scandir($path);
  if ($names === false) {
    $node['unreadable'] = true;
    $unreadableCount++;
    return $node;
  }

  $names = array_values(array_filter($names, function (string $entry) use ($includeHidden): bool {
    if ($entry === '.' || $entry === '..') {
      return false;
    }
    return $includeHidden || $entry === '' || $entry[0] !== '.';
  }));
  usort($names, 'strnatcasecmp');

  foreach ($names as $entry) {
    $child = $path . DIRECTORY_SEPARATOR . $entry;
    if ($child === MC_CONFIG_FILE) {
      continue;
    }

    $scannedItems++;
    if ($scannedItems > MC_MAX_TREE_ITEMS) {
      throw new RuntimeException('The tree contains too many items to calculate safely. Start from a narrower directory.');
    }

    $childRel = $rel === '' ? $entry : $rel . '/' . $entry;
    if (is_dir($child) && !is_link($child)) {
      $childNode = mc_directory_tree_node(
        $child,
        $childRel,
        $includeHidden,
        $depth + 1,
        $scannedItems,
        $folderCount,
        $fileCount,
        $unreadableCount
      );
      $node['children'][] = $childNode;
      $node['size'] += $childNode['size'];
      $node['files'] += $childNode['files'];
      $node['folders'] += 1 + $childNode['folders'];
      continue;
    }

    $fileCount++;
    $node['files']++;
    if (is_file($child) && !is_link($child)) {
      $size = filesize($child);
      if ($size === false) {
        $unreadableCount++;
      } else {
        $node['size'] += (int)$size;
      }
    }
  }

  usort($node['children'], function (array $a, array $b): int {
    $bySize = ((int)$b['size']) <=> ((int)$a['size']);
    return $bySize !== 0 ? $bySize : strnatcasecmp((string)$a['name'], (string)$b['name']);
  });

  return $node;
}

function mc_stream_file(string $path, bool $download): void {
  if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File not found or unreadable.');
  }
  $size = filesize($path);
  if ($size === false) {
    http_response_code(500);
    exit('Cannot determine file size.');
  }
  $mime = mc_mime($path);
  header('Content-Type: ' . $mime);
  header('Accept-Ranges: bytes');
  header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . str_replace(['"', "\r", "\n"], '', basename($path)) . '"; filename*=UTF-8\'\'' . rawurlencode(basename($path)));
  $start = 0;
  $end = max(0, $size - 1);
  $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
  if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
    if ($matches[1] !== '') {
      $start = (int)$matches[1];
    }
    if ($matches[2] !== '') {
      $end = min($end, (int)$matches[2]);
    }
    if ($start > $end || $start >= $size) {
      header('Content-Range: bytes */' . $size);
      http_response_code(416);
      exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
  }
  $length = $size === 0 ? 0 : $end - $start + 1;
  header('Content-Length: ' . $length);
  $handle = fopen($path, 'rb');
  if ($handle === false) {
    http_response_code(500);
    exit('Cannot open file.');
  }
  if ($start > 0) {
    fseek($handle, $start);
  }
  while (!feof($handle) && $length > 0) {
    $chunk = fread($handle, min(1024 * 1024, $length));
    if ($chunk === false || $chunk === '') {
      break;
    }
    echo $chunk;
    $length -= strlen($chunk);
    flush();
  }
  fclose($handle);
  exit;
}

function mc_archive_add_zip(ZipArchive $zip, string $source, string $inside): void {
  if (is_link($source)) {
    $target = mc_safe_link_target($source);
    if (is_file($target)) {
      $zip->addFile($target, $inside);
    }
    return;
  }
  if (is_file($source)) {
    if (!$zip->addFile($source, $inside)) {
      throw new RuntimeException('Cannot add to archive: ' . $inside);
    }
    return;
  }
  if (is_dir($source)) {
    $zip->addEmptyDir(rtrim($inside, '/') . '/');
    $names = scandir($source);
    if ($names === false) {
      throw new RuntimeException('Cannot read directory for archiving: ' . basename($source));
    }
    foreach ($names as $name) {
      if ($name !== '.' && $name !== '..') {
        mc_archive_add_zip($zip, $source . DIRECTORY_SEPARATOR . $name, rtrim($inside, '/') . '/' . $name);
      }
    }
  }
}

function mc_archive_add_tar(PharData $tar, string $source, string $inside): void {
  if (is_link($source)) {
    $source = mc_safe_link_target($source);
  }
  if (is_file($source)) {
    $tar->addFile($source, $inside);
    return;
  }
  if (is_dir($source)) {
    $tar->addEmptyDir($inside);
    $names = scandir($source);
    if ($names === false) {
      throw new RuntimeException('Cannot read directory for archiving: ' . basename($source));
    }
    foreach ($names as $name) {
      if ($name !== '.' && $name !== '..') {
        mc_archive_add_tar($tar, $source . DIRECTORY_SEPARATOR . $name, rtrim($inside, '/') . '/' . $name);
      }
    }
  }
}

function mc_create_archive(array $paths, string $destinationRel, string $collision): string {
  if (count($paths) === 0) {
    throw new RuntimeException('Select at least one item.');
  }
  $destinationRel = mc_normalize_rel($destinationRel);
  $destination = mc_destination_path($destinationRel);
  $prepared = mc_prepare_collision($destination, $collision);
  if ($prepared === null) {
    throw new RuntimeException('The archive already exists.');
  }
  $destination = $prepared;
  $lower = strtolower(basename($destination));
  $sources = [];
  foreach ($paths as $rel) {
    $sources[] = mc_existing_path((string)$rel, false);
  }
  $destinationParent = realpath(dirname($destination));
  foreach ($sources as $source) {
    $sourceReal = realpath($source);
    if (is_dir($source) && $sourceReal !== false && $destinationParent !== false && ($destinationParent === $sourceReal || strpos($destinationParent, $sourceReal . DIRECTORY_SEPARATOR) === 0)) {
      throw new RuntimeException('The archive cannot be created inside a selected source directory.');
    }
  }
  if (substr($lower, -4) === '.zip') {
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException('The PHP Zip extension is not installed.');
    }
    $zip = new ZipArchive();
    $opened = $zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
      throw new RuntimeException('Cannot create the ZIP archive.');
    }
    try {
      foreach ($sources as $source) {
        mc_archive_add_zip($zip, $source, basename($source));
      }
    } finally {
      $zip->close();
    }
  } elseif (substr($lower, -4) === '.tar' || substr($lower, -7) === '.tar.gz' || substr($lower, -4) === '.tgz') {
    if (!class_exists('PharData')) {
      throw new RuntimeException('PharData is not available.');
    }
    $compressed = substr($lower, -7) === '.tar.gz' || substr($lower, -4) === '.tgz';
    $tarPath = $compressed ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webcommander-' . bin2hex(random_bytes(8)) . '.tar' : $destination;
    try {
      $tar = new PharData($tarPath);
      foreach ($sources as $source) {
        mc_archive_add_tar($tar, $source, basename($source));
      }
      if ($compressed) {
        $gz = $tar->compress(Phar::GZ);
        unset($tar);
        $generated = $tarPath . '.gz';
        if (!is_file($generated)) {
          unset($gz);
          throw new RuntimeException('Cannot compress the TAR archive.');
        }
        unset($gz);
        mc_fs('Cannot move the compressed archive', function () use ($generated, $destination) { return rename($generated, $destination); });
      }
    } finally {
      if ($compressed && is_file($tarPath)) {
        unlink($tarPath);
      }
    }
  } else {
    throw new RuntimeException('Use a .zip, .tar, .tar.gz or .tgz archive name.');
  }
  return mc_rel_from_path($destination);
}

function mc_archive_entry_rel(string $entry): string {
  $entry = str_replace('\\', '/', $entry);
  if ($entry === '' || $entry[0] === '/' || preg_match('/^[A-Za-z]:\//', $entry)) {
    throw new RuntimeException('The archive contains an unsafe absolute path.');
  }
  $parts = [];
  foreach (explode('/', $entry) as $part) {
    if ($part === '' || $part === '.') {
      continue;
    }
    if ($part === '..') {
      throw new RuntimeException('The archive contains a parent-path traversal.');
    }
    $parts[] = $part;
  }
  return implode('/', $parts);
}

function mc_extract_output_path(string $base, string $entryRel, string $collision, bool $directory): ?string {
  $path = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryRel);
  $parent = dirname($path);
  if (!is_dir($parent)) {
    mc_fs('Cannot create archive directory', function () use ($parent) { return mkdir($parent, 0775, true); });
  }
  $parentReal = realpath($parent);
  if ($parentReal === false || !mc_is_within_root($parentReal)) {
    throw new RuntimeException('Archive output escaped the allowed root.');
  }
  if ($directory) {
    if (file_exists($path) || is_link($path)) {
      if (is_dir($path) && !is_link($path)) {
        return $path;
      }
      if ($collision !== 'overwrite') {
        throw new RuntimeException('An archive directory conflicts with an existing non-directory: ' . $entryRel);
      }
      mc_delete_node($path);
    }
    if (!is_dir($path)) {
      mc_fs('Cannot create archive directory', function () use ($path) { return mkdir($path, 0775, true); });
    }
    return $path;
  }
  return mc_prepare_collision($path, $collision);
}

function mc_extract_zip(string $archive, string $destination, string $collision): int {
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException('The PHP Zip extension is not installed.');
  }
  $zip = new ZipArchive();
  if ($zip->open($archive) !== true) {
    throw new RuntimeException('Cannot open the ZIP archive.');
  }
  $count = $zip->numFiles;
  if ($count > MC_MAX_ARCHIVE_ENTRIES) {
    $zip->close();
    throw new RuntimeException('The archive contains too many entries.');
  }
  $total = 0;
  for ($i = 0; $i < $count; $i++) {
    $stat = $zip->statIndex($i);
    if (!is_array($stat)) {
      continue;
    }
    $total += (int)($stat['size'] ?? 0);
    if ($total > MC_MAX_ARCHIVE_BYTES) {
      $zip->close();
      throw new RuntimeException('The uncompressed archive is too large.');
    }
    mc_archive_entry_rel((string)$stat['name']);
  }
  $written = 0;
  try {
    for ($i = 0; $i < $count; $i++) {
      $stat = $zip->statIndex($i);
      if (!is_array($stat)) {
        continue;
      }
      $name = (string)$stat['name'];
      $entryRel = mc_archive_entry_rel($name);
      if ($entryRel === '') {
        continue;
      }
      $isDir = substr($name, -1) === '/';
      $output = mc_extract_output_path($destination, $entryRel, $collision, $isDir);
      if ($isDir || $output === null) {
        continue;
      }
      $input = $zip->getStream($name);
      if ($input === false) {
        throw new RuntimeException('Cannot read archive entry: ' . $name);
      }
      $out = fopen($output, 'wb');
      if ($out === false) {
        fclose($input);
        throw new RuntimeException('Cannot write extracted file: ' . $entryRel);
      }
      stream_copy_to_stream($input, $out);
      fclose($input);
      fclose($out);
      $written++;
    }
  } finally {
    $zip->close();
  }
  return $written;
}

function mc_extract_tar(string $archive, string $destination, string $collision): int {
  if (!class_exists('PharData')) {
    throw new RuntimeException('PharData is not available.');
  }
  $tar = new PharData($archive);
  $iterator = new RecursiveIteratorIterator($tar, RecursiveIteratorIterator::SELF_FIRST);
  $written = 0;
  $entries = 0;
  $total = 0;
  $prefix = 'phar://' . str_replace('\\', '/', $archive) . '/';
  foreach ($iterator as $file) {
    $entries++;
    if ($entries > MC_MAX_ARCHIVE_ENTRIES) {
      throw new RuntimeException('The archive contains too many entries.');
    }
    $full = str_replace('\\', '/', (string)$file->getPathName());
    $name = strpos($full, $prefix) === 0 ? substr($full, strlen($prefix)) : $file->getFilename();
    $entryRel = mc_archive_entry_rel($name);
    if ($entryRel === '') {
      continue;
    }
    $isDir = $file->isDir();
    if (!$isDir) {
      $total += (int)$file->getSize();
      if ($total > MC_MAX_ARCHIVE_BYTES) {
        throw new RuntimeException('The uncompressed archive is too large.');
      }
    }
    $output = mc_extract_output_path($destination, $entryRel, $collision, $isDir);
    if ($isDir || $output === null) {
      continue;
    }
    $input = fopen((string)$file->getPathName(), 'rb');
    $out = fopen($output, 'wb');
    if ($input === false || $out === false) {
      if (is_resource($input)) fclose($input);
      if (is_resource($out)) fclose($out);
      throw new RuntimeException('Cannot extract: ' . $entryRel);
    }
    stream_copy_to_stream($input, $out);
    fclose($input);
    fclose($out);
    $written++;
  }
  return $written;
}

function mc_search(string $baseRel, array $options): array {
  $base = mc_existing_path($baseRel);
  if (!is_dir($base)) {
    throw new RuntimeException('Search base is not a directory.');
  }
  $query = (string)($options['query'] ?? '');
  $content = (string)($options['content'] ?? '');
  $case = !empty($options['case']);
  $regex = !empty($options['regex']);
  $includeHidden = !empty($options['hidden']);
  if (strlen($query) > 300 || strlen($content) > 300) {
    throw new RuntimeException('The search text is too long.');
  }
  if ($query === '' && $content === '') {
    throw new RuntimeException('Enter a filename or content search.');
  }
  $match = function (string $haystack, string $needle) use ($case, $regex): bool {
    if ($needle === '') {
      return true;
    }
    if ($regex) {
      $flags = $case ? 'u' : 'iu';
      $result = preg_match('~' . str_replace('~', '\\~', $needle) . '~' . $flags, $haystack);
      if ($result === false) {
        throw new RuntimeException('Invalid regular expression.');
      }
      return $result === 1;
    }
    return $case ? strpos($haystack, $needle) !== false : stripos($haystack, $needle) !== false;
  };
  $results = [];
  $scanned = 0;
  foreach (mc_walk($base, false) as $node) {
    $scanned++;
    if ($scanned > 50000) {
      break;
    }
    $name = basename($node);
    if (!$includeHidden && isset($name[0]) && $name[0] === '.') {
      continue;
    }
    if (!$match($name, $query)) {
      continue;
    }
    $contentMatch = true;
    $snippet = '';
    if ($content !== '') {
      $contentMatch = false;
      if (is_file($node) && !is_link($node)) {
        $size = filesize($node);
        if ($size !== false && $size <= 2 * 1024 * 1024) {
          $text = file_get_contents($node);
          if ($text !== false && strpos(substr($text, 0, 4096), "\0") === false && $match($text, $content)) {
            $contentMatch = true;
            $plain = preg_replace('/\s+/', ' ', $text);
            $plain = $plain === null ? '' : $plain;
            $snippet = function_exists('mb_substr') ? mb_substr($plain, 0, 180) : substr($plain, 0, 180);
          }
        }
      }
    }
    if ($contentMatch) {
      $info = mc_item_info($node, $name);
      $info['snippet'] = $snippet;
      $results[] = $info;
      if (count($results) >= MC_MAX_SEARCH_RESULTS) {
        break;
      }
    }
  }
  return ['results' => $results, 'scanned' => $scanned, 'limited' => count($results) >= MC_MAX_SEARCH_RESULTS || $scanned > 50000];
}

function mc_directory_map(string $base, string $mode): array {
  $map = [];
  $count = 0;
  foreach (mc_walk($base, false) as $node) {
    $count++;
    if ($count > 20000) {
      throw new RuntimeException('Directory comparison is limited to 20,000 items.');
    }
    $key = str_replace(DIRECTORY_SEPARATOR, '/', substr($node, strlen($base) + 1));
    if (is_dir($node) && !is_link($node)) {
      $map[$key] = ['type' => 'dir'];
    } elseif (is_file($node) && !is_link($node)) {
      $size = filesize($node);
      $mtime = filemtime($node);
      $value = ['type' => 'file', 'size' => $size === false ? 0 : $size, 'mtime' => $mtime === false ? 0 : $mtime];
      if ($mode === 'checksum') {
        $value['hash'] = hash_file('sha256', $node);
      }
      $map[$key] = $value;
    } else {
      $map[$key] = ['type' => 'link', 'target' => readlink($node)];
    }
  }
  return $map;
}

function mc_compare_directories(string $leftRel, string $rightRel, string $mode): array {
  $left = mc_existing_path($leftRel);
  $right = mc_existing_path($rightRel);
  if (!is_dir($left) || !is_dir($right)) {
    throw new RuntimeException('Both comparison paths must be directories.');
  }
  $a = mc_directory_map($left, $mode);
  $b = mc_directory_map($right, $mode);
  $onlyLeft = array_values(array_diff(array_keys($a), array_keys($b)));
  $onlyRight = array_values(array_diff(array_keys($b), array_keys($a)));
  $different = [];
  foreach (array_intersect(array_keys($a), array_keys($b)) as $key) {
    $av = $a[$key];
    $bv = $b[$key];
    if ($av['type'] !== $bv['type']) {
      $different[] = $key;
    } elseif ($av['type'] === 'file') {
      if ($mode === 'checksum' ? $av['hash'] !== $bv['hash'] : ($av['size'] !== $bv['size'] || $av['mtime'] !== $bv['mtime'])) {
        $different[] = $key;
      }
    } elseif ($av['type'] === 'link' && $av['target'] !== $bv['target']) {
      $different[] = $key;
    }
  }
  sort($onlyLeft, SORT_NATURAL | SORT_FLAG_CASE);
  sort($onlyRight, SORT_NATURAL | SORT_FLAG_CASE);
  sort($different, SORT_NATURAL | SORT_FLAG_CASE);
  $common = (count($a) + count($b) - count($onlyLeft) - count($onlyRight)) / 2;
  return ['onlyLeft' => $onlyLeft, 'onlyRight' => $onlyRight, 'different' => $different, 'same' => max(0, (int)$common - count($different))];
}

function mc_apply_recursive(string $path, bool $recursive, callable $callback): int {
  $count = 0;
  if ($recursive && is_dir($path) && !is_link($path)) {
    foreach (mc_walk($path) as $node) {
      $callback($node);
      $count++;
    }
  } else {
    $callback($path);
    $count = 1;
  }
  return $count;
}

function mc_download_selection(array $paths): void {
  if (count($paths) === 1) {
    $path = mc_existing_path((string)$paths[0]);
    if (is_file($path)) {
      mc_stream_file($path, true);
    }
  }
  if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('The PHP Zip extension is required for multi-item or directory downloads.');
  }
  $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webcommander-download-' . bin2hex(random_bytes(8)) . '.zip';
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Cannot create the download archive.');
  }
  try {
    foreach ($paths as $rel) {
      $path = mc_existing_path((string)$rel, false);
      mc_archive_add_zip($zip, $path, basename($path));
    }
  } finally {
    $zip->close();
  }
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="webcommander-selection-' . date('Ymd-His') . '.zip"');
  header('Content-Length: ' . filesize($tmp));
  readfile($tmp);
  unlink($tmp);
  exit;
}

function mc_handle_upload(array $data): array {
  $destinationRel = mc_normalize_rel((string)($data['destination'] ?? ''));
  $destination = mc_existing_path($destinationRel);
  if (!is_dir($destination) || !is_writable($destination)) {
    throw new RuntimeException('The upload destination is not writable.');
  }
  $collision = (string)($data['collision'] ?? 'rename');
  $relativePaths = [];
  if (isset($data['relative_paths'])) {
    $decoded = json_decode((string)$data['relative_paths'], true);
    if (is_array($decoded)) {
      $relativePaths = $decoded;
    }
  }
  if (!isset($_FILES['files'])) {
    throw new RuntimeException('No uploaded files were received.');
  }
  $files = $_FILES['files'];
  $names = is_array($files['name']) ? $files['name'] : [$files['name']];
  $tmps = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
  $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
  $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
  $results = [];
  foreach ($names as $i => $originalName) {
    $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
      $results[] = ['name' => (string)$originalName, 'status' => 'error', 'message' => 'Upload error code ' . $error];
      continue;
    }
    $tmp = (string)($tmps[$i] ?? '');
    if (!is_uploaded_file($tmp)) {
      $results[] = ['name' => (string)$originalName, 'status' => 'error', 'message' => 'Invalid upload.'];
      continue;
    }
    $relative = (string)($relativePaths[$i] ?? $originalName);
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $relative)), function ($v) { return $v !== ''; }));
    if (count($parts) === 0) {
      $parts = [(string)$originalName];
    }
    foreach ($parts as &$part) {
      $part = mc_validate_name((string)$part);
    }
    unset($part);
    $name = array_pop($parts);
    $targetDir = $destination;
    foreach ($parts as $part) {
      $targetDir .= DIRECTORY_SEPARATOR . $part;
      if (!is_dir($targetDir)) {
        mc_fs('Cannot create upload directory', function () use ($targetDir) { return mkdir($targetDir, 0775); });
      }
      $real = realpath($targetDir);
      if ($real === false || !mc_is_within_root($real)) {
        throw new RuntimeException('Upload directory escaped the allowed root.');
      }
    }
    $target = $targetDir . DIRECTORY_SEPARATOR . $name;
    $prepared = mc_prepare_collision($target, $collision);
    if ($prepared === null) {
      $results[] = ['name' => $relative, 'status' => 'skipped', 'message' => 'Destination exists.'];
      continue;
    }
    mc_fs('Cannot store uploaded file', function () use ($tmp, $prepared) { return move_uploaded_file($tmp, $prepared); });
    $results[] = ['name' => $relative, 'status' => 'done', 'size' => (int)($sizes[$i] ?? 0)];
  }
  return $results;
}

$setupError = '';
$loginError = '';
$config = null;
try {
  $config = mc_config_read();
} catch (Throwable $e) {
  $setupError = $e->getMessage();
}

if ($config === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['auth_action'] ?? '') === 'setup') {
  try {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $error = mc_password_validate($password);
    if ($error !== null) {
      throw new RuntimeException($error);
    }
    if (!hash_equals($password, $confirm)) {
      throw new RuntimeException('The two passwords do not match.');
    }
    mc_config_write([
      'password_hash' => password_hash($password, PASSWORD_DEFAULT),
      'created_at' => gmdate('c'),
      'updated_at' => gmdate('c')
    ]);
    $config = mc_config_read();
    mc_login_session();
  } catch (Throwable $e) {
    $setupError = $e->getMessage();
  }
}

if ($config !== null && !mc_is_authenticated() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['auth_action'] ?? '') === 'login') {
  $failures = (int)($_SESSION['mc_login_failures'] ?? 0);
  if ($failures > 0) {
    usleep(min(2000000, $failures * 250000));
  }
  $password = (string)($_POST['password'] ?? '');
  if (password_verify($password, (string)$config['password_hash'])) {
    if (password_needs_rehash((string)$config['password_hash'], PASSWORD_DEFAULT)) {
      $config['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
      $config['updated_at'] = gmdate('c');
      mc_config_write($config);
    }
    mc_login_session();
  } else {
    $_SESSION['mc_login_failures'] = $failures + 1;
    $loginError = 'Invalid password.';
  }
}

$authenticated = mc_is_authenticated();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action !== '' && !$authenticated) {
  mc_fail('Authentication required.', 401);
}

if ($authenticated && $action !== '') {
  try {
    if ($action === 'raw' || $action === 'download') {
      $token = (string)($_GET['csrf'] ?? '');
      if ($token === '' || !hash_equals((string)($_SESSION['mc_csrf'] ?? ''), $token)) {
        http_response_code(403);
        exit('Invalid security token.');
      }
      $path = mc_existing_path((string)($_GET['path'] ?? ''));
      mc_stream_file($path, $action === 'download');
    }

    $data = mc_request_data();
    mc_require_csrf($data);

    if ($action === 'logout') {
      $_SESSION = [];
      if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
      }
      session_destroy();
      mc_ok();
    }

    if ($action === 'list') {
      mc_ok(mc_list_directory((string)($data['path'] ?? '')));
    }

    if ($action === 'read_text') {
      $rel = (string)($data['path'] ?? '');
      $path = mc_existing_path($rel);
      if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The file is not readable.');
      }
      $size = filesize($path);
      if ($size === false || $size > MC_MAX_EDIT_BYTES) {
        throw new RuntimeException('Text viewing/editing is limited to ' . MC_MAX_EDIT_BYTES . ' bytes.');
      }
      $content = file_get_contents($path);
      if ($content === false) {
        throw new RuntimeException('Cannot read the file.');
      }
      if (strpos(substr($content, 0, 8192), "\0") !== false) {
        throw new RuntimeException('This appears to be a binary file. Use the preview or download action.');
      }
      mc_ok(['path' => mc_normalize_rel($rel), 'content' => $content, 'size' => strlen($content), 'mime' => mc_mime($path), 'mtime' => filemtime($path)]);
    }

    if ($action === 'save_text') {
      $rel = mc_normalize_rel((string)($data['path'] ?? ''));
      $content = (string)($data['content'] ?? '');
      if (strlen($content) > MC_MAX_EDIT_BYTES) {
        throw new RuntimeException('The edited content is too large.');
      }
      $path = (file_exists(mc_raw_path($rel)) || is_link(mc_raw_path($rel))) ? mc_existing_path($rel, false) : mc_destination_path($rel);
      if (mc_is_protected($path, true)) {
        throw new RuntimeException('A protected application file cannot be edited.');
      }
      if (is_dir($path) || is_link($path)) {
        throw new RuntimeException('Only regular files can be edited.');
      }
      if (!empty($data['backup']) && is_file($path)) {
        $backup = mc_unique_path($path . '.bak');
        mc_fs('Cannot create backup', function () use ($path, $backup) { return copy($path, $backup); });
      }
      $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.tmp-' . bin2hex(random_bytes(5));
      mc_fs('Cannot write temporary file', function () use ($tmp, $content) { return file_put_contents($tmp, $content, LOCK_EX); });
      if (is_file($path)) {
        $mode = fileperms($path);
        if ($mode !== false) chmod($tmp, $mode & 0777);
      }
      mc_fs('Cannot replace the edited file', function () use ($tmp, $path) { return rename($tmp, $path); });
      mc_ok(['path' => mc_rel_from_path($path), 'size' => strlen($content)]);
    }

    if ($action === 'mkdir') {
      $rel = mc_join_rel((string)($data['dir'] ?? ''), (string)($data['name'] ?? ''));
      $path = mc_destination_path($rel);
      if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('An item with that name already exists.');
      }
      $modeText = preg_replace('/[^0-7]/', '', (string)($data['mode'] ?? '0775'));
      $mode = octdec($modeText === '' ? '0775' : $modeText);
      mc_fs('Cannot create directory', function () use ($path, $mode) { return mkdir($path, $mode, !empty($_POST['parents'])); });
      mc_ok(['path' => $rel]);
    }

    if ($action === 'new_file') {
      $rel = mc_join_rel((string)($data['dir'] ?? ''), (string)($data['name'] ?? ''));
      $path = mc_destination_path($rel);
      if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('An item with that name already exists.');
      }
      mc_fs('Cannot create file', function () use ($path) { return file_put_contents($path, '', LOCK_EX); });
      mc_ok(['path' => $rel]);
    }

    if ($action === 'rename') {
      $rel = mc_normalize_rel((string)($data['path'] ?? ''));
      $source = mc_existing_path($rel, false);
      if (mc_is_protected($source, true)) {
        throw new RuntimeException('A protected application file cannot be renamed.');
      }
      $name = mc_validate_name((string)($data['name'] ?? ''));
      $destination = dirname($source) . DIRECTORY_SEPARATOR . $name;
      if (file_exists($destination) || is_link($destination)) {
        throw new RuntimeException('An item with that name already exists.');
      }
      mc_fs('Cannot rename item', function () use ($source, $destination) { return rename($source, $destination); });
      mc_ok(['path' => mc_rel_from_path($destination)]);
    }

    if ($action === 'delete') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      foreach ($paths as $rel) {
        $path = mc_existing_path((string)$rel, false);
        mc_delete_node($path);
      }
      mc_ok(['count' => count($paths)]);
    }

    if ($action === 'transfer') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      $operation = (string)($data['operation'] ?? 'copy');
      if (!in_array($operation, ['copy', 'move'], true)) {
        throw new RuntimeException('Invalid transfer operation.');
      }
      $collision = (string)($data['collision'] ?? 'rename');
      mc_ok(['results' => mc_transfer($paths, (string)($data['destination'] ?? ''), $operation, $collision)]);
    }

    if ($action === 'chmod_chown') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      $modeText = preg_replace('/[^0-7]/', '', (string)($data['mode'] ?? ''));
      $owner = trim((string)($data['owner'] ?? ''));
      $group = trim((string)($data['group'] ?? ''));
      $recursive = !empty($data['recursive']);
      if ($modeText === '' && $owner === '' && $group === '') {
        throw new RuntimeException('Enter permissions, an owner, or a group.');
      }
      $mode = $modeText === '' ? null : octdec($modeText);
      $count = 0;
      foreach ($paths as $rel) {
        $path = mc_existing_path((string)$rel, false);
        if (mc_is_protected($path, true)) {
          throw new RuntimeException('Protected application files cannot be changed.');
        }
        $count += mc_apply_recursive($path, $recursive, function (string $node) use ($mode, $owner, $group): void {
          if ($mode !== null && !is_link($node)) {
            mc_fs('Cannot change permissions for ' . basename($node), function () use ($node, $mode) { return chmod($node, $mode); });
          }
          if ($owner !== '' && function_exists('chown')) {
            mc_fs('Cannot change owner for ' . basename($node), function () use ($node, $owner) { return chown($node, $owner); });
          }
          if ($group !== '' && function_exists('chgrp')) {
            mc_fs('Cannot change group for ' . basename($node), function () use ($node, $group) { return chgrp($node, $group); });
          }
        });
      }
      mc_ok(['count' => $count]);
    }

    if ($action === 'touch') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      $timestamp = strtotime((string)($data['time'] ?? 'now'));
      if ($timestamp === false) {
        throw new RuntimeException('Invalid date/time.');
      }
      foreach ($paths as $rel) {
        $path = mc_existing_path((string)$rel, false);
        if (mc_is_protected($path, true)) {
          throw new RuntimeException('A protected application file cannot be changed.');
        }
        mc_fs('Cannot change timestamp', function () use ($path, $timestamp) { return touch($path, $timestamp); });
      }
      mc_ok(['count' => count($paths), 'timestamp' => $timestamp]);
    }

    if ($action === 'link') {
      $targetRel = mc_normalize_rel((string)($data['target'] ?? ''));
      $target = mc_existing_path($targetRel);
      $dirRel = mc_normalize_rel((string)($data['dir'] ?? ''));
      $name = mc_validate_name((string)($data['name'] ?? ''));
      $linkRel = mc_join_rel($dirRel, $name);
      $linkPath = mc_destination_path($linkRel);
      if (file_exists($linkPath) || is_link($linkPath)) {
        throw new RuntimeException('An item with that link name already exists.');
      }
      $type = (string)($data['type'] ?? 'symbolic');
      if ($type === 'hard') {
        if (!is_file($target)) {
          throw new RuntimeException('Hard links can only target regular files.');
        }
        mc_fs('Cannot create hard link', function () use ($target, $linkPath) { return link($target, $linkPath); });
      } else {
        mc_fs('Cannot create symbolic link', function () use ($target, $linkPath) { return symlink($target, $linkPath); });
      }
      mc_ok(['path' => $linkRel]);
    }

    if ($action === 'properties') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      $details = [];
      $totalSize = 0;
      $totalItems = 0;
      foreach ($paths as $rel) {
        $path = mc_existing_path((string)$rel, false);
        $info = mc_item_info($path, basename($path));
        $items = 0;
        $size = is_dir($path) && !is_link($path) ? mc_directory_size($path, $items) : ((is_file($path) && filesize($path) !== false) ? (int)filesize($path) : 0);
        if ($items === 0) $items = 1;
        $info['calculatedSize'] = $size;
        $info['itemCount'] = $items;
        $info['mime'] = is_file($path) ? mc_mime($path) : $info['type'];
        $details[] = $info;
        $totalSize += $size;
        $totalItems += $items;
      }
      mc_ok(['details' => $details, 'totalSize' => $totalSize, 'totalItems' => $totalItems]);
    }

    if ($action === 'checksum') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      $algorithm = (string)($data['algorithm'] ?? 'sha256');
      if (!in_array($algorithm, ['md5', 'sha1', 'sha256', 'sha512'], true)) {
        throw new RuntimeException('Unsupported checksum algorithm.');
      }
      $results = [];
      foreach ($paths as $rel) {
        $path = mc_existing_path((string)$rel);
        if (!is_file($path)) {
          $results[] = ['path' => mc_normalize_rel((string)$rel), 'hash' => null, 'error' => 'Not a regular file'];
        } else {
          $results[] = ['path' => mc_normalize_rel((string)$rel), 'hash' => hash_file($algorithm, $path)];
        }
      }
      mc_ok(['algorithm' => $algorithm, 'results' => $results]);
    }

    if ($action === 'archive_create') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      $result = mc_create_archive($paths, (string)($data['destination'] ?? ''), (string)($data['collision'] ?? 'rename'));
      mc_ok(['path' => $result]);
    }

    if ($action === 'archive_extract') {
      $archive = mc_existing_path((string)($data['path'] ?? ''));
      $destination = mc_existing_path((string)($data['destination'] ?? ''));
      if (!is_file($archive) || !is_dir($destination)) {
        throw new RuntimeException('Invalid archive or destination.');
      }
      $collision = (string)($data['collision'] ?? 'rename');
      $lower = strtolower($archive);
      if (substr($lower, -4) === '.zip') {
        $count = mc_extract_zip($archive, $destination, $collision);
      } elseif (substr($lower, -4) === '.tar' || substr($lower, -7) === '.tar.gz' || substr($lower, -4) === '.tgz') {
        $count = mc_extract_tar($archive, $destination, $collision);
      } else {
        throw new RuntimeException('Supported extraction formats are ZIP, TAR, TAR.GZ and TGZ.');
      }
      mc_ok(['count' => $count]);
    }

    if ($action === 'search') {
      mc_ok(mc_search((string)($data['base'] ?? ''), $data));
    }

    if ($action === 'tree') {
      $rel = mc_normalize_rel((string)($data['path'] ?? ''));
      $path = mc_existing_path($rel);
      if (!is_dir($path)) {
        throw new RuntimeException('The selected path is not a directory.');
      }

      $scannedItems = 0;
      $folderCount = 0;
      $fileCount = 0;
      $unreadableCount = 0;
      $includeHidden = !empty($data['hidden']);
      $tree = mc_directory_tree_node(
        $path,
        $rel,
        $includeHidden,
        0,
        $scannedItems,
        $folderCount,
        $fileCount,
        $unreadableCount
      );

      mc_ok([
        'tree' => $tree,
        'base' => $rel,
        'hidden' => $includeHidden,
        'scannedItems' => $scannedItems,
        'folderCount' => $folderCount,
        'fileCount' => $fileCount,
        'unreadableCount' => $unreadableCount
      ]);
    }

    if ($action === 'compare') {
      $mode = (string)($data['mode'] ?? 'quick');
      if (!in_array($mode, ['quick', 'checksum'], true)) {
        $mode = 'quick';
      }
      mc_ok(mc_compare_directories((string)($data['left'] ?? ''), (string)($data['right'] ?? ''), $mode));
    }

    if ($action === 'upload') {
      mc_ok(['results' => mc_handle_upload($data)]);
    }

    if ($action === 'download_selection') {
      $paths = json_decode((string)($data['paths'] ?? '[]'), true);
      if (!is_array($paths) || count($paths) === 0) {
        throw new RuntimeException('No items selected.');
      }
      mc_download_selection($paths);
    }

    if ($action === 'update_check') {
      $source = mc_update_download();
      $info = mc_update_source_info($source);
      mc_ok([
        'currentVersion' => MC_VERSION,
        'latestVersion' => $info['version'],
        'updateAvailable' => version_compare((string)$info['version'], MC_VERSION, '>'),
        'canInstall' => mc_update_can_install(),
        'sha256' => $info['sha256']
      ]);
    }

    if ($action === 'update_install') {
      mc_ok(mc_update_install((string)($data['expected_version'] ?? '')));
    }

    if ($action === 'change_password') {
      $current = (string)($data['current'] ?? '');
      $new = (string)($data['new_password'] ?? '');
      $confirm = (string)($data['confirm'] ?? '');
      $currentConfig = mc_config_read();
      if ($currentConfig === null || !password_verify($current, (string)$currentConfig['password_hash'])) {
        throw new RuntimeException('The current password is incorrect.');
      }
      $error = mc_password_validate($new);
      if ($error !== null) {
        throw new RuntimeException($error);
      }
      if (!hash_equals($new, $confirm)) {
        throw new RuntimeException('The two new passwords do not match.');
      }
      $currentConfig['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
      $currentConfig['updated_at'] = gmdate('c');
      mc_config_write($currentConfig);
      mc_new_csrf();
      mc_ok(['csrf' => $_SESSION['mc_csrf']]);
    }

    mc_fail('Unknown action.', 404);
  } catch (Throwable $e) {
    mc_fail($e->getMessage(), 400);
  }
}

if (!$authenticated) {
  $isSetup = $config === null;
  ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WebCommander <?= $isSetup ? 'Setup' : 'Login' ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <style>
    :root { color-scheme: dark; }
    body { min-height: 100vh; background: #000080; color: #f5f5f5; font-family: "Lucida Console", "Courier New", ui-monospace, monospace; }
    .login-card { width: min(440px, calc(100vw - 2rem)); background: #0000aa; border: 2px solid #55ffff; border-radius: 0 !important; box-shadow: 14px 14px 0 rgba(0, 0, 32, .55); }
    .brand-icon { width: 58px; height: 58px; display: grid; place-items: center; border-radius: 0; color: #000080; background: #ffff55; font-size: 1.65rem; }
    .form-control { border-radius: 0; background: #00006f; border-color: #00a7b3; color: #fff; }
    .form-control:focus { background: #00006f; color: #fff; border-color: #55ffff; box-shadow: 0 0 0 .2rem rgba(85, 255, 255, .22); }
    .form-control::placeholder { color: #b8d6d6; }
    .btn-primary { border-radius: 0; color: #000; background: #00aaaa; border-color: #55ffff; font-weight: 700; }
    .btn-primary:hover, .btn-primary:focus { color: #000; background: #55ffff; border-color: #ffff55; }
    .text-secondary { color: #c7e5e5 !important; }
  </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
  <main class="login-card rounded-4 p-4 p-md-5 text-light">
    <div class="d-flex align-items-center gap-3 mb-4">
      <div class="brand-icon"><i class="fa-solid fa-table-columns"></i></div>
      <div><h1 class="h4 mb-1">WebCommander</h1><div class="text-secondary small">Two-pane filesystem manager</div></div>
    </div>
    <?php if ($isSetup): ?>
      <h2 class="h5 mb-2">First-run setup</h2>
      <p class="text-secondary small mb-4">Create the password used to protect this file manager. Minimum 10 characters.</p>
      <?php if ($setupError !== ''): ?><div class="alert alert-danger py-2"><?= mc_h($setupError) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="auth_action" value="setup">
        <div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" minlength="10" required autofocus autocomplete="new-password"></div>
        <div class="mb-4"><label class="form-label" for="confirm_password">Confirm password</label><input class="form-control" id="confirm_password" name="confirm_password" type="password" minlength="10" required autocomplete="new-password"></div>
        <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-lock me-2"></i>Create password</button>
      </form>
    <?php else: ?>
      <h2 class="h5 mb-2">Sign in</h2>
      <p class="text-secondary small mb-4">Enter the WebCommander password.</p>
      <?php if ($loginError !== ''): ?><div class="alert alert-danger py-2"><?= mc_h($loginError) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="auth_action" value="login">
        <div class="mb-4"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" required autofocus autocomplete="current-password"></div>
        <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-right-to-bracket me-2"></i>Sign in</button>
      </form>
    <?php endif; ?>
    <div class="border-top border-secondary-subtle mt-4 pt-3 text-secondary small text-break"><i class="fa-solid fa-shield-halved me-2"></i>Root: <?= mc_h(MC_ROOT_REAL) ?></div>
  </main>
</body>
</html>
  <?php
  exit;
}

$csrf = (string)($_SESSION['mc_csrf'] ?? mc_new_csrf());
$diskFree = disk_free_space(MC_ROOT_REAL);
$diskTotal = disk_total_space(MC_ROOT_REAL);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WebCommander</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;600&family=IBM+Plex+Mono:wght@400;600&family=JetBrains+Mono:wght@400;600&family=Roboto+Mono:wght@400;600&family=Source+Code+Pro:wght@400;600&family=Ubuntu+Mono:wght@400;700&display=swap" rel="stylesheet">
  <script>
    (() => {
      const themes = ['norton', 'midnight', 'solarized-dark', 'solarized-light', 'nord', 'gruvbox', 'dracula', 'monokai', 'forest', 'paper', 'amber'];
      const fonts = ['lucida', 'consolas', 'cascadia', 'jetbrains', 'fira', 'source-code', 'ibm-plex', 'roboto', 'ubuntu', 'courier'];
      let theme = 'norton';
      let font = 'lucida';
      let fontSize = 13;
      try {
        const savedTheme = localStorage.getItem('webcommander-theme');
        const savedFont = localStorage.getItem('webcommander-font');
        const savedFontSize = Number.parseInt(localStorage.getItem('webcommander-font-size') || '', 10);
        if (themes.includes(savedTheme)) theme = savedTheme;
        if (fonts.includes(savedFont)) font = savedFont;
        if (Number.isInteger(savedFontSize) && savedFontSize >= 6 && savedFontSize <= 24) fontSize = savedFontSize;
      } catch (error) {}
      document.documentElement.dataset.theme = theme;
      document.documentElement.dataset.font = font;
      document.documentElement.dataset.fontSize = String(fontSize);
      document.documentElement.style.setProperty('--wc-font-size', fontSize + 'px');
    })();
  </script>
  <style>
    :root {
      --wc-font: "Lucida Console", "Lucida Sans Typewriter", "Courier New", monospace;
      --wc-font-size: 13px;
    }
    html[data-font="lucida"] { --wc-font: "Lucida Console", "Lucida Sans Typewriter", "Courier New", monospace; }
    html[data-font="consolas"] { --wc-font: Consolas, "Cascadia Mono", "Courier New", monospace; }
    html[data-font="cascadia"] { --wc-font: "Cascadia Mono", "Cascadia Code", Consolas, monospace; }
    html[data-font="jetbrains"] { --wc-font: "JetBrains Mono", "Cascadia Mono", Consolas, monospace; }
    html[data-font="fira"] { --wc-font: "Fira Code", "DejaVu Sans Mono", monospace; }
    html[data-font="source-code"] { --wc-font: "Source Code Pro", "Liberation Mono", monospace; }
    html[data-font="ibm-plex"] { --wc-font: "IBM Plex Mono", "DejaVu Sans Mono", monospace; }
    html[data-font="roboto"] { --wc-font: "Roboto Mono", "DejaVu Sans Mono", monospace; }
    html[data-font="ubuntu"] { --wc-font: "Ubuntu Mono", "Liberation Mono", monospace; }
    html[data-font="courier"] { --wc-font: "Courier New", Courier, monospace; }
    :root, html[data-theme="norton"] {
      color-scheme: dark;
      --wc-bg: #000080;
      --wc-chrome: #000060;
      --wc-panel: #0000aa;
      --wc-panel-2: #000080;
      --wc-surface: #00006f;
      --wc-surface-hover: #0000cc;
      --wc-line: #00a7b3;
      --wc-grid: #000080;
      --wc-line-strong: #55ffff;
      --wc-text: #f5f5f5;
      --wc-muted: #c7e5e5;
      --wc-accent: #ffff55;
      --wc-selected: #00aaaa;
      --wc-selected-text: #000000;
      --wc-danger: #ff7777;
      --wc-danger-bg: #780000;
      --wc-folder: #ffff55;
      --wc-link: #55ffff;
      --wc-file: #f5f5f5;
      --wc-success: #55ff55;
      --wc-warning: #ffff55;
      --wc-overlay: rgba(0, 0, 32, .78);
      --wc-shadow: rgba(0, 0, 0, .42);
    }
    html[data-theme="midnight"] {
      color-scheme: dark;
      --wc-bg: #07111f;
      --wc-chrome: #081728;
      --wc-panel: #0b1a2d;
      --wc-panel-2: #0e223b;
      --wc-surface: #071525;
      --wc-surface-hover: #173657;
      --wc-line: #2a4667;
      --wc-grid: #172b40;
      --wc-line-strong: #76d8ff;
      --wc-text: #e7effa;
      --wc-muted: #9fb2c7;
      --wc-accent: #55b8ff;
      --wc-selected: #17548a;
      --wc-selected-text: #ffffff;
      --wc-danger: #ff7d89;
      --wc-danger-bg: #391c28;
      --wc-folder: #72c9ff;
      --wc-link: #c39af7;
      --wc-file: #c6d4e2;
      --wc-success: #71deb5;
      --wc-warning: #f2c878;
      --wc-overlay: rgba(0, 6, 14, .75);
      --wc-shadow: rgba(0, 0, 0, .48);
    }
    html[data-theme="solarized-dark"] {
      color-scheme: dark;
      --wc-bg: #002b36;
      --wc-chrome: #00242d;
      --wc-panel: #073642;
      --wc-panel-2: #0a3e4b;
      --wc-surface: #002b36;
      --wc-surface-hover: #164a56;
      --wc-line: #42636a;
      --wc-grid: #174552;
      --wc-line-strong: #2aa198;
      --wc-text: #eee8d5;
      --wc-muted: #a9b6b6;
      --wc-accent: #2aa198;
      --wc-selected: #2aa198;
      --wc-selected-text: #002b36;
      --wc-danger: #ff6b63;
      --wc-danger-bg: #4a2528;
      --wc-folder: #e4b93d;
      --wc-link: #eb76ae;
      --wc-file: #d7d4c8;
      --wc-success: #a8b932;
      --wc-warning: #e7a93b;
      --wc-overlay: rgba(0, 20, 25, .78);
      --wc-shadow: rgba(0, 0, 0, .42);
    }
    html[data-theme="solarized-light"] {
      color-scheme: light;
      --wc-bg: #eee8d5;
      --wc-chrome: #e5ddc5;
      --wc-panel: #fdf6e3;
      --wc-panel-2: #f3ecd9;
      --wc-surface: #fffaf0;
      --wc-surface-hover: #e7dfc9;
      --wc-line: #b9ad91;
      --wc-grid: #ded5bf;
      --wc-line-strong: #1c739b;
      --wc-text: #073642;
      --wc-muted: #526970;
      --wc-accent: #096f99;
      --wc-selected: #147d8a;
      --wc-selected-text: #ffffff;
      --wc-danger: #b9231e;
      --wc-danger-bg: #f6d5ce;
      --wc-folder: #856100;
      --wc-link: #7c3c8f;
      --wc-file: #334f54;
      --wc-success: #567000;
      --wc-warning: #8a5f00;
      --wc-overlay: rgba(25, 35, 37, .38);
      --wc-shadow: rgba(38, 48, 50, .2);
    }
    html[data-theme="nord"] {
      color-scheme: dark;
      --wc-bg: #242933;
      --wc-chrome: #2b303b;
      --wc-panel: #2e3440;
      --wc-panel-2: #3b4252;
      --wc-surface: #262c36;
      --wc-surface-hover: #434c5e;
      --wc-line: #536077;
      --wc-grid: #3b4252;
      --wc-line-strong: #88c0d0;
      --wc-text: #eceff4;
      --wc-muted: #c3c9d4;
      --wc-accent: #88c0d0;
      --wc-selected: #4c6f91;
      --wc-selected-text: #ffffff;
      --wc-danger: #ea8992;
      --wc-danger-bg: #4a2f36;
      --wc-folder: #ebcb8b;
      --wc-link: #c3a2c7;
      --wc-file: #d8dee9;
      --wc-success: #a3be8c;
      --wc-warning: #e2a277;
      --wc-overlay: rgba(21, 25, 32, .76);
      --wc-shadow: rgba(0, 0, 0, .4);
    }
    html[data-theme="gruvbox"] {
      color-scheme: dark;
      --wc-bg: #1d2021;
      --wc-chrome: #242424;
      --wc-panel: #282828;
      --wc-panel-2: #3c3836;
      --wc-surface: #1d2021;
      --wc-surface-hover: #504945;
      --wc-line: #665c54;
      --wc-grid: #3c3836;
      --wc-line-strong: #83a598;
      --wc-text: #ebdbb2;
      --wc-muted: #c5b89a;
      --wc-accent: #83a598;
      --wc-selected: #3f6f75;
      --wc-selected-text: #fff7df;
      --wc-danger: #fb6a5a;
      --wc-danger-bg: #4b2422;
      --wc-folder: #fabd2f;
      --wc-link: #d99ab3;
      --wc-file: #d5c4a1;
      --wc-success: #b8bb26;
      --wc-warning: #fe9f38;
      --wc-overlay: rgba(20, 18, 17, .78);
      --wc-shadow: rgba(0, 0, 0, .48);
    }
    html[data-theme="dracula"] {
      color-scheme: dark;
      --wc-bg: #1e1f29;
      --wc-chrome: #242631;
      --wc-panel: #282a36;
      --wc-panel-2: #343746;
      --wc-surface: #20222b;
      --wc-surface-hover: #44475a;
      --wc-line: #565a70;
      --wc-grid: #343746;
      --wc-line-strong: #8be9fd;
      --wc-text: #f8f8f2;
      --wc-muted: #c5c8d5;
      --wc-accent: #8be9fd;
      --wc-selected: #596a9f;
      --wc-selected-text: #ffffff;
      --wc-danger: #ff7b86;
      --wc-danger-bg: #4b2430;
      --wc-folder: #f1fa8c;
      --wc-link: #c7a4ff;
      --wc-file: #e5e5df;
      --wc-success: #6df591;
      --wc-warning: #ffbd7a;
      --wc-overlay: rgba(15, 16, 23, .78);
      --wc-shadow: rgba(0, 0, 0, .48);
    }
    html[data-theme="monokai"] {
      color-scheme: dark;
      --wc-bg: #1d1e19;
      --wc-chrome: #23241f;
      --wc-panel: #272822;
      --wc-panel-2: #33342c;
      --wc-surface: #1f201b;
      --wc-surface-hover: #46473c;
      --wc-line: #5c5d51;
      --wc-grid: #3a3b33;
      --wc-line-strong: #66d9ef;
      --wc-text: #f8f8f2;
      --wc-muted: #c7c7bd;
      --wc-accent: #66d9ef;
      --wc-selected: #526642;
      --wc-selected-text: #ffffff;
      --wc-danger: #ff5b8d;
      --wc-danger-bg: #4c2032;
      --wc-folder: #e6db74;
      --wc-link: #bc9aff;
      --wc-file: #e3e3dc;
      --wc-success: #a6e22e;
      --wc-warning: #fda43c;
      --wc-overlay: rgba(16, 17, 14, .8);
      --wc-shadow: rgba(0, 0, 0, .48);
    }
    html[data-theme="forest"] {
      color-scheme: dark;
      --wc-bg: #0d1f1a;
      --wc-chrome: #10261f;
      --wc-panel: #132b24;
      --wc-panel-2: #17382e;
      --wc-surface: #0e241d;
      --wc-surface-hover: #21483b;
      --wc-line: #356655;
      --wc-grid: #214137;
      --wc-line-strong: #83d9b3;
      --wc-text: #edf7f1;
      --wc-muted: #aecdbd;
      --wc-accent: #7bdcb5;
      --wc-selected: #246b55;
      --wc-selected-text: #ffffff;
      --wc-danger: #ff8585;
      --wc-danger-bg: #48252a;
      --wc-folder: #e7d27c;
      --wc-link: #c2abea;
      --wc-file: #d8e9e0;
      --wc-success: #8fd694;
      --wc-warning: #f0ba6c;
      --wc-overlay: rgba(4, 20, 14, .78);
      --wc-shadow: rgba(0, 0, 0, .44);
    }
    html[data-theme="paper"] {
      color-scheme: light;
      --wc-bg: #e8e7e2;
      --wc-chrome: #f1f0ec;
      --wc-panel: #ffffff;
      --wc-panel-2: #f5f4f0;
      --wc-surface: #faf9f6;
      --wc-surface-hover: #e4ebf2;
      --wc-line: #c1c5c9;
      --wc-grid: #e4e4e0;
      --wc-line-strong: #285f9b;
      --wc-text: #20252b;
      --wc-muted: #5f6872;
      --wc-accent: #285f9b;
      --wc-selected: #2f69a5;
      --wc-selected-text: #ffffff;
      --wc-danger: #b4232c;
      --wc-danger-bg: #fde7e8;
      --wc-folder: #805500;
      --wc-link: #6d3f91;
      --wc-file: #3d4852;
      --wc-success: #24734a;
      --wc-warning: #815600;
      --wc-overlay: rgba(24, 30, 36, .4);
      --wc-shadow: rgba(35, 42, 49, .2);
    }
    html[data-theme="amber"] {
      color-scheme: dark;
      --wc-bg: #160f00;
      --wc-chrome: #211600;
      --wc-panel: #1c1300;
      --wc-panel-2: #2a1c00;
      --wc-surface: #120c00;
      --wc-surface-hover: #3a2803;
      --wc-line: #7f5a11;
      --wc-grid: #3b2906;
      --wc-line-strong: #ffc857;
      --wc-text: #ffe7a3;
      --wc-muted: #c8ae70;
      --wc-accent: #ffc857;
      --wc-selected: #d9901f;
      --wc-selected-text: #140d00;
      --wc-danger: #ff806a;
      --wc-danger-bg: #4a1d10;
      --wc-folder: #ffd166;
      --wc-link: #ffad5a;
      --wc-file: #ffe7a3;
      --wc-success: #9adf60;
      --wc-warning: #ffd166;
      --wc-overlay: rgba(15, 9, 0, .82);
      --wc-shadow: rgba(0, 0, 0, .52);
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; overflow: hidden; }
    body { margin: 0; background: var(--wc-bg); color: var(--wc-text); font-family: var(--wc-font); font-size: var(--wc-font-size); }
    button, input, select, textarea { font: inherit; }
    .text-secondary { color: var(--wc-muted) !important; }
    .wc-app { height: 100%; display: grid; grid-template-rows: auto auto minmax(0, 1fr) auto auto; }
    .wc-topbar { min-height: 48px; display: flex; align-items: center; gap: 12px; padding: 7px 12px; background: var(--wc-chrome); border-bottom: 1px solid var(--wc-line); }
    .wc-brand { display: flex; align-items: center; gap: 9px; font-weight: 750; letter-spacing: .02em; white-space: nowrap; }
    .wc-brand-mark { width: 31px; height: 31px; display: grid; place-items: center; border-radius: 8px; background: linear-gradient(135deg, var(--wc-accent), var(--wc-folder)); color: var(--wc-bg); }
    .wc-root { color: var(--wc-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0; }
    .wc-disk { color: var(--wc-muted); white-space: nowrap; }
    .wc-theme-picker { display: inline-flex; align-items: center; gap: 6px; color: var(--wc-accent); white-space: nowrap; }
    .wc-theme-select { width: 166px; min-height: 31px; border: 1px solid var(--wc-line); border-radius: 6px; color: var(--wc-text); background: var(--wc-surface); padding: 4px 28px 4px 8px; cursor: pointer; }
    .wc-theme-select:hover { border-color: var(--wc-line-strong); }
    .wc-font-select { width: 154px; }
    .wc-font-size-select { width: 82px; }
    .wc-theme-select:focus-visible, .wc-btn:focus-visible, .wc-key:focus-visible, .wc-context button:focus-visible, .wc-dialog-close:focus-visible { border-color: var(--wc-line-strong); outline: 2px solid var(--wc-line-strong); outline-offset: 1px; }
    .wc-btn { border: 1px solid var(--wc-line); border-radius: 6px; color: var(--wc-text); background: var(--wc-panel-2); min-height: 31px; padding: 5px 9px; display: inline-flex; gap: 6px; align-items: center; justify-content: center; cursor: pointer; }
    .wc-btn:hover, .wc-btn:focus { background: var(--wc-surface-hover); border-color: var(--wc-line-strong); color: var(--wc-text); outline: none; }
    .wc-btn.primary { color: var(--wc-selected-text); background: var(--wc-selected); border-color: var(--wc-line-strong); }
    .wc-btn.danger { color: var(--wc-danger); border-color: var(--wc-danger); background: var(--wc-danger-bg); }
    .wc-version { font-variant-numeric: tabular-nums; font-weight: 700; }
    .wc-version i { color: var(--wc-accent); }
    .wc-toolbar { display: flex; gap: 4px; align-items: center; padding: 5px 8px; overflow-x: auto; background: var(--wc-panel-2); border-bottom: 1px solid var(--wc-line); scrollbar-width: thin; scrollbar-color: var(--wc-line) var(--wc-surface); }
    .wc-toolbar .wc-btn { white-space: nowrap; }
    .wc-toolbar-sep { width: 1px; height: 24px; background: var(--wc-line); margin: 0 3px; flex: 0 0 auto; }
    .wc-panes { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); min-height: 0; gap: 5px; padding: 5px; }
    .wc-pane { display: grid; grid-template-rows: auto auto minmax(0, 1fr) auto; background: var(--wc-panel); border: 1px solid var(--wc-line); border-radius: 7px; min-width: 0; overflow: hidden; box-shadow: 0 8px 24px var(--wc-shadow); }
    .wc-pane.active { border-color: var(--wc-line-strong); box-shadow: 0 0 0 1px var(--wc-line-strong), 0 8px 28px var(--wc-shadow); }
    .wc-pane-head { display: flex; align-items: center; gap: 5px; padding: 6px; border-bottom: 1px solid var(--wc-line); background: var(--wc-panel-2); }
    .wc-pane-head .wc-btn { min-width: 31px; padding: 4px 7px; }
    .wc-path { min-width: 0; flex: 1; height: max(31px, calc(var(--wc-font-size) + 16px)); border-radius: 5px; border: 1px solid var(--wc-line); background: var(--wc-surface); color: var(--wc-text); padding: 4px 8px; font-family: var(--wc-font); }
    .wc-path:focus, .wc-filter:focus, .wc-input:focus, .wc-select:focus, .wc-textarea:focus { outline: 2px solid var(--wc-line-strong); outline-offset: 1px; border-color: var(--wc-line-strong); }
    .wc-pane-tools { display: flex; align-items: center; gap: 6px; padding: 5px 7px; background: var(--wc-panel-2); border-bottom: 1px solid var(--wc-line); }
    .wc-filter { flex: 1; min-width: 0; height: max(27px, calc(var(--wc-font-size) + 14px)); border: 1px solid var(--wc-line); border-radius: 5px; background: var(--wc-surface); color: var(--wc-text); padding: 3px 8px; }
    .wc-path::placeholder, .wc-filter::placeholder, .wc-input::placeholder, .wc-textarea::placeholder { color: var(--wc-muted); opacity: .9; }
    .wc-check-label { display: inline-flex; align-items: center; gap: 4px; color: var(--wc-muted); white-space: nowrap; }
    .wc-table-wrap { min-height: 0; overflow: auto; position: relative; scrollbar-color: var(--wc-line) var(--wc-surface); scrollbar-width: thin; }
    .wc-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-family: var(--wc-font); font-size: .92em; }
    .wc-table th { position: sticky; top: 0; z-index: 2; height: max(29px, calc(var(--wc-font-size) + 16px)); background: var(--wc-panel-2); color: var(--wc-muted); text-align: left; font-weight: 650; border-bottom: 1px solid var(--wc-line); padding: 4px 6px; cursor: pointer; user-select: none; }
    .wc-table th:first-child { width: 30px; cursor: default; }
    .wc-table th.name { width: auto; }
    .wc-table th.size { width: 89px; text-align: right; }
    .wc-table th.date { width: 129px; }
    .wc-table th.perms { width: 91px; }
    .wc-table td { height: max(27px, calc(var(--wc-font-size) + 14px)); padding: 3px 6px; border-bottom: 1px solid var(--wc-grid); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wc-table td.size { text-align: right; color: var(--wc-muted); }
    .wc-table td.date, .wc-table td.perms { color: var(--wc-muted); }
    .wc-row { cursor: default; user-select: none; }
    .wc-row:hover { background: var(--wc-surface-hover); }
    .wc-row.selected { background: var(--wc-selected); color: var(--wc-selected-text); }
    .wc-row.selected td, .wc-row.selected .text-secondary, .wc-row.selected .wc-folder, .wc-row.selected .wc-link, .wc-row.selected .wc-file, .wc-row.selected .wc-up { color: var(--wc-selected-text) !important; }
    .wc-row.focused { outline: 1px solid var(--wc-line-strong); outline-offset: -1px; }
    .wc-row.drop-target { color: var(--wc-selected-text); background: var(--wc-selected) !important; outline: 1px solid var(--wc-success); }
    .wc-row.drop-target td { color: var(--wc-selected-text); }
    .wc-row.protected .wc-name { color: var(--wc-warning); }
    .wc-icon { display: inline-block; width: 18px; text-align: center; margin-right: 3px; }
    .wc-folder { color: var(--wc-folder); }
    .wc-link { color: var(--wc-link); }
    .wc-file { color: var(--wc-file); }
    .wc-up { color: var(--wc-success); }
    .wc-empty { display: grid; place-items: center; min-height: 150px; color: var(--wc-muted); }
    .wc-pane-status { min-height: 27px; display: flex; align-items: center; gap: 10px; padding: 4px 8px; color: var(--wc-muted); background: var(--wc-panel-2); border-top: 1px solid var(--wc-line); white-space: nowrap; overflow: hidden; }
    .wc-pane-status span { overflow: hidden; text-overflow: ellipsis; }
    .wc-progress-row { display: none; padding: 4px 8px; background: var(--wc-chrome); border-top: 1px solid var(--wc-line); }
    .wc-progress-row.show { display: flex; align-items: center; gap: 8px; }
    .wc-progress-track { flex: 1; height: 7px; background: var(--wc-surface); border-radius: 6px; overflow: hidden; }
    .wc-progress-bar { width: 0; height: 100%; background: linear-gradient(90deg, var(--wc-accent), var(--wc-success)); transition: width .12s; }
    .wc-global-status { display: flex; align-items: center; gap: 9px; min-height: 28px; padding: 4px 10px; background: var(--wc-chrome); border-top: 1px solid var(--wc-line); color: var(--wc-muted); }
    .wc-global-status .message { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wc-keys { display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); border-top: 1px solid var(--wc-line); background: var(--wc-chrome); }
    .wc-key { border: 0; border-right: 1px solid var(--wc-line); color: var(--wc-text); background: transparent; padding: 5px 3px; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
    .wc-key:hover { background: var(--wc-surface-hover); }
    .wc-key b { color: var(--wc-accent); margin-right: 3px; }
    .wc-dialog { width: min(560px, calc(100vw - 24px)); max-height: calc(100vh - 24px); padding: 0; color: var(--wc-text); background: var(--wc-panel); border: 1px solid var(--wc-line-strong); border-radius: 9px; box-shadow: 0 25px 90px var(--wc-shadow); }
    .wc-dialog.wide { width: min(1050px, calc(100vw - 24px)); }
    .wc-dialog::backdrop { background: var(--wc-overlay); backdrop-filter: blur(2px); }
    .wc-dialog-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 14px; border-bottom: 1px solid var(--wc-line); background: var(--wc-panel-2); font-size: 1.15em; font-weight: 700; }
    .wc-dialog-close { border: 0; background: transparent; color: var(--wc-muted); font-size: 1.54em; cursor: pointer; }
    .wc-dialog-body { padding: 14px; overflow: auto; max-height: calc(100vh - 145px); }
    .wc-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; padding: 10px 14px; border-top: 1px solid var(--wc-line); }
    .wc-field { margin-bottom: 12px; }
    .wc-field:last-child { margin-bottom: 0; }
    .wc-field label { display: block; color: var(--wc-text); margin-bottom: 5px; }
    .wc-input, .wc-select, .wc-textarea { width: 100%; border: 1px solid var(--wc-line); border-radius: 6px; background: var(--wc-surface); color: var(--wc-text); padding: 7px 9px; }
    .wc-textarea { min-height: 360px; resize: vertical; font-family: var(--wc-font); font-size: var(--wc-font-size); line-height: 1.45; tab-size: 2; }
    .wc-check { display: flex; align-items: center; gap: 7px; color: var(--wc-text); }
    .wc-viewer { min-height: 300px; max-height: calc(100vh - 190px); overflow: auto; background: var(--wc-surface); border: 1px solid var(--wc-line); border-radius: 6px; }
    .wc-viewer pre { margin: 0; padding: 13px; color: var(--wc-text); white-space: pre-wrap; word-break: break-word; font-family: var(--wc-font); font-size: var(--wc-font-size); line-height: 1.45; }
    .wc-viewer img, .wc-viewer video { display: block; max-width: 100%; max-height: calc(100vh - 210px); margin: auto; }
    .wc-viewer iframe { display: block; width: 100%; height: calc(100vh - 210px); border: 0; background: white; }
    .wc-viewer audio { width: calc(100% - 30px); margin: 30px 15px; }
    .wc-result-list { border: 1px solid var(--wc-line); border-radius: 6px; overflow: auto; max-height: 55vh; }
    .wc-result { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 8px; padding: 7px 9px; border-bottom: 1px solid var(--wc-line); cursor: pointer; }
    .wc-result:last-child { border-bottom: 0; }
    .wc-result:hover { background: var(--wc-surface-hover); }
    .wc-result-path { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wc-result-note { color: var(--wc-muted); font-size: .85em; }
    .wc-tree-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; margin-bottom: 9px; }
    .wc-tree-tools .wc-result-note { flex: 1; min-width: 220px; }
    .wc-tree-view { max-height: calc(100vh - 260px); overflow: auto; padding: 7px; border: 1px solid var(--wc-line); border-radius: 6px; background: var(--wc-surface); font-family: var(--wc-font); }
    .wc-tree-node { margin: 0; }
    .wc-tree-node > summary { display: flex; align-items: center; gap: 7px; min-width: 720px; min-height: 31px; padding: 4px 7px; border-radius: 4px; cursor: pointer; list-style: none; }
    .wc-tree-node > summary::-webkit-details-marker { display: none; }
    .wc-tree-node > summary::before { content: '▸'; flex: 0 0 12px; color: var(--wc-accent); transition: transform .12s ease; }
    .wc-tree-node[open] > summary::before { transform: rotate(90deg); }
    .wc-tree-node.leaf > summary::before { content: '•'; transform: none; color: var(--wc-muted); }
    .wc-tree-node > summary:hover, .wc-tree-node > summary:focus-visible { background: var(--wc-surface-hover); outline: none; }
    .wc-tree-label { display: flex; align-items: center; gap: 6px; min-width: 120px; flex: 1; }
    .wc-tree-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wc-tree-usage { display: grid; grid-template-columns: 120px 49px; align-items: center; gap: 7px; flex: 0 0 auto; }
    .wc-tree-bar { height: 9px; overflow: hidden; border: 1px solid var(--wc-line); border-radius: 999px; background: var(--wc-panel-2); }
    .wc-tree-bar > span { display: block; height: 100%; min-width: 0; border-radius: inherit; background: linear-gradient(90deg, var(--wc-accent), var(--wc-folder)); }
    .wc-tree-percent { color: var(--wc-text); font-size: .85em; text-align: right; white-space: nowrap; }
    .wc-tree-meta { flex: 0 0 265px; color: var(--wc-muted); font-size: .85em; text-align: right; white-space: nowrap; }
    .wc-tree-children { margin-left: 12px; padding-left: 9px; border-left: 1px solid var(--wc-grid); }
    .wc-tree-node.unreadable > summary .wc-tree-name, .wc-tree-node.unreadable > summary .wc-tree-meta { color: var(--wc-danger); }
    .wc-info-table { width: 100%; border-collapse: collapse; }
    .wc-info-table th, .wc-info-table td { padding: 6px 8px; border-bottom: 1px solid var(--wc-line); text-align: left; vertical-align: top; }
    .wc-info-table th { width: 145px; color: var(--wc-muted); font-weight: 600; }
    .wc-mono { font-family: var(--wc-font); word-break: break-all; }
    .wc-context { display: none; position: fixed; z-index: 5000; min-width: 170px; padding: 5px; background: var(--wc-panel-2); border: 1px solid var(--wc-line-strong); border-radius: 7px; box-shadow: 0 15px 40px var(--wc-shadow); }
    .wc-context.show { display: block; }
    .wc-context button { width: 100%; border: 0; border-radius: 4px; color: var(--wc-text); background: transparent; padding: 6px 8px; text-align: left; }
    .wc-context button:hover { background: var(--wc-surface-hover); }
    .wc-toast-area { position: fixed; right: 12px; top: 58px; z-index: 6000; display: flex; flex-direction: column; gap: 7px; pointer-events: none; }
    .wc-toast { max-width: min(430px, calc(100vw - 24px)); padding: 10px 12px; background: var(--wc-panel-2); border: 1px solid var(--wc-line-strong); border-radius: 7px; color: var(--wc-text); box-shadow: 0 10px 30px var(--wc-shadow); animation: wc-in .15s ease-out; }
    .wc-toast.error { color: var(--wc-danger); background: var(--wc-danger-bg); border-color: var(--wc-danger); }
    html[data-theme="norton"] body { font-family: var(--wc-font); }
    html[data-theme="norton"] .wc-pane, html[data-theme="norton"] .wc-btn, html[data-theme="norton"] .wc-theme-select, html[data-theme="norton"] .wc-path, html[data-theme="norton"] .wc-filter, html[data-theme="norton"] .wc-dialog, html[data-theme="norton"] .wc-input, html[data-theme="norton"] .wc-select, html[data-theme="norton"] .wc-textarea, html[data-theme="norton"] .wc-viewer, html[data-theme="norton"] .wc-result-list, html[data-theme="norton"] .wc-tree-view, html[data-theme="norton"] .wc-context, html[data-theme="norton"] .wc-toast, html[data-theme="norton"] .wc-brand-mark { border-radius: 0; }
    html[data-theme="norton"] .wc-pane { box-shadow: none; }
    html[data-theme="norton"] .wc-pane.active { box-shadow: 0 0 0 1px var(--wc-line-strong); }
    html[data-theme="norton"] .wc-brand-mark { background: var(--wc-accent); }
    @keyframes wc-in { from { opacity: 0; transform: translateY(-5px); } }
    @media (max-width: 900px) {
      .wc-panes { grid-template-columns: 1fr; grid-template-rows: minmax(0,1fr) minmax(0,1fr); }
      .wc-table th.perms, .wc-table td.perms { display: none; }
      .wc-disk, .wc-root { display: none; }
      .wc-keys { grid-template-columns: repeat(4, minmax(0,1fr)); }
    }
    @media (max-width: 720px) {
      .wc-topbar { gap: 6px; padding-inline: 7px; }
      .wc-brand > span:last-child, .wc-topbar .wc-btn span { display: none; }
      .wc-topbar .wc-version span { display: inline; }
      .wc-theme-select { width: 145px; }
      .wc-font-size-select { width: 78px; }
    }
    @media (max-width: 560px) {
      .wc-table th.date, .wc-table td.date { display: none; }
      .wc-table th.size { width: 78px; }
      .wc-toolbar .wc-btn span { display: none; }
      .wc-toolbar .wc-btn { min-width: 33px; }
      .wc-theme-picker > i { display: none; }
      .wc-theme-select { width: 124px; }
      .wc-font-size-select { width: 72px; }
    }
    @media (max-width: 440px) {
      .wc-topbar { gap: 4px; padding-inline: 5px; }
      .wc-brand { display: none; }
      .wc-topbar .wc-version span { display: none; }
      .wc-theme-select { width: 78px; padding-inline: 5px 20px; }
      .wc-font-size-select { width: 62px; }
    }
  </style>
</head>
<body>
<div class="wc-app">
  <header class="wc-topbar">
    <div class="wc-brand"><span class="wc-brand-mark"><i class="fa-solid fa-table-columns"></i></span><span>WebCommander</span></div>
    <div class="wc-root" title="<?= mc_h(MC_ROOT_REAL) ?>"><i class="fa-solid fa-shield-halved me-1"></i><?= mc_h(MC_ROOT_REAL) ?></div>
    <div class="wc-disk" id="diskInfo"></div>
    <label class="wc-theme-picker" title="Color theme">
      <i class="fa-solid fa-palette" aria-hidden="true"></i>
      <span class="visually-hidden">Color theme</span>
      <select class="wc-theme-select" id="themeSelect" aria-label="Color theme">
        <option value="norton">Norton Commander</option>
        <option value="midnight">Midnight Blue</option>
        <option value="solarized-dark">Solarized Dark</option>
        <option value="solarized-light">Solarized Light</option>
        <option value="nord">Nord</option>
        <option value="gruvbox">Gruvbox Dark</option>
        <option value="dracula">Dracula</option>
        <option value="monokai">Monokai</option>
        <option value="forest">Forest</option>
        <option value="paper">Paper Light</option>
        <option value="amber">Amber Terminal</option>
      </select>
    </label>
    <label class="wc-theme-picker" title="Fixed-width interface font">
      <i class="fa-solid fa-font" aria-hidden="true"></i>
      <span class="visually-hidden">Interface font</span>
      <select class="wc-theme-select wc-font-select" id="fontSelect" aria-label="Fixed-width interface font">
        <option value="lucida">Lucida Console</option>
        <option value="consolas">Consolas</option>
        <option value="cascadia">Cascadia Mono</option>
        <option value="jetbrains">JetBrains Mono</option>
        <option value="fira">Fira Code</option>
        <option value="source-code">Source Code Pro</option>
        <option value="ibm-plex">IBM Plex Mono</option>
        <option value="roboto">Roboto Mono</option>
        <option value="ubuntu">Ubuntu Mono</option>
        <option value="courier">Courier New</option>
      </select>
    </label>
    <label class="wc-theme-picker" title="Interface font size">
      <i class="fa-solid fa-text-height" aria-hidden="true"></i>
      <span class="visually-hidden">Interface font size</span>
      <select class="wc-theme-select wc-font-size-select" id="fontSizeSelect" aria-label="Interface font size">
        <option value="6">6 px</option>
        <option value="7">7 px</option>
        <option value="8">8 px</option>
        <option value="9">9 px</option>
        <option value="10">10 px</option>
        <option value="11">11 px</option>
        <option value="12">12 px</option>
        <option value="13" selected>13 px</option>
        <option value="14">14 px</option>
        <option value="15">15 px</option>
        <option value="16">16 px</option>
        <option value="17">17 px</option>
        <option value="18">18 px</option>
        <option value="19">19 px</option>
        <option value="20">20 px</option>
        <option value="21">21 px</option>
        <option value="22">22 px</option>
        <option value="23">23 px</option>
        <option value="24">24 px</option>
      </select>
    </label>
    <button class="wc-btn wc-version" id="versionButton" data-action="update" title="Version <?= mc_h(MC_VERSION) ?> — check for updates" aria-label="WebCommander version <?= mc_h(MC_VERSION) ?>. Check for updates"><i class="fa-solid fa-cloud-arrow-down"></i><span>v<?= mc_h(MC_VERSION) ?></span></button>
    <button class="wc-btn" data-action="password" title="Change password"><i class="fa-solid fa-key"></i><span>Password</span></button>
    <button class="wc-btn" data-action="logout" title="Sign out"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></button>
  </header>

  <nav class="wc-toolbar" aria-label="File operations">
    <button class="wc-btn" data-action="view" title="View (F3)"><i class="fa-solid fa-eye"></i><span>View</span></button>
    <button class="wc-btn" data-action="edit" title="Edit (F4)"><i class="fa-solid fa-pen"></i><span>Edit</span></button>
    <button class="wc-btn" data-action="copy" title="Copy to other pane (F5)"><i class="fa-solid fa-copy"></i><span>Copy</span></button>
    <button class="wc-btn" data-action="move" title="Move to other pane (F6)"><i class="fa-solid fa-right-left"></i><span>Move</span></button>
    <button class="wc-btn" data-action="mkdir" title="New directory (F7)"><i class="fa-solid fa-folder-plus"></i><span>Folder</span></button>
    <button class="wc-btn danger" data-action="delete" title="Delete (F8)"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
    <span class="wc-toolbar-sep"></span>
    <button class="wc-btn" data-action="new-file" title="New text file"><i class="fa-solid fa-file-circle-plus"></i><span>New file</span></button>
    <button class="wc-btn" data-action="rename" title="Rename"><i class="fa-solid fa-i-cursor"></i><span>Rename</span></button>
    <button class="wc-btn" data-action="upload" title="Upload files"><i class="fa-solid fa-upload"></i><span>Upload</span></button>
    <button class="wc-btn" data-action="upload-folder" title="Upload folder"><i class="fa-solid fa-folder-arrow-up"></i><span>Folder upload</span></button>
    <button class="wc-btn" data-action="download" title="Download selection"><i class="fa-solid fa-download"></i><span>Download</span></button>
    <span class="wc-toolbar-sep"></span>
    <button class="wc-btn" data-action="archive" title="Create archive"><i class="fa-solid fa-file-zipper"></i><span>Archive</span></button>
    <button class="wc-btn" data-action="extract" title="Extract archive"><i class="fa-solid fa-box-open"></i><span>Extract</span></button>
    <button class="wc-btn" data-action="search" title="Find files"><i class="fa-solid fa-magnifying-glass"></i><span>Find</span></button>
    <button class="wc-btn" data-action="tree" title="Recursive folder tree and sizes"><i class="fa-solid fa-folder-tree"></i><span>Tree</span></button>
    <button class="wc-btn" data-action="compare" title="Compare pane directories"><i class="fa-solid fa-code-compare"></i><span>Compare</span></button>
    <span class="wc-toolbar-sep"></span>
    <button class="wc-btn" data-action="properties" title="Properties"><i class="fa-solid fa-circle-info"></i><span>Properties</span></button>
    <button class="wc-btn" data-action="permissions" title="Permissions and ownership"><i class="fa-solid fa-user-shield"></i><span>Permissions</span></button>
    <button class="wc-btn" data-action="touch" title="Change modification time"><i class="fa-solid fa-clock"></i><span>Touch</span></button>
    <button class="wc-btn" data-action="link" title="Create symbolic or hard link"><i class="fa-solid fa-link"></i><span>Link</span></button>
    <button class="wc-btn" data-action="checksum" title="Checksums"><i class="fa-solid fa-fingerprint"></i><span>Checksum</span></button>
    <button class="wc-btn" data-action="refresh" title="Refresh both panes"><i class="fa-solid fa-rotate"></i><span>Refresh</span></button>
  </nav>

  <main class="wc-panes">
    <?php foreach (['left', 'right'] as $paneId): ?>
    <section class="wc-pane<?= $paneId === 'left' ? ' active' : '' ?>" id="pane-<?= $paneId ?>" data-pane="<?= $paneId ?>">
      <div class="wc-pane-head">
        <button class="wc-btn pane-home" title="Root"><i class="fa-solid fa-house"></i></button>
        <button class="wc-btn pane-up" title="Parent directory"><i class="fa-solid fa-arrow-up"></i></button>
        <input class="wc-path" aria-label="<?= ucfirst($paneId) ?> pane path" value="/" spellcheck="false">
        <button class="wc-btn pane-refresh" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
      </div>
      <div class="wc-pane-tools">
        <input class="wc-filter" placeholder="Filter this pane…" aria-label="Filter files">
        <label class="wc-check-label" title="Show dotfiles"><input class="show-hidden" type="checkbox"> Hidden</label>
        <button class="wc-btn select-pattern" title="Select by pattern"><i class="fa-solid fa-check-double"></i></button>
        <button class="wc-btn invert-selection" title="Invert selection"><i class="fa-solid fa-shuffle"></i></button>
      </div>
      <div class="wc-table-wrap">
        <table class="wc-table">
          <thead><tr><th><input class="select-all" type="checkbox" aria-label="Select all"></th><th class="name" data-sort="name">Name</th><th class="size" data-sort="size">Size</th><th class="date" data-sort="mtime">Modified</th><th class="perms" data-sort="mode">Mode</th></tr></thead>
          <tbody></tbody>
        </table>
        <div class="wc-empty" hidden>No items</div>
      </div>
      <div class="wc-pane-status"><span class="pane-count">Loading…</span><span class="pane-selection"></span></div>
    </section>
    <?php endforeach; ?>
  </main>

  <div class="wc-progress-row" id="progressRow"><span id="progressLabel">Working…</span><div class="wc-progress-track"><div class="wc-progress-bar" id="progressBar"></div></div><span id="progressPercent">0%</span></div>
  <div class="wc-global-status"><i class="fa-solid fa-terminal"></i><span class="message" id="globalStatus">Ready</span><span id="clock"></span></div>
  <footer class="wc-keys">
    <button class="wc-key" data-action="view"><b>F3</b>View</button>
    <button class="wc-key" data-action="edit"><b>F4</b>Edit</button>
    <button class="wc-key" data-action="copy"><b>F5</b>Copy</button>
    <button class="wc-key" data-action="move"><b>F6</b>Move</button>
    <button class="wc-key" data-action="mkdir"><b>F7</b>Folder</button>
    <button class="wc-key" data-action="delete"><b>F8</b>Delete</button>
    <button class="wc-key" data-action="password"><b>F9</b>Password</button>
    <button class="wc-key" data-action="logout"><b>F10</b>Logout</button>
  </footer>
</div>

<dialog class="wc-dialog" id="mainDialog">
  <form id="dialogForm">
    <div class="wc-dialog-head"><span id="dialogTitle">Dialog</span><button class="wc-dialog-close" type="button" data-dialog-close aria-label="Close">&times;</button></div>
    <div class="wc-dialog-body" id="dialogBody"></div>
    <div class="wc-dialog-actions" id="dialogActions"><button class="wc-btn" type="button" data-dialog-close>Cancel</button><button class="wc-btn primary" id="dialogSubmit" type="submit">OK</button></div>
  </form>
</dialog>

<div class="wc-context" id="contextMenu">
  <button data-action="view"><i class="fa-solid fa-eye fa-fw me-2"></i>View</button>
  <button data-action="edit"><i class="fa-solid fa-pen fa-fw me-2"></i>Edit</button>
  <button data-action="copy"><i class="fa-solid fa-copy fa-fw me-2"></i>Copy</button>
  <button data-action="move"><i class="fa-solid fa-right-left fa-fw me-2"></i>Move</button>
  <button data-action="rename"><i class="fa-solid fa-i-cursor fa-fw me-2"></i>Rename</button>
  <button data-action="properties"><i class="fa-solid fa-circle-info fa-fw me-2"></i>Properties</button>
  <button data-action="tree-selected" id="contextTree"><i class="fa-solid fa-folder-tree fa-fw me-2"></i>Tree</button>
  <button data-action="delete"><i class="fa-solid fa-trash fa-fw me-2"></i>Delete</button>
</div>

<input id="fileUpload" type="file" multiple hidden>
<input id="folderUpload" type="file" webkitdirectory multiple hidden>
<div class="wc-toast-area" id="toastArea" aria-live="polite"></div>

<script>
'use strict';

const WC = {
  csrf: <?= json_encode($csrf) ?>,
  root: <?= json_encode(MC_ROOT_REAL, JSON_UNESCAPED_SLASHES) ?>,
  diskFree: <?= json_encode($diskFree === false ? null : (float)$diskFree) ?>,
  diskTotal: <?= json_encode($diskTotal === false ? null : (float)$diskTotal) ?>,
  active: 'left',
  panes: {},
  busy: 0
};

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
}

function formatBytes(value) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '';
  const bytes = Number(value);
  if (bytes === 0) return '0 B';
  const units = ['B','KB','MB','GB','TB','PB'];
  const index = Math.min(units.length - 1, Math.floor(Math.log(Math.abs(bytes)) / Math.log(1024)));
  return (bytes / Math.pow(1024, index)).toLocaleString(undefined, {maximumFractionDigits: index ? 1 : 0}) + ' ' + units[index];
}

function formatDate(timestamp) {
  if (!timestamp) return '—';
  const date = new Date(Number(timestamp) * 1000);
  const pad = value => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fullPathDisplay(path) {
  return '/' + (path || '');
}

function parentPath(path) {
  const parts = String(path || '').split('/').filter(Boolean);
  parts.pop();
  return parts.join('/');
}

function baseName(path) {
  const parts = String(path || '').split('/').filter(Boolean);
  return parts.pop() || '';
}

function joinPath(dir, name) {
  return dir ? `${dir}/${name}` : name;
}

function fileIcon(item) {
  if (item.type === 'dir' || item.navigable) return '<i class="fa-solid fa-folder wc-folder"></i>';
  if (item.type === 'link') return '<i class="fa-solid fa-link wc-link"></i>';
  const ext = item.name.includes('.') ? item.name.split('.').pop().toLowerCase() : '';
  const map = {
    php:'fa-file-code', html:'fa-file-code', htm:'fa-file-code', js:'fa-file-code', css:'fa-file-code', json:'fa-file-code', xml:'fa-file-code',
    jpg:'fa-file-image', jpeg:'fa-file-image', png:'fa-file-image', gif:'fa-file-image', webp:'fa-file-image', svg:'fa-file-image',
    zip:'fa-file-zipper', tar:'fa-file-zipper', gz:'fa-file-zipper', tgz:'fa-file-zipper', rar:'fa-file-zipper', '7z':'fa-file-zipper',
    pdf:'fa-file-pdf', doc:'fa-file-word', docx:'fa-file-word', xls:'fa-file-excel', xlsx:'fa-file-excel', csv:'fa-file-csv',
    mp3:'fa-file-audio', wav:'fa-file-audio', ogg:'fa-file-audio', mp4:'fa-file-video', webm:'fa-file-video', mov:'fa-file-video'
  };
  return `<i class="fa-solid ${map[ext] || 'fa-file'} wc-file"></i>`;
}

function setStatus(message) {
  $('#globalStatus').textContent = message;
}

function toast(message, error = false, timeout = 3500) {
  const node = document.createElement('div');
  node.className = 'wc-toast' + (error ? ' error' : '');
  node.textContent = message;
  $('#toastArea').appendChild(node);
  setTimeout(() => node.remove(), timeout);
}

const THEME_IDS = new Set(['norton', 'midnight', 'solarized-dark', 'solarized-light', 'nord', 'gruvbox', 'dracula', 'monokai', 'forest', 'paper', 'amber']);
const FONT_IDS = new Set(['lucida', 'consolas', 'cascadia', 'jetbrains', 'fira', 'source-code', 'ibm-plex', 'roboto', 'ubuntu', 'courier']);

function applyTheme(theme, remember = true) {
  const nextTheme = THEME_IDS.has(theme) ? theme : 'norton';
  document.documentElement.dataset.theme = nextTheme;
  $('#themeSelect').value = nextTheme;
  if (remember) {
    try { localStorage.setItem('webcommander-theme', nextTheme); } catch (error) {}
  }
}

function applyFont(font, remember = true) {
  const nextFont = FONT_IDS.has(font) ? font : 'lucida';
  document.documentElement.dataset.font = nextFont;
  $('#fontSelect').value = nextFont;
  if (remember) {
    try { localStorage.setItem('webcommander-font', nextFont); } catch (error) {}
  }
}

function applyFontSize(value, remember = true) {
  const parsed = Number.parseInt(String(value), 10);
  const nextSize = Number.isInteger(parsed) && parsed >= 6 && parsed <= 24 ? parsed : 13;
  document.documentElement.dataset.fontSize = String(nextSize);
  document.documentElement.style.setProperty('--wc-font-size', nextSize + 'px');
  $('#fontSizeSelect').value = String(nextSize);
  if (remember) {
    try { localStorage.setItem('webcommander-font-size', String(nextSize)); } catch (error) {}
  }
}

applyTheme(document.documentElement.dataset.theme, false);
applyFont(document.documentElement.dataset.font, false);
applyFontSize(document.documentElement.dataset.fontSize, false);
$('#themeSelect').addEventListener('change', event => {
  applyTheme(event.target.value);
  toast(`Theme: ${event.target.selectedOptions[0].textContent}`);
});
$('#fontSelect').addEventListener('change', event => {
  applyFont(event.target.value);
  toast(`Font: ${event.target.selectedOptions[0].textContent}`);
});
$('#fontSizeSelect').addEventListener('change', event => {
  applyFontSize(event.target.value);
  toast(`Font size: ${event.target.value}px`);
});

function setBusy(on, message = 'Working…') {
  WC.busy += on ? 1 : -1;
  WC.busy = Math.max(0, WC.busy);
  if (on) setStatus(message);
  else if (WC.busy === 0) setStatus('Ready');
  document.body.style.cursor = WC.busy ? 'progress' : '';
}

async function api(action, data = {}) {
  setBusy(true, `${action.replaceAll('_', ' ')}…`);
  try {
    const response = await fetch(`?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json', 'X-CSRF-Token':WC.csrf, 'Accept':'application/json'},
      body: JSON.stringify({...data, csrf: WC.csrf})
    });
    const text = await response.text();
    let result;
    try { result = JSON.parse(text); }
    catch (e) { throw new Error(text || `HTTP ${response.status}`); }
    if (response.status === 401) {
      location.reload();
      throw new Error('Session expired.');
    }
    if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
    return result;
  } finally {
    setBusy(false);
  }
}

function rawUrl(path, download = false) {
  return `?action=${download ? 'download' : 'raw'}&path=${encodeURIComponent(path)}&csrf=${encodeURIComponent(WC.csrf)}`;
}

class Pane {
  constructor(id) {
    this.id = id;
    this.el = $(`#pane-${id}`);
    this.body = $('tbody', this.el);
    this.path = '';
    this.parent = '';
    this.items = [];
    this.visibleItems = [];
    this.selected = new Set();
    this.focused = null;
    this.anchorIndex = null;
    this.sort = 'name';
    this.direction = 1;
    this.filter = '';
    this.showHidden = false;
    this.bind();
  }

  bind() {
    this.el.addEventListener('mousedown', () => this.activate());
    $('.pane-home', this.el).addEventListener('click', () => this.load(''));
    $('.pane-up', this.el).addEventListener('click', () => this.load(this.parent));
    $('.pane-refresh', this.el).addEventListener('click', () => this.load(this.path));
    const pathInput = $('.wc-path', this.el);
    pathInput.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        this.load(pathInput.value.replace(/^\/+|\/+$/g, ''));
      }
    });
    $('.wc-filter', this.el).addEventListener('input', event => { this.filter = event.target.value; this.render(); });
    $('.show-hidden', this.el).addEventListener('change', event => { this.showHidden = event.target.checked; this.render(); });
    $('.select-all', this.el).addEventListener('change', event => {
      if (event.target.checked) this.visibleItems.forEach(item => this.selected.add(item.path));
      else this.visibleItems.forEach(item => this.selected.delete(item.path));
      this.renderRowsOnly();
    });
    $('.select-pattern', this.el).addEventListener('click', () => this.selectPattern());
    $('.invert-selection', this.el).addEventListener('click', () => {
      this.visibleItems.forEach(item => this.selected.has(item.path) ? this.selected.delete(item.path) : this.selected.add(item.path));
      this.renderRowsOnly();
    });
    $$('th[data-sort]', this.el).forEach(th => th.addEventListener('click', () => {
      const field = th.dataset.sort;
      if (this.sort === field) this.direction *= -1;
      else { this.sort = field; this.direction = 1; }
      this.render();
    }));

    this.body.addEventListener('click', event => this.handleRowClick(event));
    this.body.addEventListener('dblclick', event => this.handleDoubleClick(event));
    this.body.addEventListener('contextmenu', event => this.handleContext(event));
    this.body.addEventListener('dragstart', event => this.handleDragStart(event));
    this.body.addEventListener('dragover', event => this.handleDragOver(event));
    this.body.addEventListener('dragleave', event => this.handleDragLeave(event));
    this.body.addEventListener('drop', event => this.handleDrop(event));
    const wrap = $('.wc-table-wrap', this.el);
    wrap.addEventListener('dragover', event => { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; });
    wrap.addEventListener('drop', event => {
      if (event.target.closest('tr')) return;
      event.preventDefault();
      this.dropData(event, this.path);
    });
  }

  activate() {
    WC.active = this.id;
    Object.values(WC.panes).forEach(pane => pane.el.classList.toggle('active', pane.id === this.id));
    this.updateStatus();
  }

  async load(path = this.path) {
    this.activate();
    try {
      const result = await api('list', {path});
      this.path = result.path;
      this.parent = result.parent;
      this.items = result.items;
      this.selected.clear();
      this.focused = null;
      this.anchorIndex = null;
      $('.wc-path', this.el).value = fullPathDisplay(this.path);
      this.render();
      setStatus(`${this.id === 'left' ? 'Left' : 'Right'}: ${fullPathDisplay(this.path)}`);
    } catch (error) {
      toast(error.message, true, 6000);
      $('.wc-path', this.el).value = fullPathDisplay(this.path);
    }
  }

  render() {
    const needle = this.filter.toLocaleLowerCase();
    this.visibleItems = this.items.filter(item => {
      if (!this.showHidden && item.name.startsWith('.')) return false;
      return !needle || item.name.toLocaleLowerCase().includes(needle);
    });
    const field = this.sort;
    const direction = this.direction;
    this.visibleItems.sort((a, b) => {
      const ad = (a.type === 'dir' || a.navigable) ? 0 : 1;
      const bd = (b.type === 'dir' || b.navigable) ? 0 : 1;
      if (ad !== bd) return ad - bd;
      let av = a[field], bv = b[field];
      if (field === 'name' || field === 'mode') return String(av ?? '').localeCompare(String(bv ?? ''), undefined, {numeric:true, sensitivity:'base'}) * direction;
      return ((Number(av) || 0) - (Number(bv) || 0)) * direction;
    });
    this.renderRowsOnly();
  }

  renderRowsOnly() {
    const rows = [];
    if (this.path !== '') {
      rows.push(`<tr class="wc-row wc-parent" data-parent="1"><td></td><td colspan="4" class="wc-name"><span class="wc-icon"><i class="fa-solid fa-turn-up wc-up"></i></span>..</td></tr>`);
    }
    this.visibleItems.forEach((item, index) => {
      const selected = this.selected.has(item.path);
      const title = item.type === 'link' ? `${item.name} → ${item.linkTarget || '?'}` : item.name;
      rows.push(`<tr class="wc-row${selected ? ' selected' : ''}${this.focused === item.path ? ' focused' : ''}${item.protected ? ' protected' : ''}" data-index="${index}" data-path="${escapeHtml(item.path)}" draggable="true" title="${escapeHtml(title)}">
        <td><input class="row-check" type="checkbox" ${selected ? 'checked' : ''} aria-label="Select ${escapeHtml(item.name)}"></td>
        <td class="wc-name"><span class="wc-icon">${fileIcon(item)}</span>${escapeHtml(item.name)}${item.type === 'link' ? ` <span class="text-secondary">→ ${escapeHtml(item.linkTarget || '?')}</span>` : ''}</td>
        <td class="size">${item.type === 'file' ? formatBytes(item.size) : item.type === 'dir' ? '&lt;DIR&gt;' : '&lt;LINK&gt;'}</td>
        <td class="date">${formatDate(item.mtime)}</td>
        <td class="perms">${escapeHtml(item.mode)}</td>
      </tr>`);
    });
    this.body.innerHTML = rows.join('');
    $('.wc-empty', this.el).hidden = rows.length !== 0;
    $('.select-all', this.el).checked = this.visibleItems.length > 0 && this.visibleItems.every(item => this.selected.has(item.path));
    $('.select-all', this.el).indeterminate = this.visibleItems.some(item => this.selected.has(item.path)) && !$('.select-all', this.el).checked;
    this.updateStatus();
  }

  updateStatus() {
    const selectedItems = this.items.filter(item => this.selected.has(item.path));
    const selectedSize = selectedItems.reduce((sum, item) => sum + (Number(item.size) || 0), 0);
    $('.pane-count', this.el).textContent = `${this.visibleItems.length}/${this.items.length} items`;
    $('.pane-selection', this.el).textContent = selectedItems.length ? `• ${selectedItems.length} selected${selectedSize ? `, ${formatBytes(selectedSize)}` : ''}` : '';
    if (WC.active === this.id) setStatus(`${fullPathDisplay(this.path)}${selectedItems.length ? ` — ${selectedItems.length} selected` : ''}`);
  }

  rowItem(row) {
    const index = Number(row.dataset.index);
    return Number.isInteger(index) ? this.visibleItems[index] : null;
  }

  handleRowClick(event) {
    this.activate();
    const row = event.target.closest('tr');
    if (!row || row.dataset.parent) return;
    const item = this.rowItem(row);
    if (!item) return;
    const index = Number(row.dataset.index);
    if (event.shiftKey && this.anchorIndex !== null) {
      if (!event.ctrlKey && !event.metaKey) this.selected.clear();
      const start = Math.min(this.anchorIndex, index), end = Math.max(this.anchorIndex, index);
      for (let i = start; i <= end; i++) this.selected.add(this.visibleItems[i].path);
    } else if (event.ctrlKey || event.metaKey || event.target.matches('.row-check')) {
      this.selected.has(item.path) ? this.selected.delete(item.path) : this.selected.add(item.path);
      this.anchorIndex = index;
    } else {
      this.selected.clear();
      this.selected.add(item.path);
      this.anchorIndex = index;
    }
    this.focused = item.path;
    this.renderRowsOnly();
  }

  handleDoubleClick(event) {
    const row = event.target.closest('tr');
    if (!row) return;
    if (row.dataset.parent) {
      this.load(this.parent);
      return;
    }
    const item = this.rowItem(row);
    if (!item) return;
    if (item.type === 'dir' || item.navigable) this.load(item.path);
    else runAction('view');
  }

  handleContext(event) {
    event.preventDefault();
    this.activate();
    const row = event.target.closest('tr');
    if (!row || row.dataset.parent) return;
    const item = this.rowItem(row);
    if (!item) return;
    if (!this.selected.has(item.path)) {
      this.selected.clear();
      this.selected.add(item.path);
    }
    this.focused = item.path;
    this.renderRowsOnly();
    showContext(event.clientX, event.clientY, item.type === 'dir' || item.navigable);
  }

  handleDragStart(event) {
    const row = event.target.closest('tr');
    if (!row || row.dataset.parent) return;
    const item = this.rowItem(row);
    if (!item) return;
    if (!this.selected.has(item.path)) {
      this.selected.clear();
      this.selected.add(item.path);
      this.focused = item.path;
      this.renderRowsOnly();
    }
    event.dataTransfer.setData('application/x-webcommander', JSON.stringify({pane:this.id, paths:[...this.selected]}));
    event.dataTransfer.effectAllowed = 'copyMove';
  }

  handleDragOver(event) {
    const row = event.target.closest('tr');
    if (!row) return;
    const item = row.dataset.parent ? {navigable:true} : this.rowItem(row);
    if (!item || (!row.dataset.parent && item.type !== 'dir' && !item.navigable)) return;
    event.preventDefault();
    row.classList.add('drop-target');
  }

  handleDragLeave(event) {
    const row = event.target.closest('tr');
    if (row) row.classList.remove('drop-target');
  }

  handleDrop(event) {
    const row = event.target.closest('tr');
    if (!row) return;
    row.classList.remove('drop-target');
    const item = row.dataset.parent ? null : this.rowItem(row);
    const destination = row.dataset.parent ? this.parent : (item && (item.type === 'dir' || item.navigable) ? item.path : this.path);
    if (destination === null) return;
    event.preventDefault();
    event.stopPropagation();
    this.dropData(event, destination);
  }

  async dropData(event, destination) {
    const internal = event.dataTransfer.getData('application/x-webcommander');
    if (internal) {
      try {
        const transfer = JSON.parse(internal);
        const values = await showForm('Drop items', [
          {name:'operation', label:`Operation to ${fullPathDisplay(destination)}`, type:'select', value: transfer.pane === this.id ? 'move' : 'copy', options:[['copy','Copy'],['move','Move']]},
          collisionField()
        ]);
        if (!values) return;
        await api('transfer', {paths:transfer.paths, destination, operation:values.operation, collision:values.collision});
        toast(`${transfer.paths.length} item(s) ${values.operation === 'move' ? 'moved' : 'copied'}.`);
        await reloadBoth();
      } catch (error) { toast(error.message, true, 6000); }
      return;
    }
    if (event.dataTransfer.files && event.dataTransfer.files.length) {
      uploadFiles(event.dataTransfer.files, destination);
    }
  }

  async selectPattern() {
    const values = await showForm('Select by pattern', [
      {name:'pattern', label:'Pattern (* and ? wildcards)', value:'*'},
      {name:'mode', label:'Selection mode', type:'select', value:'add', options:[['add','Add matches'],['remove','Remove matches'],['replace','Replace selection']]}
    ]);
    if (!values) return;
    const regex = new RegExp('^' + values.pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replaceAll('*', '.*').replaceAll('?', '.') + '$', 'i');
    if (values.mode === 'replace') this.selected.clear();
    this.visibleItems.forEach(item => {
      if (regex.test(item.name)) values.mode === 'remove' ? this.selected.delete(item.path) : this.selected.add(item.path);
    });
    this.renderRowsOnly();
  }

  getSelected(require = true) {
    const result = this.items.filter(item => this.selected.has(item.path));
    if (require && result.length === 0) throw new Error('Select at least one item.');
    return result;
  }
}

const dialog = $('#mainDialog');
let dialogResolver = null;

function fieldHtml(field) {
  if (field.type === 'html') return `<div class="wc-field">${field.html || ''}</div>`;
  if (field.type === 'checkbox') return `<div class="wc-field"><label class="wc-check"><input type="checkbox" name="${escapeHtml(field.name)}" ${field.value ? 'checked' : ''}> ${escapeHtml(field.label)}</label>${field.help ? `<div class="wc-result-note mt-1">${escapeHtml(field.help)}</div>` : ''}</div>`;
  if (field.type === 'select') {
    const options = (field.options || []).map(option => `<option value="${escapeHtml(option[0])}" ${String(option[0]) === String(field.value ?? '') ? 'selected' : ''}>${escapeHtml(option[1])}</option>`).join('');
    return `<div class="wc-field"><label>${escapeHtml(field.label)}</label><select class="wc-select" name="${escapeHtml(field.name)}">${options}</select></div>`;
  }
  if (field.type === 'textarea') return `<div class="wc-field"><label>${escapeHtml(field.label)}</label><textarea class="wc-textarea" name="${escapeHtml(field.name)}" ${field.required ? 'required' : ''} spellcheck="false">${escapeHtml(field.value ?? '')}</textarea></div>`;
  return `<div class="wc-field"><label>${escapeHtml(field.label)}</label><input class="wc-input" name="${escapeHtml(field.name)}" type="${escapeHtml(field.type || 'text')}" value="${escapeHtml(field.value ?? '')}" ${field.placeholder ? `placeholder="${escapeHtml(field.placeholder)}"` : ''} ${field.required ? 'required' : ''} ${field.minlength ? `minlength="${field.minlength}"` : ''} autocomplete="${field.type === 'password' ? 'new-password' : 'off'}">${field.help ? `<div class="wc-result-note mt-1">${escapeHtml(field.help)}</div>` : ''}</div>`;
}

function showForm(title, fields, options = {}) {
  if (dialog.open) dialog.close();
  dialog.classList.toggle('wide', !!options.wide);
  $('#dialogTitle').textContent = title;
  $('#dialogBody').innerHTML = fields.map(fieldHtml).join('');
  $('#dialogActions').hidden = false;
  $('#dialogSubmit').textContent = options.submitLabel || 'OK';
  $('#dialogSubmit').classList.toggle('danger', !!options.danger);
  dialog.showModal();
  const first = $('.wc-input, .wc-select, .wc-textarea', $('#dialogBody'));
  if (first) setTimeout(() => first.focus(), 30);
  return new Promise(resolve => { dialogResolver = resolve; });
}

function showContent(title, html, options = {}) {
  if (dialog.open) dialog.close();
  dialog.classList.toggle('wide', options.wide !== false);
  $('#dialogTitle').textContent = title;
  $('#dialogBody').innerHTML = html;
  $('#dialogActions').hidden = true;
  dialog.showModal();
  dialogResolver = null;
}

function closeDialog(value = null) {
  if (dialog.open) dialog.close();
  if (dialogResolver) {
    const resolve = dialogResolver;
    dialogResolver = null;
    resolve(value);
  }
}

$('#dialogForm').addEventListener('submit', event => {
  event.preventDefault();
  if (!dialogResolver) return;
  const data = {};
  new FormData(event.currentTarget).forEach((value, key) => { data[key] = value; });
  $$('input[type=checkbox][name]', event.currentTarget).forEach(input => data[input.name] = input.checked);
  closeDialog(data);
});
$$('[data-dialog-close]').forEach(button => button.addEventListener('click', () => closeDialog(null)));
dialog.addEventListener('cancel', event => { event.preventDefault(); closeDialog(null); });

function collisionField() {
  return {name:'collision', label:'If destination exists', type:'select', value:'rename', options:[['rename','Keep both (automatic new name)'],['overwrite','Overwrite'],['skip','Skip']]};
}

function activePane() { return WC.panes[WC.active]; }
function otherPane() { return WC.panes[WC.active === 'left' ? 'right' : 'left']; }

async function reloadBoth() {
  await Promise.all([WC.panes.left.load(WC.panes.left.path), WC.panes.right.load(WC.panes.right.path)]);
  activePane().activate();
}

function selectedPaths() {
  return activePane().getSelected().map(item => item.path);
}

async function actionView() {
  const item = activePane().getSelected()[0];
  if (item.type === 'dir' || item.navigable) { activePane().load(item.path); return; }
  try {
    const props = await api('properties', {paths:[item.path]});
    const mime = props.details[0]?.mime || 'application/octet-stream';
    const url = rawUrl(item.path);
    if (mime.startsWith('image/')) showContent(item.name, `<div class="wc-viewer"><img src="${escapeHtml(url)}" alt="${escapeHtml(item.name)}"></div>`);
    else if (mime === 'application/pdf') showContent(item.name, `<div class="wc-viewer"><iframe src="${escapeHtml(url)}"></iframe></div>`);
    else if (mime.startsWith('audio/')) showContent(item.name, `<div class="wc-viewer"><audio controls autoplay src="${escapeHtml(url)}"></audio></div>`);
    else if (mime.startsWith('video/')) showContent(item.name, `<div class="wc-viewer"><video controls autoplay src="${escapeHtml(url)}"></video></div>`);
    else {
      const result = await api('read_text', {path:item.path});
      showContent(item.name, `<div class="wc-viewer"><pre>${escapeHtml(result.content)}</pre></div>`);
    }
  } catch (error) {
    const html = `<div class="alert alert-warning mb-3">${escapeHtml(error.message)}</div><a class="wc-btn primary" href="${escapeHtml(rawUrl(item.path, true))}"><i class="fa-solid fa-download"></i> Download file</a>`;
    showContent(item.name, html, {wide:false});
  }
}

async function editPath(path, isNew = false) {
  try {
    let content = '';
    if (!isNew) content = (await api('read_text', {path})).content;
    const values = await showForm(`Edit: ${baseName(path)}`, [
      {name:'content', label:fullPathDisplay(path), type:'textarea', value:content},
      {name:'backup', label:'Create a backup before saving', type:'checkbox', value:!isNew}
    ], {wide:true, submitLabel:'Save'});
    if (!values) return;
    await api('save_text', {path, content:values.content, backup:values.backup});
    toast('File saved.');
    await activePane().load(activePane().path);
  } catch (error) { toast(error.message, true, 6000); }
}

async function actionEdit() {
  const item = activePane().getSelected()[0];
  if (item.type !== 'file') throw new Error('Select a regular text file.');
  if (item.protected) throw new Error('This application file is protected from editing.');
  await editPath(item.path);
}

async function actionTransfer(operation) {
  const paths = selectedPaths();
  const destinationDefault = otherPane().path;
  const values = await showForm(operation === 'copy' ? 'Copy items' : 'Move items', [
    {name:'destination', label:'Destination directory (relative to root)', value:destinationDefault, placeholder:'folder/subfolder'},
    collisionField()
  ], {submitLabel:operation === 'copy' ? 'Copy' : 'Move'});
  if (!values) return;
  await api('transfer', {paths, destination:values.destination.replace(/^\/+|\/+$/g,''), operation, collision:values.collision});
  toast(`${paths.length} item(s) ${operation === 'copy' ? 'copied' : 'moved'}.`);
  await reloadBoth();
}

async function actionMkdir() {
  const values = await showForm('Create directory', [
    {name:'name', label:'Directory name', required:true},
    {name:'mode', label:'Permissions (octal)', value:'0775'}
  ], {submitLabel:'Create'});
  if (!values) return;
  await api('mkdir', {dir:activePane().path, name:values.name, mode:values.mode});
  toast('Directory created.');
  await activePane().load(activePane().path);
}

async function actionNewFile() {
  const values = await showForm('Create text file', [{name:'name', label:'File name', value:'new-file.txt', required:true}], {submitLabel:'Create and edit'});
  if (!values) return;
  const result = await api('new_file', {dir:activePane().path, name:values.name});
  await activePane().load(activePane().path);
  await editPath(result.path, true);
}

async function actionRename() {
  const item = activePane().getSelected()[0];
  const values = await showForm('Rename item', [{name:'name', label:'New name', value:item.name, required:true}], {submitLabel:'Rename'});
  if (!values || values.name === item.name) return;
  await api('rename', {path:item.path, name:values.name});
  toast('Item renamed.');
  await activePane().load(activePane().path);
}

async function actionDelete() {
  const items = activePane().getSelected();
  const list = items.slice(0, 12).map(item => `<li>${escapeHtml(item.name)}</li>`).join('') + (items.length > 12 ? `<li>…and ${items.length - 12} more</li>` : '');
  const values = await showForm('Permanently delete', [
    {type:'html', html:`<div class="alert alert-danger">This operation cannot be undone.</div><ul>${list}</ul>`},
    {name:'confirm', label:`Type DELETE to remove ${items.length} item(s)`, required:true}
  ], {submitLabel:'Delete', danger:true});
  if (!values) return;
  if (values.confirm !== 'DELETE') throw new Error('Deletion cancelled: confirmation text did not match.');
  await api('delete', {paths:items.map(item => item.path)});
  toast(`${items.length} item(s) deleted.`);
  await reloadBoth();
}

function actionUpload(folder = false) {
  const input = folder ? $('#folderUpload') : $('#fileUpload');
  input.value = '';
  input.click();
}

async function uploadFiles(files, destination = activePane().path) {
  if (!files || !files.length) return;
  const values = await showForm('Upload options', [collisionField()], {submitLabel:`Upload ${files.length} file(s)`});
  if (!values) return;
  const form = new FormData();
  form.append('csrf', WC.csrf);
  form.append('destination', destination);
  form.append('collision', values.collision);
  const relative = [];
  Array.from(files).forEach(file => {
    form.append('files[]', file, file.name);
    relative.push(file.webkitRelativePath || file.name);
  });
  form.append('relative_paths', JSON.stringify(relative));
  const row = $('#progressRow'), bar = $('#progressBar'), percent = $('#progressPercent');
  row.classList.add('show');
  $('#progressLabel').textContent = `Uploading ${files.length} file(s)…`;
  bar.style.width = '0%'; percent.textContent = '0%';
  try {
    const result = await new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '?action=upload');
      xhr.setRequestHeader('X-CSRF-Token', WC.csrf);
      xhr.upload.addEventListener('progress', event => {
        if (!event.lengthComputable) return;
        const value = Math.round(event.loaded / event.total * 100);
        bar.style.width = value + '%'; percent.textContent = value + '%';
      });
      xhr.addEventListener('load', () => {
        let data;
        try { data = JSON.parse(xhr.responseText); } catch (e) { reject(new Error(xhr.responseText || `HTTP ${xhr.status}`)); return; }
        if (xhr.status >= 200 && xhr.status < 300 && data.success) resolve(data);
        else reject(new Error(data.message || `HTTP ${xhr.status}`));
      });
      xhr.addEventListener('error', () => reject(new Error('Network error during upload.')));
      xhr.send(form);
    });
    const errors = result.results.filter(item => item.status === 'error').length;
    toast(`${result.results.length - errors} uploaded${errors ? `, ${errors} failed` : ''}.`, errors > 0, 5000);
    await reloadBoth();
  } catch (error) {
    toast(error.message, true, 7000);
  } finally {
    setTimeout(() => row.classList.remove('show'), 700);
  }
}

function actionDownload() {
  const paths = selectedPaths();
  const form = document.createElement('form');
  form.method = 'post';
  form.action = '?action=download_selection';
  form.style.display = 'none';
  const fields = {csrf:WC.csrf, paths:JSON.stringify(paths)};
  Object.entries(fields).forEach(([name,value]) => {
    const input = document.createElement('input');
    input.name = name; input.value = value;
    form.appendChild(input);
  });
  document.body.appendChild(form);
  form.submit();
  setTimeout(() => form.remove(), 1000);
}

async function actionArchive() {
  const items = activePane().getSelected();
  const stamp = new Date().toISOString().slice(0,10);
  const defaultName = items.length === 1 ? `${items[0].name}.zip` : `archive-${stamp}.zip`;
  const values = await showForm('Create archive', [
    {name:'destination', label:'Archive path (.zip, .tar, .tar.gz, .tgz)', value:joinPath(activePane().path, defaultName), required:true},
    collisionField()
  ], {submitLabel:'Create archive'});
  if (!values) return;
  const result = await api('archive_create', {paths:items.map(item => item.path), destination:values.destination.replace(/^\/+|\/+$/g,''), collision:values.collision});
  toast(`Archive created: ${result.path}`);
  await reloadBoth();
}

async function actionExtract() {
  const archives = activePane().getSelected().filter(item => item.type === 'file');
  if (!archives.length) throw new Error('Select at least one ZIP, TAR, TAR.GZ, or TGZ archive.');
  const values = await showForm('Extract archive(s)', [
    {name:'destination', label:'Destination directory', value:otherPane().path},
    collisionField()
  ], {submitLabel:'Extract'});
  if (!values) return;
  let count = 0;
  for (const archive of archives) {
    const result = await api('archive_extract', {path:archive.path, destination:values.destination.replace(/^\/+|\/+$/g,''), collision:values.collision});
    count += result.count;
  }
  toast(`${count} file(s) extracted.`);
  await reloadBoth();
}

async function actionSearch() {
  const pane = activePane();
  const values = await showForm('Find files', [
    {name:'query', label:'Filename contains (or regex)', value:''},
    {name:'content', label:'File content contains (files up to 2 MB)', value:''},
    {name:'regex', label:'Use regular expressions', type:'checkbox', value:false},
    {name:'case', label:'Case-sensitive', type:'checkbox', value:false},
    {name:'hidden', label:'Include hidden files and folders', type:'checkbox', value:false}
  ], {submitLabel:'Search'});
  if (!values) return;
  const result = await api('search', {base:pane.path, ...values});
  const rows = result.results.map(item => `<div class="wc-result" data-result-path="${escapeHtml(item.path)}" data-result-type="${escapeHtml(item.type)}"><div><div class="wc-result-path">${fileIcon(item)} ${escapeHtml(fullPathDisplay(item.path))}</div>${item.snippet ? `<div class="wc-result-note">${escapeHtml(item.snippet)}</div>` : ''}</div><div class="wc-result-note">${item.type === 'file' ? formatBytes(item.size) : item.type}</div></div>`).join('');
  showContent(`Search results (${result.results.length})`, `<div class="mb-2 text-secondary">Scanned ${result.scanned.toLocaleString()} items${result.limited ? ' — result limit reached' : ''}</div><div class="wc-result-list">${rows || '<div class="p-3 text-secondary">No matches.</div>'}</div>`);
  $$('.wc-result[data-result-path]', $('#dialogBody')).forEach(node => node.addEventListener('dblclick', async () => {
    const path = node.dataset.resultPath;
    const type = node.dataset.resultType;
    closeDialog();
    if (type === 'dir') pane.load(path);
    else {
      await pane.load(parentPath(path));
      pane.selected.add(path); pane.focused = path; pane.renderRowsOnly(); pane.activate();
      actionView();
    }
  }));
}

function largestTreeFolderSize(node) {
  const children = Array.isArray(node.children) ? node.children : [];
  return children.reduce((largest, child) => Math.max(largest, largestTreeFolderSize(child)), Math.max(0, Number(node.size) || 0));
}

function treeNodeHtml(node, largestSize, depth = 0) {
  const children = Array.isArray(node.children) ? node.children : [];
  const folderLabel = node.folders === 1 ? 'folder' : 'folders';
  const fileLabel = node.files === 1 ? 'file' : 'files';
  const classes = ['wc-tree-node'];
  if (!children.length) classes.push('leaf');
  if (node.unreadable) classes.push('unreadable');
  const size = Math.max(0, Number(node.size) || 0);
  const percent = largestSize > 0 ? Math.min(100, size / largestSize * 100) : 0;
  const percentText = percent > 0 && percent < 0.1 ? '<0.1%' : percent.toLocaleString(undefined, {maximumFractionDigits:1}) + '%';
  const open = depth === 0 ? ' open' : '';
  const childrenHtml = children.length ? '<div class="wc-tree-children">' + children.map(child => treeNodeHtml(child, largestSize, depth + 1)).join('') + '</div>' : '';
  const unreadable = node.unreadable ? ' · unreadable' : '';
  const usage = '<span class="wc-tree-usage" title="' + escapeHtml(percentText) + ' of the largest folder (' + escapeHtml(formatBytes(largestSize)) + ')"><span class="wc-tree-bar" aria-hidden="true"><span style="width:' + percent.toFixed(3) + '%"></span></span><span class="wc-tree-percent">' + escapeHtml(percentText) + '</span></span>';
  return '<details class="' + classes.join(' ') + '"' + open + '><summary data-tree-path="' + escapeHtml(node.path) + '" title="Double-click to open this folder"><span class="wc-tree-label"><i class="fa-solid fa-folder wc-folder"></i><span class="wc-tree-name">' + escapeHtml(node.name) + '</span></span>' + usage + '<span class="wc-tree-meta">' + formatBytes(size) + ' · ' + Number(node.folders).toLocaleString() + ' ' + folderLabel + ' · ' + Number(node.files).toLocaleString() + ' ' + fileLabel + unreadable + '</span></summary>' + childrenHtml + '</details>';
}

async function actionTree(basePath = null) {
  const pane = activePane();
  const treePath = basePath === null ? pane.path : basePath;
  const result = await api('tree', {path:treePath, hidden:pane.showHidden});
  const largestSize = largestTreeFolderSize(result.tree);
  const hiddenNote = result.hidden ? 'Hidden items included' : 'Hidden items excluded';
  const unreadable = result.unreadableCount ? '<div class="alert alert-warning py-2 mb-2">' + Number(result.unreadableCount).toLocaleString() + ' item(s) could not be fully read, so affected totals may be incomplete.</div>' : '';
  const tools = '<div class="wc-tree-tools"><button class="wc-btn" id="treeExpandAll" type="button"><i class="fa-solid fa-angles-down"></i> Expand all</button><button class="wc-btn" id="treeCollapseAll" type="button"><i class="fa-solid fa-angles-up"></i> Collapse all</button><div class="wc-result-note">' + formatBytes(result.tree.size) + ' · ' + Number(result.folderCount).toLocaleString() + ' folders · ' + Number(result.fileCount).toLocaleString() + ' files · ' + hiddenNote + '</div></div>';
  const help = '<div class="wc-result-note mb-2">Each bar is relative to the largest folder (100%). Subfolders start collapsed. Sizes include the full visible subtree; symlinks are not followed. Double-click a folder to open it; Shift + double-click opens it in the other pane.</div>';
  showContent('Folder tree: ' + fullPathDisplay(result.base), tools + help + unreadable + '<div class="wc-tree-view" id="treeView">' + treeNodeHtml(result.tree, largestSize) + '</div>');

  const treeView = $('#treeView');
  $('#treeExpandAll').addEventListener('click', () => $$('details', treeView).forEach(node => { node.open = true; }));
  $('#treeCollapseAll').addEventListener('click', () => $$('details', treeView).forEach((node, index) => { node.open = index === 0; }));
  $$('.wc-tree-node > summary[data-tree-path]', treeView).forEach(summary => {
    summary.addEventListener('dblclick', event => {
      event.preventDefault();
      event.stopPropagation();
      const targetPane = event.shiftKey ? otherPane() : pane;
      const path = summary.dataset.treePath || '';
      closeDialog();
      targetPane.load(path);
    });
  });
}

async function actionTreeSelected() {
  const pane = activePane();
  const item = pane.items.find(candidate => candidate.path === pane.focused);
  if (!item || (item.type !== 'dir' && !item.navigable)) {
    throw new Error('Right-click a folder to view its tree.');
  }
  await actionTree(item.path);
}

async function actionCompare() {
  const values = await showForm('Compare pane directories', [
    {name:'mode', label:'Comparison mode', type:'select', value:'quick', options:[['quick','Quick (type, size and modified time)'],['checksum','Thorough (SHA-256 content)']]}
  ], {submitLabel:'Compare'});
  if (!values) return;
  const result = await api('compare', {left:WC.panes.left.path, right:WC.panes.right.path, mode:values.mode});
  const section = (title, values, color) => `<h3 class="h6 mt-3" style="color:${color}">${escapeHtml(title)} (${values.length})</h3><div class="wc-result-list">${values.length ? values.map(value => `<div class="wc-result"><span class="wc-result-path">${escapeHtml(value)}</span></div>`).join('') : '<div class="p-2 text-secondary">None</div>'}</div>`;
  showContent('Directory comparison', `<div class="text-secondary">Same entries: ${Math.max(0,result.same)}</div>${section('Only in left',result.onlyLeft,'var(--wc-accent)')}${section('Only in right',result.onlyRight,'var(--wc-success)')}${section('Different',result.different,'var(--wc-warning)')}`);
}

async function actionProperties() {
  const result = await api('properties', {paths:selectedPaths()});
  const rows = result.details.map(info => `<tr><th>${escapeHtml(info.name)}</th><td><div class="wc-mono">${escapeHtml(fullPathDisplay(info.path))}</div><div class="mt-1">${escapeHtml(info.type)} · ${escapeHtml(info.mime)} · ${formatBytes(info.calculatedSize)} · ${info.itemCount.toLocaleString()} item(s)</div><div class="mt-1 wc-mono">${escapeHtml(info.permissions)} (${escapeHtml(info.mode)}) · ${escapeHtml(info.owner)}:${escapeHtml(info.group)} · modified ${formatDate(info.mtime)}</div>${info.linkTarget ? `<div class="mt-1 wc-mono">→ ${escapeHtml(info.linkTarget)}</div>` : ''}</td></tr>`).join('');
  showContent('Properties', `<div class="mb-2">Total: <strong>${formatBytes(result.totalSize)}</strong> in <strong>${result.totalItems.toLocaleString()}</strong> item(s)</div><table class="wc-info-table">${rows}</table>`);
}

async function actionPermissions() {
  const items = activePane().getSelected();
  const first = items[0];
  const values = await showForm('Permissions and ownership', [
    {name:'mode', label:'Permissions (octal; blank leaves unchanged)', value:first.mode || ''},
    {name:'owner', label:'Owner name or UID (blank leaves unchanged)', value:''},
    {name:'group', label:'Group name or GID (blank leaves unchanged)', value:''},
    {name:'recursive', label:'Apply recursively inside selected directories', type:'checkbox', value:false}
  ], {submitLabel:'Apply'});
  if (!values) return;
  const result = await api('chmod_chown', {paths:items.map(item => item.path), ...values});
  toast(`Updated ${result.count} item(s).`);
  await reloadBoth();
}

async function actionTouch() {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  const values = await showForm('Change modification time', [{name:'time', label:'Date and time', type:'datetime-local', value:now.toISOString().slice(0,16), required:true}], {submitLabel:'Apply'});
  if (!values) return;
  await api('touch', {paths:selectedPaths(), time:values.time});
  toast('Modification time updated.');
  await reloadBoth();
}

async function actionLink() {
  const item = activePane().getSelected()[0];
  const defaultName = item.name + '.link';
  const values = await showForm('Create link in the other pane', [
    {name:'name', label:'Link name', value:defaultName, required:true},
    {name:'type', label:'Link type', type:'select', value:'symbolic', options:[['symbolic','Symbolic link'],['hard','Hard link (regular files only)']]},
    {type:'html', html:`<div class="wc-result-note">Target: ${escapeHtml(fullPathDisplay(item.path))}<br>Location: ${escapeHtml(fullPathDisplay(otherPane().path))}</div>`}
  ], {submitLabel:'Create link'});
  if (!values) return;
  await api('link', {target:item.path, dir:otherPane().path, name:values.name, type:values.type});
  toast('Link created.');
  await reloadBoth();
}

async function actionChecksum() {
  const values = await showForm('Calculate checksums', [{name:'algorithm', label:'Algorithm', type:'select', value:'sha256', options:[['sha256','SHA-256'],['sha512','SHA-512'],['sha1','SHA-1'],['md5','MD5']]}], {submitLabel:'Calculate'});
  if (!values) return;
  const result = await api('checksum', {paths:selectedPaths(), algorithm:values.algorithm});
  const rows = result.results.map(item => `<tr><th>${escapeHtml(item.path)}</th><td class="wc-mono">${item.hash ? escapeHtml(item.hash) : `<span class="text-warning">${escapeHtml(item.error || 'Unavailable')}</span>`}</td></tr>`).join('');
  showContent(`${result.algorithm.toUpperCase()} checksums`, `<table class="wc-info-table">${rows}</table>`);
}

async function actionPassword() {
  const values = await showForm('Change password', [
    {name:'current', label:'Current password', type:'password', required:true},
    {name:'new_password', label:'New password (minimum 10 characters)', type:'password', required:true, minlength:10},
    {name:'confirm', label:'Confirm new password', type:'password', required:true, minlength:10}
  ], {submitLabel:'Change password'});
  if (!values) return;
  const result = await api('change_password', values);
  WC.csrf = result.csrf;
  toast('Password changed.');
}

async function actionUpdate() {
  const check = await api('update_check');
  const current = escapeHtml(check.currentVersion);
  const latest = escapeHtml(check.latestVersion);

  if (!check.updateAvailable) {
    showContent('WebCommander update', '<div class="text-center py-3"><i class="fa-solid fa-circle-check fa-2x mb-3" style="color:var(--wc-success)"></i><div><strong>v' + current + ' is up to date.</strong></div><div class="wc-result-note mt-2">No newer version is available on GitHub.</div></div>', {wide:false});
    return;
  }

  if (!check.canInstall) {
    showContent('Update available', '<div class="alert alert-warning mb-0"><strong>v' + latest + ' is available.</strong><br>PHP cannot write to the directory containing WebCommander. Make that directory writable, then click the version again.</div>', {wide:false});
    return;
  }

  const confirmed = await showForm('Update available', [
    {type:'html', html:'<div class="mb-3"><strong>WebCommander v' + latest + '</strong> is available. You are using v' + current + '.</div><div class="wc-result-note">The update will be downloaded from the official GitHub repository, syntax-checked, installed, verified, and then the page will reload.</div>'}
  ], {submitLabel:'Update to v' + check.latestVersion});
  if (!confirmed) return;

  const result = await api('update_install', {expected_version:check.latestVersion});
  showContent('Update installed', '<div class="text-center py-3"><i class="fa-solid fa-circle-check fa-2x mb-3" style="color:var(--wc-success)"></i><div><strong>Updated to v' + escapeHtml(result.version) + '.</strong></div><div class="wc-result-note mt-2">Reloading WebCommander…</div></div>', {wide:false});
  setTimeout(() => location.reload(), 1200);
}

async function actionLogout() {
  try { await api('logout'); } finally { location.reload(); }
}

const actionMap = {
  view:actionView, edit:actionEdit, copy:() => actionTransfer('copy'), move:() => actionTransfer('move'), mkdir:actionMkdir,
  delete:actionDelete, 'new-file':actionNewFile, rename:actionRename, upload:() => actionUpload(false), 'upload-folder':() => actionUpload(true),
  download:actionDownload, archive:actionArchive, extract:actionExtract, search:actionSearch, tree:actionTree, 'tree-selected':actionTreeSelected, compare:actionCompare, properties:actionProperties,
  permissions:actionPermissions, touch:actionTouch, link:actionLink, checksum:actionChecksum, refresh:reloadBoth, update:actionUpdate, password:actionPassword, logout:actionLogout
};

async function runAction(name) {
  hideContext();
  const action = actionMap[name];
  if (!action) return;
  try { await action(); }
  catch (error) { toast(error.message || String(error), true, 6000); }
}

$$('[data-action]').forEach(button => button.addEventListener('click', event => {
  event.preventDefault();
  runAction(button.dataset.action);
}));

function showContext(x, y, canShowTree = false) {
  const menu = $('#contextMenu');
  $('#contextTree').style.display = canShowTree ? '' : 'none';
  menu.classList.add('show');
  const rect = menu.getBoundingClientRect();
  menu.style.left = Math.max(5, Math.min(x, innerWidth - rect.width - 5)) + 'px';
  menu.style.top = Math.max(5, Math.min(y, innerHeight - rect.height - 5)) + 'px';
}

function hideContext() { $('#contextMenu').classList.remove('show'); }
document.addEventListener('click', event => { if (!event.target.closest('#contextMenu')) hideContext(); });
window.addEventListener('blur', hideContext);

$('#fileUpload').addEventListener('change', event => uploadFiles(event.target.files));
$('#folderUpload').addEventListener('change', event => uploadFiles(event.target.files));

document.addEventListener('keydown', event => {
  if (dialog.open || event.target.matches('input,textarea,select')) return;
  const keys = {F3:'view',F4:'edit',F5:'copy',F6:'move',F7:'mkdir',F8:'delete',F9:'password',F10:'logout'};
  if (keys[event.key]) { event.preventDefault(); runAction(keys[event.key]); return; }
  const pane = activePane();
  if (event.key === 'Backspace') { event.preventDefault(); pane.load(pane.parent); }
  if (event.key === 'Insert' && pane.focused) {
    event.preventDefault();
    pane.selected.has(pane.focused) ? pane.selected.delete(pane.focused) : pane.selected.add(pane.focused);
    pane.renderRowsOnly();
  }
  if (event.key === 'Tab') { event.preventDefault(); otherPane().activate(); }
}
);

function updateClock() {
  $('#clock').textContent = new Date().toLocaleString();
}

WC.panes.left = new Pane('left');
WC.panes.right = new Pane('right');

if (WC.diskFree !== null && WC.diskTotal !== null) {
  const used = WC.diskTotal - WC.diskFree;
  $('#diskInfo').textContent = `${formatBytes(WC.diskFree)} free of ${formatBytes(WC.diskTotal)} (${Math.round(used / WC.diskTotal * 100)}% used)`;
}

updateClock();
setInterval(updateClock, 1000);
Promise.all([WC.panes.left.load(''), WC.panes.right.load('')]).then(() => WC.panes.left.activate());
</script>
</body>
</html>
