'use strict';

/*
 * Run: node tests/updater.js [path/to/webcommander.php]
 * Requires Node.js 18+, OpenSSL CLI, and PHP 7.4+ with tokenizer, openssl,
 * allow_url_fopen and cURL. Optional: PHP_BINARY, PHP_INI.
 * Real authenticated HTTP actions download from a local HTTPS fixture server.
 * Both transports run with certificate verification enabled. Filesystem faults
 * are injected only into disposable, namespaced copies; no deployment is touched.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const https = require('node:https');
const net = require('node:net');
const crypto = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {once} = require('node:events');

const cap = 16 * 1024 * 1024;
const end = '<!-- WebCommander update end -->\n';
// Latin-1 preserves every compiled payload byte while editing ASCII PHP test hooks.
const bytesFromText = data => Buffer.from(data, 'latin1');
const digest = data => crypto.createHash('sha256').update(typeof data === 'string' ? bytesFromText(data) : data).digest('hex');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const php = process.env.PHP_BINARY || 'php';
const phpIni = process.env.PHP_INI ? ['-c', process.env.PHP_INI] : [];

async function port() {
  const socket = net.createServer();
  socket.listen(0, '127.0.0.1');
  await once(socket, 'listening');
  const value = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  return value;
}

async function stop(child) {
  if (child && child.exitCode === null && child.signalCode === null) {
    const closed = once(child, 'exit');
    child.kill('SIGTERM');
    await closed;
  }
}

async function main() {
  const base = fs.mkdtempSync(path.join(os.tmpdir(), 'webcommander-updater-'));
  const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '..', 'webcommander.php'), 'latin1');
  const currentVersion = source.match(/define\('MC_VERSION', '([^']+)'\)/)[1];
  const nextVersion = currentVersion.split('.')[0] + '.' + (Number(currentVersion.split('.')[1]) + 1);
  const futureSource = source.replace("define('MC_VERSION', '" + currentVersion + "')", "define('MC_VERSION', '" + nextVersion + "')");
  assert.ok(source.endsWith(end), 'The release must retain its final completeness marker.');
  assert.match(source, /MC_UPDATE_MAX_BYTES', 16 \* 1024 \* 1024/);
  const release = bytes => bytesFromText(futureSource.slice(0, -end.length) +
    '<!--' + 'x'.repeat(Math.max(0, bytes - Buffer.byteLength(futureSource, 'latin1') - 7)) + '-->' + end);
  const large = release(3 * 1024 * 1024);
  assert.ok(large.length > 2 * 1024 * 1024 && large.length < cap);
  let fixture = {body:large};
  let fixtureRequests = 0;
  let redirectRequests = 0;
  let passed = 0;
  let tlsServer;
  let server;
  let serverLog = '';
  try {
    const key = path.join(base, 'key.pem');
    const cert = path.join(base, 'cert.pem');
    const generated = spawnSync('openssl', [
      'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key,
      '-out', cert, '-days', '1', '-subj', '/CN=127.0.0.1',
      '-addext', 'subjectAltName=IP:127.0.0.1'
    ], {encoding:'utf8'});
    assert.equal(generated.status, 0, 'Cannot generate HTTPS fixtures: ' + generated.stderr);
    tlsServer = https.createServer({key:fs.readFileSync(key), cert:fs.readFileSync(cert)}, (request, response) => {
      fixtureRequests++;
      if (fixture.onRequest) fixture.onRequest();
      if (request.url.startsWith('/redirect')) redirectRequests++;
      const body = fixture.body || Buffer.alloc(0);
      response.statusCode = fixture.status || 200;
      response.setHeader('Connection', 'close');
      if (fixture.status === 302) response.setHeader('Location', updateUrl.replace('/release', '/redirect'));
      if (fixture.encoding) response.setHeader('Content-Encoding', fixture.encoding);
      if (fixture.mode === 'chunked') {
        response.setHeader('Transfer-Encoding', 'chunked');
      } else if (fixture.mode !== 'no-length') {
        response.setHeader('Content-Length', fixture.length === undefined ? body.length : fixture.length);
      } else {
        response.useChunkedEncodingByDefault = false;
      }
      response.write(body);
      response.end();
    });
    // A deliberate aborted size-limit read can reset the local fixture socket.
    tlsServer.on('tlsClientError', () => {});
    tlsServer.listen(0, '127.0.0.1');
    await once(tlsServer, 'listening');
    const updateUrl = 'https://127.0.0.1:' + tlsServer.address().port + '/release';

    for (const transport of ['curl', 'stream']) {
      const root = path.join(base, transport);
      const sessions = path.join(root, 'sessions');
      const temp = path.join(root, 'downloads');
      fs.mkdirSync(root);
      fs.mkdirSync(sessions);
      fs.mkdirSync(temp);
      fs.copyFileSync(path.join(__dirname, 'updater_faults.php'), path.join(root, 'updater_faults.php'));
      const target = path.join(root, 'webcommander.php');
      const lockPath = path.join(root, '.webcommander-' + digest(target).slice(0, 12) + '.php.update-lock');
      const control = path.join(root, 'fault.json');
      let installedSource = source.replace(
        "define('MC_UPDATE_URL', 'https://raw.githubusercontent.com/ziobit/webcommander/main/webcommander.php');",
        "define('MC_UPDATE_URL', '" + updateUrl + "');");
      assert.notEqual(installedSource, source, 'Cannot substitute the fixture URL.');
      installedSource = installedSource.replace('declare(strict_types=1);',
        'declare(strict_types=1);\nnamespace WebCommanderUpdaterRegression;\n' +
        'use RuntimeException;\nuse Throwable;\nuse ParseError;\nuse ZipArchive;\nuse Generator;\n' +
        'require __DIR__ . "/updater_faults.php";');
      if (transport === 'stream') {
        assert.equal(installedSource.split("if (function_exists('curl_init')) {").length, 2);
        installedSource = installedSource.replace("if (function_exists('curl_init')) {", 'if (false) {');
      }
      const reset = (fault = '') => {
        fs.writeFileSync(target, installedSource, 'latin1');
        fs.chmodSync(target, 0o640);
        fs.writeFileSync(control, JSON.stringify({fault}));
        fixture = {body:large};
      };
      reset();
      const address = '127.0.0.1:' + await port();
      const url = 'http://' + address + '/webcommander.php';
      const args = phpIni.concat([
        '-d', 'session.save_path=' + sessions, '-d', 'sys_temp_dir=' + temp,
        '-d', 'curl.cainfo=' + cert, '-d', 'openssl.cafile=' + cert,
        '-d', 'memory_limit=256M', '-S', address, '-t', root
      ]);
      serverLog = '';
      server = spawn(php, args, {stdio:['ignore', 'pipe', 'pipe']});
      let serverError;
      server.on('error', error => { serverError = error; });
      server.stdout.on('data', chunk => { serverLog += chunk; });
      server.stderr.on('data', chunk => { serverLog += chunk; });
      let ready = false;
      for (let attempt = 0; attempt < 100; attempt++) {
        if (serverError) throw serverError;
        if (server.exitCode !== null) throw new Error('PHP server exited: ' + serverLog);
        try { await fetch(url); ready = true; break; } catch (_) { await delay(50); }
      }
      assert.ok(ready, 'PHP server failed to start.');
      let cookie = '';
      let csrf;
      async function request(action, data, authenticated = true) {
        const response = await fetch(url + (action ? '?action=' + action : ''), {
          method:'POST',
          headers:{'User-Agent':'WebCommanderUpdaterRegression/1.0',
            'Content-Type':'application/x-www-form-urlencoded', ...(authenticated ? {Cookie:cookie} : {})},
          body:new URLSearchParams(data)
        });
        const setCookie = response.headers.get('set-cookie');
        if (setCookie && authenticated) cookie = setCookie.split(';')[0];
        return {status:response.status, body:await response.text()};
      }
      const password = crypto.randomBytes(16).toString('hex');
      const setup = await request('', {auth_action:'setup', password, confirm_password:password});
      assert.equal(setup.status, 200);
      csrf = setup.body.match(/csrf:\s*"([a-f0-9]{64})"/)[1];
      const config = fs.readdirSync(root).find(name => /^\.webcommander-[a-f0-9]{12}\.php$/.test(name));
      const configBytes = fs.readFileSync(path.join(root, config));
      const api = async (action, data = {}, authenticated = true) => {
        const result = await request(action, {csrf, ...data}, authenticated);
        let json;
        try { json = JSON.parse(result.body); } catch (_) { throw new Error('Invalid API response: ' + result.body.slice(0, 300) + '\n' + serverLog.slice(-3000)); }
        return {status:result.status, ...json};
      };
      const install = data => api('update_install', {
        expected_version:nextVersion, expected_sha256:digest(fixture.body), ...data
      });
      const temporaryFiles = () => fs.readdirSync(root).filter(name => /^\.webcommander-update-.*\.tmp$/.test(name));
      const checkClean = () => {
        assert.deepEqual(temporaryFiles(), [], 'Staging or rollback files leaked.');
        assert.deepEqual(fs.readdirSync(temp), [], 'Download files leaked.');
        assert.deepEqual(fs.readFileSync(path.join(root, config)), configBytes, 'Authentication configuration changed.');
      };
      const unchanged = () => assert.equal(fs.readFileSync(target, 'latin1'), installedSource, 'The running source changed after a failed update.');
      async function test(name, callback) {
        reset();
        await callback();
        checkClean();
        passed++;
        console.log('PASS ' + transport + ': ' + name);
      }
      async function rejected(name, responseFixture, pattern) {
        await test(name, async () => {
          fixture = responseFixture;
          const result = await install();
          assert.equal(result.success, false);
          assert.match(result.message, pattern);
          unchanged();
        });
      }

      await test('updates require authentication', async () => {
        const before = fixtureRequests;
        const result = await api('update_install', {}, false);
        assert.equal(result.success, false);
        assert.equal(result.status, 401);
        assert.equal(fixtureRequests, before);
        unchanged();
      });
      await test('updates require a valid CSRF token', async () => {
        const before = fixtureRequests;
        const result = await install({csrf:'invalid'});
        assert.equal(result.status, 403);
        assert.equal(fixtureRequests, before);
        unchanged();
      });
      await test('checks and atomically installs a release larger than 2 MiB', async () => {
        const checked = await api('update_check');
        assert.equal(checked.success, true);
        assert.equal(checked.currentVersion, currentVersion);
        assert.equal(checked.latestVersion, nextVersion);
        assert.equal(checked.sha256, digest(large));
        assert.equal(checked.updateAvailable, true);
        const result = await install();
        assert.equal(result.success, true);
        assert.equal(result.version, nextVersion);
        assert.equal(digest(fs.readFileSync(target)), digest(large));
        assert.equal(fs.statSync(target).mode & 0o777, 0o640);
      });
      await test('accepts an exact 16 MiB release', async () => {
        fixture = {body:release(cap)};
        assert.equal(fixture.body.length, cap);
        const result = await api('update_check');
        assert.equal(result.success, true);
        assert.equal(result.sha256, digest(fixture.body));
        unchanged();
      });
      await test('accepts a valid chunked release', async () => {
        fixture = {body:large, mode:'chunked'};
        assert.equal((await api('update_check')).success, true);
      });
      await test('accepts a complete release without a length header', async () => {
        fixture = {body:large, mode:'no-length'};
        assert.equal((await api('update_check')).success, true);
      });
      await rejected('rejects an oversized declared download', {body:large, length:cap + 1}, /allowed size/);
      await rejected('aborts an oversized chunked download', {body:release(cap + 1), mode:'chunked'}, /allowed size/);
      await rejected('rejects a short Content-Length download with otherwise complete PHP', {body:large, length:large.length + 100}, /complete|truncated|length|interrupted/);
      await rejected('rejects a truncated chunked HTML tail', {body:large.subarray(0, -100), mode:'chunked'}, /end marker/);
      await rejected('rejects a truncated tail without a length header', {body:large.subarray(0, -100), mode:'no-length'}, /end marker/);
      await rejected('rejects an empty update', {body:Buffer.alloc(0)}, /empty file/);
      await rejected('rejects a non-PHP response', {body:bytesFromText('<html>not an update</html>')}, /valid WebCommander/);
      await rejected('rejects invalid PHP syntax', {body:bytesFromText(futureSource.replace('declare(strict_types=1);', 'declare(strict_types=1);\n$broken = ;'))}, /syntax validation/);
      await rejected('rejects a version that exists only in a comment', {body:bytesFromText("<?php\n// WebCommander - single-file\n// define('MC_VERSION', '" + nextVersion + "');\n?>" + end)}, /literal version/);
      await rejected('rejects duplicate version definitions', {body:bytesFromText(futureSource.replace('declare(strict_types=1);', "declare(strict_types=1);\ndefine('MC_VERSION', '" + nextVersion + "');"))}, /unique literal version/);
      await rejected('rejects a missing version', {body:bytesFromText(futureSource.replace(/define\('MC_VERSION', '[^']+'\);/, ''))}, /valid version/);
      await rejected('rejects compressed responses', {body:large, encoding:'gzip'}, /encoding/);
      await rejected('rejects partial HTTP responses', {body:large, status:206}, /HTTP 206/);
      await rejected('rejects redirects without following them', {body:bytesFromText('moved'), status:302}, /HTTP 302/);
      assert.equal(redirectRequests, 0);
      await test('refuses a non-HTTPS update source', async () => {
        const changed = installedSource.replace(updateUrl, updateUrl.replace('https:', 'http:'));
        fs.writeFileSync(target, changed, 'latin1');
        const before = fixtureRequests;
        assert.match((await install()).message, /must use HTTPS/);
        assert.equal(fixtureRequests, before);
        assert.equal(fs.readFileSync(target, 'latin1'), changed);
      });
      await test('keeps TLS hostname verification enabled', async () => {
        const changed = installedSource.replace(updateUrl, updateUrl.replace('127.0.0.1', 'localhost'));
        fs.writeFileSync(target, changed, 'latin1');
        assert.match((await install()).message, /Cannot download/);
        assert.equal(fs.readFileSync(target, 'latin1'), changed);
      });
      await test('checks legacy versions without a new end marker', async () => {
        fixture = {body:bytesFromText(futureSource.replace("define('MC_VERSION', '" + nextVersion + "')", "define('MC_VERSION', '1.10')").slice(0, -end.length))};
        const checked = await api('update_check');
        assert.equal(checked.success, true);
        assert.equal(checked.updateAvailable, false);
        assert.match((await install({expected_version:'1.10'})).message, /No newer version/);
        unchanged();
      });
      await test('requires the checksum returned by the check', async () => {
        const result = await install({expected_sha256:''});
        assert.equal(result.success, false);
        assert.match(result.message, /checksum/);
        unchanged();
      });
      await test('rejects a changed version between check and install', async () => {
        const result = await install({expected_version:'9.99'});
        assert.equal(result.success, false);
        assert.match(result.message, /available update changed/);
        unchanged();
      });
      await test('rejects changed bytes even when the version stays the same', async () => {
        const checked = await api('update_check');
        fixture = {body:bytesFromText(futureSource)};
        const result = await install({expected_sha256:checked.sha256});
        assert.equal(result.success, false);
        assert.match(result.message, /available update changed/);
        unchanged();
      });
      await test('validates without executing the downloaded source', async () => {
        const probe = path.join(root, 'executed.txt');
        fixture = {body:bytesFromText(futureSource.replace('declare(strict_types=1);', 'declare(strict_types=1);\nfile_put_contents(' + JSON.stringify(probe) + ', "executed");'))};
        assert.equal((await api('update_check')).success, true);
        assert.equal(fs.existsSync(probe), false);
      });
      for (const [fault, pattern] of [
        ['download-write-fail', /temporary update file/],
        ['stage-short', /complete staged update/],
        ['stage-hash-fail', /staged update.*checksum/],
        ['backup-short', /current source changed/],
        ['replace-fail', /atomically replace.*left unchanged/],
        ['verify-fail', /restored and verified/]
      ]) {
        await test('recovers safely from ' + fault, async () => {
          fs.writeFileSync(control, JSON.stringify({fault}));
          const result = await install();
          assert.equal(result.success, false);
          assert.match(result.message, pattern);
          unchanged();
          assert.equal(fs.statSync(target).mode & 0o777, 0o640);
        });
      }
      await test('handles short writes while downloading', async () => {
        fs.writeFileSync(control, JSON.stringify({fault:'download-short-writes'}));
        const result = await api('update_check');
        assert.equal(result.success, true);
        assert.equal(result.sha256, digest(large));
      });
      await test('preserves an independent edit detected before replacement', async () => {
        fs.writeFileSync(control, JSON.stringify({fault:'current-changed'}));
        const result = await install();
        assert.equal(result.success, false);
        assert.match(result.message, /current source changed/);
        assert.equal(fs.readFileSync(target, 'latin1'), installedSource + '\n<!-- concurrent edit -->\n');
      });
      await test('preserves an independent edit made during the download', async () => {
        fixture = {body:large, onRequest:() => fs.appendFileSync(target, '\n<!-- concurrent download edit -->\n')};
        const result = await install();
        assert.equal(result.success, false);
        assert.match(result.message, /current source changed/);
        assert.equal(fs.readFileSync(target, 'latin1'), installedSource + '\n<!-- concurrent download edit -->\n');
      });
      for (const fault of ['rollback-fail', 'rollback-verify-fail']) {
        reset(fault);
        const result = await install();
        assert.equal(result.success, false);
        assert.match(result.message, /automatic recovery failed/);
        assert.doesNotMatch(result.message, /was restored/);
        const backups = temporaryFiles();
        assert.equal(backups.length, 1);
        assert.equal(fs.readFileSync(path.join(root, backups[0]), 'latin1'), installedSource);
        assert.ok(result.message.includes(path.join(root, backups[0])));
        assert.equal(fs.statSync(path.join(root, backups[0])).mode & 0o777, 0o600);
        fs.unlinkSync(path.join(root, backups[0]));
        checkClean();
        passed++;
        console.log('PASS ' + transport + ': retains verified original bytes after ' + fault);
      }
      await test('rejects a symlink used as an update lock', async () => {
        fs.unlinkSync(lockPath);
        const outside = path.join(base, transport + '-outside.txt');
        fs.writeFileSync(outside, 'unchanged');
        fs.symlinkSync(outside, lockPath);
        try {
          assert.match((await install()).message, /safe update lock/);
          assert.equal(fs.readFileSync(outside, 'utf8'), 'unchanged');
          unchanged();
        } finally { fs.unlinkSync(lockPath); }
      });
      await test('blocks simultaneous updates and protects the lock from deletion', async () => {
        const script = path.join(base, 'hold-lock.php');
        fs.writeFileSync(script, '<?php $f=fopen($argv[1],"c+b");flock($f,LOCK_EX);echo "ready\\n";fflush(STDOUT);fgets(STDIN);');
        const holder = spawn(php, phpIni.concat([script, lockPath]), {stdio:['pipe', 'pipe', 'pipe']});
        try {
          await once(holder.stdout, 'data');
          assert.match((await install()).message, /already in progress/);
          const result = await api('delete', {'paths[0]':path.basename(lockPath)});
          assert.equal(result.success, false);
          assert.match(result.message, /protected application file/);
          assert.ok(fs.existsSync(lockPath));
          unchanged();
        } finally { holder.stdin.end('\n'); await stop(holder); }
      });
      await test('refuses validation with insufficient memory', async () => {
        const original = installedSource;
        // This isolated copy lowers its own limit before invoking real handlers.
        fs.writeFileSync(target, installedSource.replace('error_reporting(E_ALL);', 'ini_set("memory_limit", "48M");\nerror_reporting(E_ALL);'), 'latin1');
        const result = await install();
        assert.equal(result.success, false);
        assert.match(result.message, /Insufficient PHP memory/);
        assert.equal(fs.readFileSync(target, 'latin1'), original.replace('error_reporting(E_ALL);', 'ini_set("memory_limit", "48M");\nerror_reporting(E_ALL);'));
      });
      await stop(server);
      server = null;
    }
    console.log('\n' + passed + ' updater regressions passed.');
  } catch (error) {
    console.error(serverLog.slice(-6000));
    throw error;
  } finally {
    await stop(server);
    if (tlsServer) await new Promise(resolve => tlsServer.close(resolve));
    fs.rmSync(base, {recursive:true, force:true});
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
