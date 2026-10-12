'use strict';

/*
 * Run: node tests/assets.js [path/to/webcommander.php]
 * Requires Node.js 18+ and PHP 7.4+ CLI with zlib. Optional: PHP_BINARY, PHP_INI.
 * Uses only the compiled PHP file in a disposable document root.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const net = require('node:net');
const zlib = require('node:zlib');
const crypto = require('node:crypto');
const {spawn} = require('node:child_process');
const {once} = require('node:events');
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

async function main() {
  const base = fs.mkdtempSync(path.join(os.tmpdir(), 'webcommander-assets-'));
  const root = path.join(base, 'root');
  const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '..', 'webcommander.php'));
  const marker = Buffer.from('\n<?php __halt_compiler();');
  const boundary = source.indexOf(marker) + marker.length;
  assert.ok(boundary >= marker.length, 'Compiled binary pack missing.');
  const phpSource = source.subarray(0, boundary).toString('utf8');
  const tableStart = phpSource.indexOf('static $assets = [');
  const table = phpSource.slice(tableStart, phpSource.indexOf('\n  ];', tableStart));
  const assets = {};
  for (const entry of table.matchAll(/    '([^']+)' => \[\n([\s\S]*?)    \],/g)) {
    const asset = {};
    for (const field of entry[2].matchAll(/      '([^']+)' => (.*),\n/g)) {
      const value = field[2];
      asset[field[1]] = value.startsWith("'") ? value.slice(1, -1) :
        value === 'true' ? true : value === 'false' ? false : Number(value);
    }
    assets[entry[1]] = asset;
  }
  assert.ok(Object.keys(assets).length > 10, 'Asset manifest missing.');
  let server;
  let log = '';
  let passed = 0;
  try {
    fs.mkdirSync(root);
    fs.mkdirSync(path.join(base, 'sessions'));
    const target = path.join(root, 'webcommander.php');
    fs.writeFileSync(target, source);
    const socket = net.createServer();
    socket.listen(0, '127.0.0.1');
    await once(socket, 'listening');
    const address = '127.0.0.1:' + socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const args = process.env.PHP_INI ? ['-c', process.env.PHP_INI] : [];
    args.push('-d', 'session.save_path=' + path.join(base, 'sessions'),
      '-d', 'zlib.output_compression=1', '-S', address, '-t', root);
    server = spawn(process.env.PHP_BINARY || 'php', args, {stdio:['ignore', 'pipe', 'pipe']});
    let startError;
    server.on('error', error => { startError = error; });
    server.stdout.on('data', value => { log += value; });
    server.stderr.on('data', value => { log += value; });
    const url = 'http://' + address + '/webcommander.php';
    async function request(query, headers = {}, method = 'GET') {
      return new Promise((resolve, reject) => {
        const connection = http.request(url + query, {headers, method}, response => {
          const chunks = [];
          response.on('data', chunk => chunks.push(chunk));
          response.on('end', () => resolve({status:response.statusCode,
            headers:response.headers, body:Buffer.concat(chunks)}));
          response.on('error', reject);
        });
        connection.on('error', reject);
        connection.end();
      });
    }
    const assetUrl = name => '?wc_asset=' + name + '&v=' + assets[name].sha256;
    for (let attempt = 0; attempt < 100; attempt++) {
      if (startError) throw startError;
      if (server.exitCode !== null) throw new Error('PHP server exited: ' + log);
      try { await request(assetUrl('app-js')); break; } catch (_) {
        if (attempt === 99) throw new Error('PHP server did not start.');
        await new Promise(resolve => setTimeout(resolve, 50));
      }
    }
    async function test(name, callback) {
      await callback();
      passed++;
      console.log('PASS ' + name);
    }
    for (const [name, asset] of Object.entries(assets)) {
      await test('serves exact packed bytes and identity fallback: ' + name, async () => {
        const identity = await request(assetUrl(name), {'Accept-Encoding':'identity'});
        const compressed = await request(assetUrl(name), {'Accept-Encoding':'br, gzip'});
        assert.equal(identity.status, 200);
        assert.equal(compressed.status, 200);
        assert.equal(identity.headers['content-encoding'], undefined);
        assert.equal(identity.body.length, asset.bytes);
        assert.equal(sha(identity.body), asset.sha256);
        assert.equal(Number(identity.headers['content-length']), identity.body.length);
        assert.equal(identity.headers['content-type'], asset.type);
        assert.equal(identity.headers['set-cookie'], undefined);
        assert.match(identity.headers['cache-control'], /public.*max-age=31536000.*immutable.*no-transform/);
        assert.equal(identity.headers.vary, 'Accept-Encoding');
        assert.equal(identity.headers['x-content-type-options'], 'nosniff');
        assert.equal(identity.headers['x-frame-options'], 'SAMEORIGIN');
        assert.equal(identity.headers['referrer-policy'], 'no-referrer');
        const stored = source.subarray(boundary + asset.offset, boundary + asset.offset + asset.length);
        assert.ok(compressed.body.equals(stored));
        assert.equal(sha(compressed.body), asset.storedSha256);
        if (asset.gzip) {
          assert.equal(compressed.headers['content-encoding'], 'gzip');
          assert.ok(zlib.gunzipSync(compressed.body).equals(identity.body));
          assert.notEqual(compressed.headers.etag, identity.headers.etag);
        } else {
          assert.equal(compressed.headers['content-encoding'], undefined);
          assert.ok(compressed.body.equals(identity.body));
        }
      });
    }
    await test('asset routing creates no sessions or configuration and needs no sidecar files', async () => {
      assert.deepEqual(fs.readdirSync(root), ['webcommander.php']);
      assert.deepEqual(fs.readdirSync(path.join(base, 'sessions')), []);
    });
    await test('handles encoding quality and explicit GZIP refusal', async () => {
      for (const encoding of ['gzip;q=0', '*;q=1, gzip;q=0']) {
        const response = await request(assetUrl('app-js'), {'Accept-Encoding':encoding});
        assert.equal(response.status, 200);
        assert.equal(response.headers['content-encoding'], undefined);
        assert.equal(sha(response.body), assets['app-js'].sha256);
      }
      assert.equal((await request(assetUrl('app-js'), {'Accept-Encoding':'*;q=1'})).headers['content-encoding'], 'gzip');
      const refused = await request(assetUrl('app-js'), {'Accept-Encoding':'gzip;q=0, identity;q=0'});
      assert.equal(refused.status, 406);
      assert.equal(refused.headers['cache-control'], 'no-store');
    });
    await test('uses representation-specific ETags and conditional 304 responses', async () => {
      for (const encoding of ['identity', 'gzip']) {
        const first = await request(assetUrl('app-js'), {'Accept-Encoding':encoding});
        for (const tag of [first.headers.etag, 'W/' + first.headers.etag, '"other", ' + first.headers.etag, '*']) {
          const cached = await request(assetUrl('app-js'), {'Accept-Encoding':encoding, 'If-None-Match':tag});
          assert.equal(cached.status, 304);
          assert.equal(cached.body.length, 0);
          assert.equal(cached.headers.etag, first.headers.etag);
          assert.match(cached.headers['cache-control'], /immutable/);
          assert.equal(cached.headers.vary, 'Accept-Encoding');
        }
      }
      const identity = await request(assetUrl('app-js'), {'Accept-Encoding':'identity'});
      const gzip = await request(assetUrl('app-js'), {'Accept-Encoding':'gzip', 'If-None-Match':identity.headers.etag});
      assert.equal(gzip.status, 200);
    });
    await test('supports HEAD without sending an asset body', async () => {
      const head = await request(assetUrl('app-js'), {'Accept-Encoding':'gzip'}, 'HEAD');
      assert.equal(head.status, 200);
      assert.equal(head.body.length, 0);
      assert.equal(Number(head.headers['content-length']), assets['app-js'].length);
      assert.equal(head.headers['content-encoding'], 'gzip');
    });
    await test('does not serve source/configuration paths or unknown asset versions', async () => {
      for (const query of ['?wc_asset=../../etc/passwd&v=x', '?wc_asset=webcommander.php&v=x',
        '?wc_asset[]=app-js&v=x', '?wc_asset=app-js&v[]=x',
        '?wc_asset=app-js', '?wc_asset=app-js&v=' + '0'.repeat(64)]) {
        const response = await request(query);
        assert.equal(response.status, 404);
        assert.equal(response.headers['cache-control'], 'no-store');
        assert.equal(response.headers['set-cookie'], undefined);
      }
      const post = await request(assetUrl('app-js'), {}, 'POST');
      assert.equal(post.status, 405);
      assert.equal(post.headers.allow, 'GET, HEAD');
    });
    await test('public assets do not bypass authentication for application actions', async () => {
      assert.equal((await request('?action=update_check')).status, 401);
      const combined = await request(assetUrl('app-js') + '&action=delete');
      assert.equal(combined.status, 200);
      assert.equal(sha(combined.body), assets['app-js'].sha256);
      assert.ok(fs.readFileSync(target).equals(source));
    });
    await test('font URLs point to exact versioned assets on the same PHP endpoint', async () => {
      const css = (await request(assetUrl('ui-css'))).body.toString('utf8');
      const fonts = [...css.matchAll(/\?wc_asset=(font-[a-f0-9]+)&v=([a-f0-9]{64})/g)];
      assert.ok(fonts.length > 1);
      for (const font of fonts) assert.equal(assets[font[1]].sha256, font[2]);
      assert.ok(!/url\(["']?https?:/.test(css), 'CSS must not request external resources.');
      assert.ok(!/data:font\//.test(css), 'Fonts must use the packed endpoints.');
    });
    await test('rejects corrupted packed data without caching or exposing a partial body', async () => {
      const damaged = Buffer.from(source);
      damaged[boundary + assets['app-js'].offset + 8] ^= 0xff;
      fs.writeFileSync(target, damaged);
      const response = await request(assetUrl('app-js'), {'Accept-Encoding':'gzip'});
      assert.equal(response.status, 500);
      assert.equal(response.headers['cache-control'], 'no-store');
      assert.equal(response.headers['content-encoding'], undefined);
      assert.match(response.body.toString(), /failed verification/);
      fs.writeFileSync(target, source);
    });
    await test('rejects a truncated asset pack', async () => {
      fs.writeFileSync(target, source.subarray(0, boundary + assets['app-js'].offset + 10));
      const response = await request(assetUrl('app-js'));
      assert.equal(response.status, 500);
      assert.match(response.body.toString(), /incomplete/);
      assert.equal(response.headers['cache-control'], 'no-store');
      fs.writeFileSync(target, source);
    });
    console.log('\n' + passed + ' asset regressions passed.');
  } catch (error) {
    console.error(log.slice(-5000));
    throw error;
  } finally {
    if (server && server.exitCode === null && server.signalCode === null) {
      const closed = once(server, 'exit');
      server.kill('SIGTERM');
      await closed;
    }
    fs.rmSync(base, {recursive:true, force:true});
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
