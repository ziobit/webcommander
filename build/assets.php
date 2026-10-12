<?php
/*
 * Asset routing is embedded into the deployed PHP file by pack-assets.js.
 * Read only named ranges from the current file's __halt_compiler() payload.
 */
function mc_asset_manifest(): array {
  static $assets = /* WC_ASSET_MANIFEST */;
  return $assets;
}

function mc_asset_fail(int $status, string $message): void {
  http_response_code($status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  echo $message;
  exit;
}

function mc_asset_encodings(): array {
  $header = (string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
  $values = [];
  foreach (explode(',', $header) as $part) {
    if (preg_match('/^\s*(gzip|identity|\*)\s*(?:;\s*q=(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?))?\s*$/iD', $part, $match)) {
      $values[strtolower($match[1])] = isset($match[2]) ? (float)$match[2] : 1.0;
    }
  }
  return [
    'gzip' => $values['gzip'] ?? ($values['*'] ?? 0),
    'identity' => $values['identity'] ?? (($values['*'] ?? 1) === 0.0 ? 0 : 1)
  ];
}

function mc_asset_serve(): void {
  if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    mc_asset_fail(405, 'Assets support GET and HEAD only.');
  }
  $assets = mc_asset_manifest();
  $name = $_GET['wc_asset'];
  $version = $_GET['v'] ?? null;
  if (!is_string($name) || !is_string($version) || !isset($assets[$name]) ||
      !hash_equals($assets[$name]['sha256'], $version)) {
    mc_asset_fail(404, 'Unknown asset or asset version.');
  }
  $asset = $assets[$name];
  $encodings = mc_asset_encodings();
  $gzip = $asset['gzip'] && $encodings['gzip'] > 0;
  if (!$gzip && $encodings['identity'] <= 0) {
    mc_asset_fail(406, 'No acceptable asset encoding.');
  }
  // Avoid double compression and session cache headers/cookies. This endpoint
  // runs before session_start() and never loads the password configuration.
  @ini_set('zlib.output_compression', '0');
  while (ob_get_level() > 0) {
    if (!@ob_end_clean()) break;
  }
  $file = @fopen(__FILE__, 'rb');
  if ($file === false) {
    mc_asset_fail(500, 'Cannot read the embedded asset pack.');
  }
  try {
    $stat = fstat($file);
    $offset = __COMPILER_HALT_OFFSET__ + $asset['offset'];
    if ($stat === false || $offset < __COMPILER_HALT_OFFSET__ ||
        $asset['length'] > $stat['size'] - $offset || fseek($file, $offset) !== 0) {
      mc_asset_fail(500, 'The embedded asset pack is incomplete.');
    }
    $body = stream_get_contents($file, $asset['length']);
  } finally {
    fclose($file);
  }
  if ($body === false || strlen($body) !== $asset['length'] ||
      !hash_equals($asset['storedSha256'], hash('sha256', $body))) {
    mc_asset_fail(500, 'The embedded asset pack failed verification.');
  }
  if ($asset['gzip'] && !$gzip) {
    if (!function_exists('gzdecode')) {
      mc_asset_fail(500, 'PHP zlib is required for uncompressed asset responses.');
    }
    $body = @gzdecode($body, $asset['bytes']);
    if ($body === false || strlen($body) !== $asset['bytes'] ||
        !hash_equals($asset['sha256'], hash('sha256', $body))) {
      mc_asset_fail(500, 'Cannot decode the embedded asset.');
    }
  }
  $etag = '"' . ($gzip ? $asset['storedSha256'] : $asset['sha256']) . '"';
  header('Content-Type: ' . $asset['type']);
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: public, max-age=31536000, immutable, no-transform');
  header('Vary: Accept-Encoding');
  header('ETag: ' . $etag);
  if ($gzip) header('Content-Encoding: gzip');
  $conditional = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
  foreach (explode(',', $conditional) as $tag) {
    $tag = trim($tag);
    if (strncmp($tag, 'W/', 2) === 0) $tag = substr($tag, 2);
    if ($tag === '*' || $tag === $etag) {
      http_response_code(304);
      exit;
    }
  }
  header('Content-Length: ' . strlen($body));
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
  exit;
}

function mc_asset_url(string $name): string {
  $assets = mc_asset_manifest();
  return '?wc_asset=' . rawurlencode($name) . '&v=' . $assets[$name]['sha256'];
}

function mc_asset_styles(string $name, string $id = ''): void {
  echo '<link rel="stylesheet"' . ($id === '' ? '' : ' id="' . mc_h($id) . '"') .
    ' href="' . mc_h(mc_asset_url($name)) . '">';
}

function mc_asset_script(string $name, bool $defer = false, string $id = ''): void {
  echo '<script' . ($id === '' ? '' : ' id="' . mc_h($id) . '"') .
    ' src="' . mc_h(mc_asset_url($name)) . '"' . ($defer ? ' defer' : '') . '></script>';
}

function mc_ui_styles(): void {
  mc_asset_styles('ui-css', 'wc-ui-assets');
}

function mc_code_styles(): void {
  mc_asset_styles('code-css', 'wc-syntax-styles');
}

function mc_code_scripts(): void {
  mc_asset_script('code-js', true, 'wc-syntax-scripts');
}
