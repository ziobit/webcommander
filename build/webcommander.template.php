<?php
declare(strict_types=1);

/*
 * WebCommander - single-file, two-pane file manager for PHP 7.4+
 *
 * Change MC_ROOT below if you want to confine the manager to another folder.
 * The web-server user must have the appropriate filesystem permissions.
 */

define('MC_ROOT', __DIR__);
define('MC_VERSION', '1.12'); // Increment this for every published update.
define('MC_CODEMIRROR_VERSION', '5.65.21'); // Embedded core, CSS, modes and addons; see the asset manifest below.
define('MC_BOOTSTRAP_VERSION', '5.3.3');
define('MC_FONT_AWESOME_VERSION', '6.5.2');
define('MC_UPDATE_URL', 'https://raw.githubusercontent.com/ziobit/webcommander/main/webcommander.php');
define('MC_UPDATE_MAX_BYTES', 16 * 1024 * 1024); // Bound both download transports before buffering/parsing.
define('MC_MAX_TREE_ITEMS', 200000);
define('MC_MAX_TREE_FOLDERS', 10000);
define('MC_MAX_TREE_DEPTH', 64);
define('MC_SESSION_TIMEOUT', 1800);
define('MC_MAX_EDIT_BYTES', 5 * 1024 * 1024);
define('MC_VIEW_PAGE_BYTES', 100 * 1024);
define('MC_MAX_SEARCH_RESULTS', 500);
define('MC_MAX_ARCHIVE_ENTRIES', 10000);
define('MC_MAX_ARCHIVE_BYTES', 1024 * 1024 * 1024);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// Public immutable assets contain no session, configuration or user data.
if (isset($_GET['wc_asset'])) {
  mc_asset_serve();
}

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

$rootReal = realpath(MC_ROOT);
if ($rootReal === false || !is_dir($rootReal)) {
  http_response_code(500);
  exit('MC_ROOT is not a valid directory.');
}
define('MC_ROOT_REAL', rtrim($rootReal, DIRECTORY_SEPARATOR));
define('MC_CONFIG_FILE', __DIR__ . DIRECTORY_SEPARATOR . '.webcommander-' . substr(hash('sha256', __FILE__), 0, 12) . '.php');
define('MC_UPDATE_LOCK_FILE', MC_CONFIG_FILE . '.update-lock');

/* WC_ASSET_HELPERS */

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

function mc_json(array $payload, int $status = 200, int $flags = 0): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $flags);
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

function mc_update_response_header(string $header, array &$response): void {
  $header = trim($header);
  if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches)) {
    $response = ['status' => (int)$matches[1], 'length' => null, 'encoding' => '', 'transfer' => ''];
  } elseif (stripos($header, 'Content-Length:') === 0) {
    $length = trim(substr($header, 15));
    if (!preg_match('/^[0-9]+$/D', $length)) {
      throw new RuntimeException('The update server returned an invalid download length.');
    }
    if ((float)$length > MC_UPDATE_MAX_BYTES) {
      throw new RuntimeException('The update file exceeds the allowed size (16 MiB).');
    }
    if ($response['length'] !== null && $response['length'] !== (int)$length) {
      throw new RuntimeException('The update server returned conflicting download lengths.');
    }
    $response['length'] = (int)$length;
  } elseif (stripos($header, 'Content-Encoding:') === 0) {
    $response['encoding'] = strtolower(trim(substr($header, 17)));
  } elseif (stripos($header, 'Transfer-Encoding:') === 0) {
    $response['transfer'] = strtolower(trim(substr($header, 18)));
  }
}

function mc_update_response_check(array $response): void {
  if ($response['status'] !== 200) {
    throw new RuntimeException('The update server returned HTTP ' . $response['status'] . '.');
  }
  if (!in_array($response['encoding'], ['', 'identity'], true) ||
      !in_array($response['transfer'], ['', 'chunked'], true) ||
      ($response['transfer'] !== '' && $response['length'] !== null)) {
    throw new RuntimeException('The update server returned an unsupported or ambiguous download encoding.');
  }
}

/** Write a bounded chunk to a disk stream, including short-write handling. */
function mc_update_write($file, string $chunk, int &$bytes): void {
  $length = strlen($chunk);
  if ($length > MC_UPDATE_MAX_BYTES - $bytes) {
    throw new RuntimeException('The update file exceeds the allowed size (16 MiB).');
  }
  $offset = 0;
  while ($offset < $length) {
    $written = @fwrite($file, $offset === 0 ? $chunk : substr($chunk, $offset));
    if ($written === false || $written === 0) {
      throw new RuntimeException('Cannot write the temporary update file. Check available disk space.');
    }
    $offset += $written;
  }
  $bytes += $length;
}

/** Returns an automatically removed disk-backed stream; the caller must fclose it. */
function mc_update_download() {
  if (parse_url(MC_UPDATE_URL, PHP_URL_SCHEME) !== 'https') {
    throw new RuntimeException('The update source must use HTTPS.');
  }
  $url = MC_UPDATE_URL . '?cache=' . rawurlencode((string)time());
  $file = @tmpfile();
  if ($file === false) {
    throw new RuntimeException('Cannot create a temporary download file.');
  }
  $response = ['status' => 0, 'length' => null, 'encoding' => '', 'transfer' => ''];
  $bytes = 0;
  try {
    if (function_exists('curl_init')) {
      $curl = curl_init($url);
      if ($curl === false) {
        throw new RuntimeException('Cannot initialize the update connection.');
      }
      $callbackError = null;
      try {
        if (!curl_setopt_array($curl, [
          CURLOPT_CONNECTTIMEOUT => 8,
          CURLOPT_TIMEOUT => 120,
          CURLOPT_FOLLOWLOCATION => false,
          CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_USERAGENT => 'WebCommander/' . MC_VERSION,
          CURLOPT_HTTPHEADER => ['Accept: text/plain', 'Accept-Encoding: identity', 'Cache-Control: no-cache'],
          CURLOPT_HEADERFUNCTION => function ($connection, string $header) use (&$response, &$callbackError): int {
            try {
              mc_update_response_header($header, $response);
              return strlen($header);
            } catch (Throwable $e) {
              $callbackError = $e;
              return 0;
            }
          },
          CURLOPT_WRITEFUNCTION => function ($connection, string $chunk) use ($file, &$bytes, &$response, &$callbackError): int {
            try {
              mc_update_response_check($response);
              mc_update_write($file, $chunk, $bytes);
              return strlen($chunk);
            } catch (Throwable $e) {
              $callbackError = $e;
              return 0;
            }
          }
        ])) {
          throw new RuntimeException('Cannot configure a secure update connection.');
        }
        $completed = curl_exec($curl);
        if ($callbackError !== null) {
          throw $callbackError;
        }
        if ($completed === false) {
          throw new RuntimeException('Cannot download the complete update: ' . curl_error($curl));
        }
      } finally {
        curl_close($curl);
      }
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'GET',
          'timeout' => 20,
          'ignore_errors' => true,
          'follow_location' => 0,
          'max_redirects' => 0,
          'header' => "User-Agent: WebCommander/" . MC_VERSION . "\r\nAccept: text/plain\r\nAccept-Encoding: identity\r\nCache-Control: no-cache\r\n"
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
      ]);
      $connection = @fopen($url, 'rb', false, $context);
      if ($connection === false) {
        throw new RuntimeException('Cannot download the update. Enable cURL or allow_url_fopen and permit outbound HTTPS.');
      }
      try {
        $metadata = stream_get_meta_data($connection);
        foreach (($metadata['wrapper_data'] ?? []) as $header) {
          mc_update_response_header($header, $response);
        }
        mc_update_response_check($response);
        $deadline = microtime(true) + 120;
        while (!feof($connection)) {
          $remaining = $deadline - microtime(true);
          if ($remaining <= 0) {
            throw new RuntimeException('The update download timed out.');
          }
          stream_set_timeout($connection, max(1, (int)min(20, $remaining)));
          $chunk = @fread($connection, 64 * 1024);
          $metadata = stream_get_meta_data($connection);
          if ($chunk === false || !empty($metadata['timed_out']) || ($chunk === '' && !feof($connection))) {
            throw new RuntimeException('The update download was interrupted or timed out.');
          }
          mc_update_write($file, $chunk, $bytes);
        }
      } finally {
        fclose($connection);
      }
    }
    mc_update_response_check($response);
    if ($bytes === 0) {
      throw new RuntimeException('The update server returned an empty file.');
    }
    if ($response['length'] !== null && $bytes !== $response['length']) {
      throw new RuntimeException('The update download is truncated or its length is incorrect.');
    }
    if (!fflush($file) || !rewind($file)) {
      throw new RuntimeException('Cannot finish the temporary download file.');
    }
    return $file;
  } catch (Throwable $e) {
    fclose($file);
    throw $e;
  }
}

function mc_update_memory_check(int $bytes): void {
  $limit = trim((string)ini_get('memory_limit'));
  if ($limit === '-1') {
    return;
  }
  $units = ['k' => 1024, 'm' => 1024 * 1024, 'g' => 1024 * 1024 * 1024];
  $available = (float)$limit * ($units[strtolower(substr($limit, -1))] ?? 1) - memory_get_usage(true);
  // The source and PHP tokenizer still need memory. Leave headroom for tokens,
  // parsing and a JSON error; never raise the server's configured memory limit.
  if ($available < $bytes * 8 + 32 * 1024 * 1024) {
    throw new RuntimeException('Insufficient PHP memory for update syntax validation. Increase memory_limit or update manually.');
  }
}

function mc_update_source_info($file): array {
  $stat = fstat($file);
  $bytes = $stat === false ? 0 : (int)$stat['size'];
  if ($bytes === 0 || $bytes > MC_UPDATE_MAX_BYTES) {
    throw new RuntimeException('The downloaded update has an invalid size.');
  }
  mc_update_memory_check($bytes);
  rewind($file);
  $source = stream_get_contents($file, MC_UPDATE_MAX_BYTES + 1);
  if ($source === false || strlen($source) !== $bytes) {
    throw new RuntimeException('Cannot read the complete update for validation.');
  }
  if (strncmp($source, '<?php', 5) !== 0 || strpos($source, 'WebCommander - single-file') === false) {
    throw new RuntimeException('The downloaded file is not a valid WebCommander source file.');
  }
  $pattern = '/define\(\s*[\'"]MC_VERSION[\'"]\s*,\s*[\'"]([0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z.-]+)?)[\'"]\s*\)\s*;/';
  if (!preg_match($pattern, $source, $matches)) {
    throw new RuntimeException('The downloaded source does not contain a valid version.');
  }
  // Legacy releases predate the marker and cannot be installed over v1.11.
  // New releases must retain this last line, including when served chunked or
  // without Content-Length: PHP syntax alone cannot detect a missing HTML tail.
  $end = "<!-- WebCommander update end -->\n";
  if (version_compare($matches[1], '1.10', '>') && substr($source, -strlen($end)) !== $end) {
    throw new RuntimeException('The update is incomplete: its end marker is missing.');
  }
  if (!function_exists('token_get_all')) {
    throw new RuntimeException('The PHP tokenizer extension is required to validate updates.');
  }
  try {
    $tokens = token_get_all($source, TOKEN_PARSE);
  } catch (ParseError $e) {
    throw new RuntimeException('The downloaded update failed PHP syntax validation: ' . $e->getMessage());
  }
  // Confirm an actual literal define(), rather than accepting a version hidden
  // in a comment or string. Never execute the downloaded source to inspect it.
  $versions = [];
  foreach ($tokens as $index => $token) {
    if (!is_array($token) || $token[0] !== T_STRING || strcasecmp($token[1], 'define') !== 0) {
      continue;
    }
    $arguments = [];
    for ($next = $index + 1; $next < count($tokens) && count($arguments) < 6; $next++) {
      $item = $tokens[$next];
      if (is_array($item) && in_array($item[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
      }
      $arguments[] = $item;
    }
    if (count($arguments) === 6 && $arguments[0] === '(' && $arguments[2] === ',' &&
        $arguments[4] === ')' && $arguments[5] === ';' &&
        is_array($arguments[1]) && $arguments[1][0] === T_CONSTANT_ENCAPSED_STRING &&
        in_array($arguments[1][1], ["'MC_VERSION'", '"MC_VERSION"'], true) &&
        is_array($arguments[3]) && $arguments[3][0] === T_CONSTANT_ENCAPSED_STRING) {
      $versions[] = substr($arguments[3][1], 1, -1);
    }
  }
  unset($tokens);
  if (count($versions) !== 1 || !hash_equals($matches[1], $versions[0])) {
    throw new RuntimeException('The downloaded source does not contain a unique literal version definition.');
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

/** Create exclusive, private staging/rollback files on the target filesystem. */
function mc_update_temporary(string $directory): array {
  $path = $directory . DIRECTORY_SEPARATOR . '.webcommander-update-' . bin2hex(random_bytes(16)) . '.tmp';
  $file = @fopen($path, 'x+b');
  if ($file === false) {
    throw new RuntimeException('Cannot create a temporary update file in the application directory.');
  }
  if (!@chmod($path, 0600)) {
    fclose($file);
    @unlink($path);
    throw new RuntimeException('Cannot secure the temporary update file.');
  }
  return [$path, $file];
}

function mc_update_install(string $expectedVersion, string $expectedHash): array {
  if (!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z.-]+)?$/D', $expectedVersion)) {
    throw new RuntimeException('Invalid expected update version.');
  }
  if (!mc_update_can_install()) {
    throw new RuntimeException('The directory containing WebCommander is not writable by PHP.');
  }
  if (!preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
    throw new RuntimeException('Invalid expected update checksum. Check for updates again.');
  }
  clearstatcache(true, MC_UPDATE_LOCK_FILE);
  $lockStat = @lstat(MC_UPDATE_LOCK_FILE);
  if ($lockStat !== false && ($lockStat['mode'] & 0170000) !== 0100000) {
    throw new RuntimeException('Cannot open a safe update lock file.');
  }
  $lock = @fopen(MC_UPDATE_LOCK_FILE, 'x+b');
  if ($lock === false) {
    $lock = @fopen(MC_UPDATE_LOCK_FILE, 'r+b');
  }
  clearstatcache(true, MC_UPDATE_LOCK_FILE);
  $lockStat = @lstat(MC_UPDATE_LOCK_FILE);
  $openedStat = $lock === false ? false : fstat($lock);
  if ($lock === false || $lockStat === false || $openedStat === false ||
      ($lockStat['mode'] & 0170000) !== 0100000 ||
      $lockStat['dev'] !== $openedStat['dev'] || $lockStat['ino'] !== $openedStat['ino']) {
    if (is_resource($lock)) fclose($lock);
    throw new RuntimeException('Cannot open a safe update lock file.');
  }
  if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    throw new RuntimeException('Another update is already in progress. Try again later.');
  }
  $target = __FILE__;
  $directory = dirname($target);
  $temporary = null;
  $backup = null;
  $keepBackup = false;
  $download = null;
  try {
    $currentFile = @fopen($target, 'rb');
    $header = $currentFile === false ? '' : (string)fread($currentFile, 8192);
    if (is_resource($currentFile)) fclose($currentFile);
    if (!preg_match('/^define\(\s*[\'"]MC_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)\s*;/m', $header, $currentVersion) ||
        !hash_equals(MC_VERSION, $currentVersion[1])) {
      throw new RuntimeException('WebCommander changed since this request started. Reload before updating.');
    }
    $previousHash = @hash_file('sha256', $target);
    if ($previousHash === false) {
      throw new RuntimeException('Cannot read the current source before updating.');
    }
    $download = mc_update_download();
    $info = mc_update_source_info($download);
    if (!hash_equals($expectedVersion, (string)$info['version']) || !hash_equals($expectedHash, (string)$info['sha256'])) {
      throw new RuntimeException('The available update changed. Check for updates again.');
    }
    if (!version_compare((string)$info['version'], MC_VERSION, '>')) {
      throw new RuntimeException('No newer version is available.');
    }
    [$temporary, $stage] = mc_update_temporary($directory);
    try {
      rewind($download);
      $written = stream_copy_to_stream($download, $stage);
      if ($written !== $info['bytes'] || !fflush($stage)) {
        throw new RuntimeException('Cannot write the complete staged update.');
      }
    } finally {
      fclose($stage);
    }
    if (!hash_equals($info['sha256'], (string)@hash_file('sha256', $temporary))) {
      throw new RuntimeException('The staged update failed checksum verification.');
    }
    $permissions = @fileperms($target);
    if ($permissions === false) {
      throw new RuntimeException('Cannot read the current source before updating.');
    }
    [$backup, $previousFile] = mc_update_temporary($directory);
    $currentFile = @fopen($target, 'rb');
    try {
      if ($currentFile === false || stream_copy_to_stream($currentFile, $previousFile) === false || !fflush($previousFile)) {
        throw new RuntimeException('Cannot create the update rollback file.');
      }
    } finally {
      if (is_resource($currentFile)) fclose($currentFile);
      fclose($previousFile);
    }
    if (!hash_equals($previousHash, (string)@hash_file('sha256', $backup)) ||
        !hash_equals($previousHash, (string)@hash_file('sha256', $target))) {
      throw new RuntimeException('The current source changed while preparing the update. Retry after reloading.');
    }
    if (!@chmod($temporary, $permissions & 0777)) {
      throw new RuntimeException('Cannot preserve the current source permissions.');
    }
    // Never truncate/overwrite the live PHP file, including when rename fails.
    if (!@rename($temporary, $target)) {
      throw new RuntimeException('Cannot atomically replace the current source. The current file was left unchanged.');
    }
    $temporary = null;
    // Retain the verified old bytes if execution stops after replacement.
    $keepBackup = true;
    clearstatcache(true, $target);
    $installedHash = @hash_file('sha256', $target);
    if ($installedHash === false || !hash_equals((string)$info['sha256'], $installedHash)) {
      $restored = false;
      try {
        [$temporary, $restoreFile] = mc_update_temporary($directory);
        $previousFile = @fopen($backup, 'rb');
        try {
          $copied = $previousFile !== false && stream_copy_to_stream($previousFile, $restoreFile) !== false && fflush($restoreFile);
        } finally {
          if (is_resource($previousFile)) fclose($previousFile);
          fclose($restoreFile);
        }
        $restored = $copied && hash_equals($previousHash, (string)@hash_file('sha256', $temporary)) &&
          @chmod($temporary, $permissions & 0777) && @rename($temporary, $target);
      } catch (Throwable $e) {
        // The verified rollback file is kept even if preparing recovery fails.
      }
      clearstatcache(true, $target);
      if ($restored && hash_equals($previousHash, (string)@hash_file('sha256', $target))) {
        $temporary = null;
        $keepBackup = false;
        if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
        throw new RuntimeException('Update verification failed; the previous source was restored and verified.');
      }
      throw new RuntimeException('Update verification failed and automatic recovery failed. Restore the verified rollback file manually: ' . $backup);
    }
    $keepBackup = false;
    if (function_exists('opcache_invalidate')) {
      @opcache_invalidate($target, true);
    }
    return [
      'previousVersion' => MC_VERSION,
      'version' => $info['version'],
      'sha256' => $info['sha256']
    ];
  } finally {
    if (is_resource($download)) fclose($download);
    if ($temporary !== null) {
      @unlink($temporary);
    }
    if ($backup !== null && !$keepBackup) {
      @unlink($backup);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
  }
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
  $parent = realpath(dirname($raw));
  if ($parent === false || !mc_is_within_root($parent)) {
    throw new RuntimeException('The parent directory is outside the allowed root.');
  }
  if (!is_link($raw) && !file_exists($raw)) {
    throw new RuntimeException('Path not found: ' . $rel);
  }
  if ($follow) {
    $real = realpath($raw);
    if ($real === false || !mc_is_within_root($real)) {
      throw new RuntimeException('The path resolves outside the allowed root.');
    }
    return $real;
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
  $paths[] = MC_UPDATE_LOCK_FILE;
  return $paths;
}

function mc_is_protected(string $path, bool $includeChildren = false): bool {
  $path = rtrim($path, DIRECTORY_SEPARATOR);
  foreach (mc_protected_paths() as $protected) {
    $protected = rtrim($protected, DIRECTORY_SEPARATOR);
    if ($path === $protected) {
      return true;
    }
    if ($includeChildren && !is_link($path) && is_dir($path) && strpos($protected, $path . DIRECTORY_SEPARATOR) === 0) {
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

function mc_system_accounts(string $file): array {
  $accounts = [];
  $lines = is_readable($file) ? @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
  foreach ($lines === false ? [] : $lines as $line) {
    $parts = explode(':', $line);
    if (count($parts) >= 3 && $parts[0] !== '' && preg_match('/^\d+$/', $parts[2])) {
      $accounts[] = ['name' => $parts[0], 'id' => (int)$parts[2]];
    }
  }
  usort($accounts, function (array $a, array $b): int { return strnatcasecmp($a['name'], $b['name']); });
  return $accounts;
}

function mc_ownership_value(string $value) {
  if (!preg_match('/^\d+$/', $value)) {
    return $value;
  }
  $number = ltrim($value, '0');
  $id = filter_var($number === '' ? '0' : $number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
  if ($id === false) {
    throw new RuntimeException('The numeric owner/group ID is invalid or too large.');
  }
  return $id;
}

function mc_mime(string $path): string {
  if (is_link($path)) {
    return 'inode/symlink';
  }
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
  $accessPath = $path;
  $navigable = $type === 'dir';
  if ($type === 'link') {
    $target = readlink($path);
    $real = realpath($path);
    $accessPath = $real !== false && mc_is_within_root($real) ? $real : null;
    $navigable = $accessPath !== null && is_dir($accessPath);
  }
  return [
    'name' => $name,
    'path' => mc_rel_from_path($path),
    'type' => $type,
    'navigable' => $navigable,
    'size' => ($type === 'file' || $type === 'link') ? (int)$lstat['size'] : null,
    'mtime' => (int)$lstat['mtime'],
    'ctime' => (int)$lstat['ctime'],
    'mode' => substr(sprintf('%o', (int)$lstat['mode']), -4),
    'permissions' => mc_permissions((int)$lstat['mode']),
    'owner' => mc_owner_name((int)$lstat['uid']),
    'group' => mc_group_name((int)$lstat['gid']),
    'uid' => (int)$lstat['uid'],
    'gid' => (int)$lstat['gid'],
    'readable' => $accessPath !== null && is_readable($accessPath),
    'writable' => $accessPath !== null && is_writable($accessPath),
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
  if (!is_link($path) && is_dir($path)) {
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
      if (!is_link($child) && is_dir($child)) {
        yield from mc_walk($child, false);
      }
    }
  }
}

function mc_directory_size(string $path, int &$items): int {
  $total = 0;
  foreach (mc_walk($path) as $node) {
    $items++;
    if (!is_link($node) && is_file($node)) {
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
    if (!is_link($child) && is_dir($child)) {
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
    if (!is_link($child) && is_file($child)) {
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

function mc_read_text_page(string $path, int $page): array {
  if (!is_file($path) || !is_readable($path)) {
    throw new RuntimeException('The file is not readable.');
  }
  $handle = fopen($path, 'rb');
  if ($handle === false) {
    throw new RuntimeException('Cannot open the file.');
  }
  try {
    $stat = fstat($handle);
    if ($stat === false) {
      throw new RuntimeException('Cannot determine file size.');
    }
    $size = (int)$stat['size'];
    $pages = max(1, (int)ceil($size / MC_VIEW_PAGE_BYTES));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * MC_VIEW_PAGE_BYTES;

    $sample = fread($handle, 8192);
    if ($sample === false) {
      throw new RuntimeException('Cannot read the file.');
    }
    if (strpos($sample, "\0") !== false) {
      throw new RuntimeException('This appears to be a binary file. Use the preview or download action.');
    }

    // Read only one page plus enough context to keep UTF-8 characters intact.
    $readOffset = max(0, $offset - 3);
    $readLength = min(MC_VIEW_PAGE_BYTES + 6, $size - $readOffset);
    if (fseek($handle, $readOffset) !== 0) {
      throw new RuntimeException('Cannot seek to the requested page.');
    }
    $raw = $readLength > 0 ? stream_get_contents($handle, $readLength) : '';
    if ($raw === false || strlen($raw) !== $readLength) {
      throw new RuntimeException('Cannot read the requested page. The file may have changed; reopen it.');
    }
    // Adjacent pages use the same boundary, without splitting a character.
    $alignBoundary = function (int $position) use ($raw): int {
      $minimum = max(0, $position - 3);
      while ($position > $minimum && $position < strlen($raw) && (ord($raw[$position]) & 0xC0) === 0x80) {
        $position--;
      }
      return $position;
    };
    $start = $alignBoundary($offset - $readOffset);
    $end = $alignBoundary(min($size, $offset + MC_VIEW_PAGE_BYTES) - $readOffset);
    $content = substr($raw, $start, $end - $start);
    if (strpos(substr($content, 0, 8192), "\0") !== false) {
      throw new RuntimeException('This appears to be a binary file. Use the preview or download action.');
    }
    return [
      'content' => $content,
      'size' => $size,
      'page' => $page,
      'pages' => $pages,
      'pageBytes' => MC_VIEW_PAGE_BYTES,
      'offset' => $readOffset + $start,
      'endOffset' => $readOffset + $end
    ];
  } finally {
    fclose($handle);
  }
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
      if (!is_link($node) && is_file($node)) {
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
    if (!is_link($node) && is_dir($node)) {
      $map[$key] = ['type' => 'dir'];
    } elseif (!is_link($node) && is_file($node)) {
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
  if ($recursive && !is_link($path) && is_dir($path)) {
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

    if ($action === 'read_text_page') {
      $rel = mc_normalize_rel((string)($data['path'] ?? ''));
      $page = filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if ($page === false) {
        throw new RuntimeException('Enter a valid page number.');
      }
      $result = mc_read_text_page(mc_existing_path($rel), $page);
      mc_json(array_merge(['success' => true, 'path' => $rel], $result), 200, JSON_INVALID_UTF8_SUBSTITUTE);
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

    if ($action === 'permission_info') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      if (!$paths) {
        throw new RuntimeException('Select at least one item.');
      }
      $details = [];
      foreach ($paths as $rel) {
        $path = mc_existing_path((string)$rel, false);
        $details[] = mc_item_info($path, basename($path));
      }
      mc_ok(['details' => $details, 'users' => mc_system_accounts('/etc/passwd'), 'groups' => mc_system_accounts('/etc/group')]);
    }

    if ($action === 'chmod_chown') {
      $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
      $modeText = trim((string)($data['mode'] ?? ''));
      if ($modeText !== '' && !preg_match('/^[0-7]{3,4}$/', $modeText)) {
        throw new RuntimeException('Permissions must contain three or four octal digits (0–7).');
      }
      $owner = mc_ownership_value(trim((string)($data['owner'] ?? '')));
      $group = mc_ownership_value(trim((string)($data['group'] ?? '')));
      $recursive = !empty($data['recursive']);
      if (!$paths) {
        throw new RuntimeException('Select at least one item.');
      }
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
          if ($owner !== '') {
            $changeOwner = is_link($node) ? 'lchown' : 'chown';
            if (!function_exists($changeOwner)) {
              throw new RuntimeException('Changing ownership is not supported for this item.');
            }
            mc_fs('Cannot change owner for ' . basename($node), function () use ($node, $owner, $changeOwner) { return $changeOwner($node, $owner); });
          }
          if ($group !== '') {
            $changeGroup = is_link($node) ? 'lchgrp' : 'chgrp';
            if (!function_exists($changeGroup)) {
              throw new RuntimeException('Changing groups is not supported for this item.');
            }
            mc_fs('Cannot change group for ' . basename($node), function () use ($node, $group, $changeGroup) { return $changeGroup($node, $group); });
          }
          // Apply the requested mode last: changing ownership can clear special bits.
          if ($mode !== null && !is_link($node)) {
            mc_fs('Cannot change permissions for ' . basename($node), function () use ($node, $mode) { return chmod($node, $mode); });
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
        // touch() follows links; use only a canonical target inside MC_ROOT.
        $path = mc_existing_path((string)$rel);
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
        $size = $info['type'] === 'dir' ? mc_directory_size($path, $items) : (int)($info['size'] ?? 0);
        if ($items === 0) $items = 1;
        $info['calculatedSize'] = $size;
        $info['itemCount'] = $items;
        $info['mime'] = $info['type'] === 'file' ? mc_mime($path) : ($info['type'] === 'link' ? 'inode/symlink' : $info['type']);
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
      $download = mc_update_download();
      try {
        $info = mc_update_source_info($download);
      } finally {
        fclose($download);
      }
      mc_ok([
        'currentVersion' => MC_VERSION,
        'latestVersion' => $info['version'],
        'updateAvailable' => version_compare((string)$info['version'], MC_VERSION, '>'),
        'canInstall' => mc_update_can_install(),
        'sha256' => $info['sha256']
      ]);
    }

    if ($action === 'update_install') {
      mc_ok(mc_update_install((string)($data['expected_version'] ?? ''), (string)($data['expected_sha256'] ?? '')));
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
  <?php mc_ui_styles(); ?>
  <?php mc_asset_styles('login-css'); ?>
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
  <?php mc_ui_styles(); mc_code_styles(); ?>
  <?php mc_asset_script('theme-js'); ?>
  <?php mc_asset_styles('app-css'); ?>
</head>
<body>
<div class="wc-app">
  <header class="wc-topbar">
    <div class="wc-brand"><span class="wc-brand-mark"><i class="fa-solid fa-table-columns"></i></span><span>WebCommander</span></div>
    <div class="wc-root" title="<?= mc_h(MC_ROOT_REAL) ?>"><i class="fa-solid fa-shield-halved me-1"></i><?= mc_h(MC_ROOT_REAL) ?></div>
    <div class="wc-disk" id="diskInfo"></div>
    <button class="wc-btn" id="themeButton" data-action="theme" title="Font, font size and color scheme" aria-haspopup="dialog" aria-controls="mainDialog"><i class="fa-solid fa-palette" aria-hidden="true"></i><span>Theme</span></button>
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
    <button class="wc-btn" data-action="upload-folder" title="Upload folder"><i class="fa-solid fa-folder-open"></i><span>Folder upload</span></button>
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

  <main class="wc-panes" id="panes">
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
        <button class="wc-btn pane-columns" title="Column widths — saved for this pane, font and size" aria-label="<?= ucfirst($paneId) ?> pane column widths"><i class="fa-solid fa-table-columns"></i></button>
      </div>
      <div class="wc-table-wrap" tabindex="0" role="region" aria-label="<?= ucfirst($paneId) ?> file pane">
        <table class="wc-table">
          <thead><tr><th data-column="select"><input class="select-all" type="checkbox" aria-label="Select all"></th><th class="name" data-column="name" data-sort="name">Name</th><th class="size" data-column="size" data-sort="size">Size</th><th class="date" data-column="date" data-sort="mtime">Modified</th><th class="perms" data-column="mode" data-sort="mode">Mode</th></tr></thead>
          <tbody></tbody>
        </table>
        <div class="wc-empty" hidden>No items</div>
      </div>
      <div class="wc-pane-status"><span class="pane-count">Loading…</span><span class="pane-selection"></span></div>
    </section>
    <?php if ($paneId === 'left'): ?>
    <div class="wc-pane-separator" id="paneSeparator" role="separator" tabindex="0" aria-label="Resize file panes" aria-controls="pane-left pane-right" aria-orientation="vertical" aria-valuemin="15" aria-valuemax="85" aria-valuenow="50" title="Drag to resize panes; double-click to restore equal panes"></div>
    <?php endif; ?>
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
  <button data-action="permissions"><i class="fa-solid fa-user-shield fa-fw me-2"></i>Permissions / Owner</button>
  <button data-action="tree-selected" id="contextTree"><i class="fa-solid fa-folder-tree fa-fw me-2"></i>Tree</button>
  <button data-action="delete"><i class="fa-solid fa-trash fa-fw me-2"></i>Delete</button>
</div>

<input id="fileUpload" type="file" multiple hidden>
<input id="folderUpload" type="file" webkitdirectory multiple hidden>
<div class="wc-toast-area" id="toastArea" aria-live="polite"></div>

<?php mc_code_scripts(); ?>
<script>
'use strict';

const WC = {
  csrf: <?= json_encode($csrf) ?>,
  root: <?= json_encode(MC_ROOT_REAL, JSON_UNESCAPED_SLASHES) ?>,
  diskFree: <?= json_encode($diskFree === false ? null : (float)$diskFree) ?>,
  diskTotal: <?= json_encode($diskTotal === false ? null : (float)$diskTotal) ?>,
  codeViewBytes: <?= json_encode(MC_MAX_EDIT_BYTES) ?>,
  active: 'left',
  panes: {},
  busy: 0
};

</script>
<?php mc_asset_script('app-js', true); ?>
</body>
</html>
<?php __halt_compiler();
