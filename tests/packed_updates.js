'use strict';
// Validate packed candidates with either the v1.11 updater or a packed updater.
// node tests/packed_updates.js [updater.php] [packed-candidate.php]
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const {spawnSync} = require('node:child_process');
const root = path.join(__dirname, '..');
const updater = fs.readFileSync(process.argv[2] || path.join(root, 'webcommander.php'), 'latin1');
const candidate = fs.readFileSync(process.argv[3] || path.join(root, 'webcommander.php'));
const end = Buffer.from('<!-- WebCommander update end -->\n');
const delimiter = Buffer.from('<?php __halt_compiler();');
const boundary = candidate.indexOf(delimiter) + delimiter.length;
assert.ok(boundary >= delimiter.length);
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'wc-packed-updates-'));
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
let passed = 0;
try {
  const harness = '<?php\ndefine("MC_UPDATE_MAX_BYTES", 16*1024*1024);\n' +
    updater.slice(updater.indexOf('function mc_update_memory_check('), updater.indexOf('function mc_update_can_install(')) +
    '\ntry { mc_update_source_info(fopen($argv[1], "rb")); echo "ACCEPT"; } catch (Throwable $e) { echo "REJECT: ".$e->getMessage(); exit(1); }';
  fs.writeFileSync(path.join(directory, 'check.php'), harness, 'latin1');
  function test(name, bytes, accepted) {
    fs.writeFileSync(path.join(directory, 'candidate.php'), bytes);
    const args = process.env.PHP_INI ? ['-c', process.env.PHP_INI] : [];
    const result = spawnSync(process.env.PHP_BINARY || 'php', [...args, '-d', 'memory_limit=256M',
      path.join(directory, 'check.php'), path.join(directory, 'candidate.php')], {encoding:'utf8'});
    if (result.error) throw result.error;
    assert.equal(result.status, accepted ? 0 : 1, name + ': ' + result.stdout + result.stderr);
    assert.match(result.stdout, accepted ? /^ACCEPT$/ : /^REJECT: .*pack/i, name);
    console.log('PASS ' + name); passed++;
  }
  test('intact binary payload', candidate, true);
  const damaged = Buffer.from(candidate);
  damaged[boundary + 20] ^= 1;
  test('damaged stored bytes even with whole-download checksum', damaged, false);
  test('truncated payload with intact end marker', Buffer.concat([candidate.subarray(0, -end.length - 1), end]), false);
  test('unexpected trailing pack bytes', Buffer.concat([candidate.subarray(0, -end.length), Buffer.from('x'), end]), false);
  test('missing binary boundary', Buffer.concat([candidate.subarray(0, boundary - delimiter.length), end]), false);
  const source = candidate.toString('latin1');
  for (const [name, pattern, replacement] of [
    ['duplicate manifest keys', /'offset' => 0,/, "'offset' => 0, 'offset' => 0,"],
    ['incorrect decoded size', /'bytes' => \d+,/, "'bytes' => 1,"],
    ['overlapping offsets', /'offset' => 0,/, "'offset' => 1,"],
    ['excessive decoded size', /'bytes' => \d+,/, "'bytes' => 99999999,"],
    ['decoded hash mismatch', /'sha256' => '[a-f0-9]{64}'/, "'sha256' => '" + '0'.repeat(64) + "'"],
    ['nonliteral manifest', /'offset' => 0,/, "'offset' => strlen(''),"],
    ['missing manifest', /function mc_asset_manifest\(/, 'function absent_manifest(']
  ]) test(name, Buffer.from(source.replace(pattern, replacement), 'latin1'), false);
  const gzipEntry = source.match(/    '[^']+' => \[\n(?:(?!    \],)[\s\S])*?'gzip' => true,(?:(?!    \],)[\s\S])*?    \],/)[0];
  const gzipOffset = Number(gzipEntry.match(/'offset' => (\d+),/)[1]);
  const gzipLength = Number(gzipEntry.match(/'length' => (\d+),/)[1]);
  const malicious = Buffer.from(candidate);
  malicious.fill(0, boundary + gzipOffset, boundary + gzipOffset + gzipLength);
  const oldHash = gzipEntry.match(/'storedSha256' => '([a-f0-9]{64})'/)[1];
  const forged = malicious.toString('latin1').replace(oldHash,
    sha(malicious.subarray(boundary + gzipOffset, boundary + gzipOffset + gzipLength)));
  test('invalid gzip despite matching stored hash', Buffer.from(forged, 'latin1'), false);
  const good = Buffer.from('good');
  function gzipCandidate(stored) {
    const fields = {type:'text/plain', offset:0, length:stored.length, bytes:good.length,
      gzip:true, sha256:sha(good), storedSha256:sha(stored)};
    const table = Object.entries(fields).map(([key, value]) =>
      "'" + key + "' => " + (typeof value === 'string' ? "'" + value + "'" : String(value)) + ',').join('\n');
    return Buffer.concat([Buffer.from("<?php\n// WebCommander - single-file\ndefine('MC_VERSION', '1.12');\n" +
      "function mc_asset_manifest(): array { static $assets = ['test' => [" + table +
      "],]; return $assets; }\n__halt_compiler();"), stored, end]);
  }
  test('single complete gzip member', gzipCandidate(zlib.gzipSync(good)), true);
  test('concatenated gzip members despite matching first decoded hash',
    gzipCandidate(Buffer.concat([zlib.gzipSync(good), zlib.gzipSync(Buffer.from('bad'))])), false);
  test('gzip trailing junk despite matching stored hash',
    gzipCandidate(Buffer.concat([zlib.gzipSync(good), Buffer.from('junk')])), false);
  console.log(passed + ' packed update tests passed.');
} finally { fs.rmSync(directory, {recursive:true, force:true}); }
