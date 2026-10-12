'use strict';

// Development-only build. The deployed application requires no Node/packages.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const {minify} = require('terser');
const CleanCSS = require('clean-css');

const root = path.join(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name));
const sha = data => crypto.createHash('sha256').update(data).digest('hex');
const end = Buffer.from('<!-- WebCommander update end -->\n');
const quote = value => "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

async function build() {
  const vendor = JSON.parse(read('assets/vendor/manifest.json'));
  const fonts = JSON.parse(read('assets/fonts.json'));
  const manifest = {};
  const payload = [];
  let offset = 0;
  function add(name, type, content, gzip = false) {
    const stored = gzip ? zlib.gzipSync(content, {level:9}) : content;
    manifest[name] = {type, offset, length:stored.length, bytes:content.length,
      gzip, sha256:sha(content), storedSha256:sha(stored)};
    if (gzip && !zlib.gunzipSync(stored).equals(content)) throw new Error('GZIP verification failed: ' + name);
    payload.push(stored);
    offset += stored.length;
    return '?wc_asset=' + name + '&v=' + manifest[name].sha256;
  }
  const fontUrls = {};
  for (const placeholder of Object.keys(fonts).sort()) {
    const value = fonts[placeholder];
    if (!value.startsWith('data:font/woff2;base64,')) throw new Error('Unexpected font format.');
    const content = Buffer.from(value.split(',')[1], 'base64');
    const hash = sha(content);
    if (placeholder !== '__WC_FONT_' + hash.slice(0, 24) + '__') throw new Error('Font source checksum mismatch.');
    // WOFF2 is already compressed. Embedding/serving its original binary avoids
    // Base64 and additional GZIP overhead while retaining exact font bytes.
    fontUrls[placeholder] = add('font-' + hash.slice(0, 24), 'font/woff2', content);
  }
  const licenses = read('assets/THIRD_PARTY_LICENSES.txt');
  add('licenses', 'text/plain; charset=utf-8', licenses, true);
  for (const [name, file] of [
    ['ui-css', 'assets/vendor/ui.css'], ['code-css', 'assets/vendor/codemirror.css'],
    ['login-css', 'assets/login.css'], ['app-css', 'assets/app.css']
  ]) {
    let source = read(file).toString('utf8');
    for (const [placeholder, url] of Object.entries(fontUrls)) source = source.split(placeholder).join(url);
    if (source.includes('__WC_FONT_')) throw new Error('Unresolved font URL in ' + file);
    const result = new CleanCSS({level:1, rebase:false}).minify(source);
    if (result.errors.length) throw new Error(file + ': ' + result.errors.join('; '));
    add(name, 'text/css; charset=utf-8', Buffer.from(result.styles), true);
  }
  for (const [name, file] of [
    ['code-js', 'assets/vendor/codemirror.js'], ['theme-js', 'assets/theme.js'],
    ['app-js', 'assets/app.js']
  ]) {
    const source = read(file).toString('utf8');
    const result = await minify(source, {
      ecma:2020, compress:{passes:2}, mangle:true,
      format:{comments:/@license|^!/}
    });
    let code = result.code;
    if (name === 'code-js') {
      const license = source.match(/^\/\*([\s\S]*?)\*\//)[0];
      code = license + '\n' + code;
    }
    add(name, 'application/javascript; charset=utf-8', Buffer.from(code + '\n'), true);
  }
  let table = '[\n';
  for (const [name, entry] of Object.entries(manifest)) {
    table += '    ' + quote(name) + ' => [\n';
    for (const [key, value] of Object.entries(entry)) {
      table += '      ' + quote(key) + ' => ' +
        (typeof value === 'string' ? quote(value) : typeof value === 'boolean' ? String(value) : value) + ',\n';
    }
    table += '    ],\n';
  }
  table += '  ]';
  let helper = read('build/assets.php').toString('utf8').replace(/^<\?php\n/, '');
  helper = helper.replace('/* WC_ASSET_MANIFEST */', table);
  const provenance = '/*\n * Packed assets: CodeMirror ' + vendor.codemirror.version +
    ', Bootstrap ' + vendor.bootstrap.version + ', Font Awesome ' + vendor.fontAwesome.version +
    '.\n * Built with Terser 5.44.0, clean-css 5.3.3 and GZIP level 9.\n' +
    ' * Readable sources and the reproducible builder are in assets/ and build/.\n' +
    ' * No runtime CDN or build dependencies. Text fallback uses PHP zlib.\n *\n' +
    licenses.toString('utf8').split('\n').map(line => (' * ' + line.replace(/\*\//g, '* /')).trimEnd()).join('\n') + '\n */\n';
  let template = read('build/webcommander.template.php').toString('utf8').trimEnd();
  if (template.split('/* WC_ASSET_HELPERS */').length !== 2 ||
      !template.endsWith('<?php __halt_compiler();')) throw new Error('Invalid PHP build template.');
  template = template.replace('/* WC_ASSET_HELPERS */', provenance + helper);
  const output = Buffer.concat([Buffer.from(template), ...payload, end]);
  const limit = template.match(/MC_UPDATE_MAX_BYTES',\s*(\d+)\s*\*\s*1024\s*\*\s*1024/);
  if (!limit || output.length > Number(limit[1]) * 1024 * 1024) {
    throw new Error('The compiled release exceeds its automatic updater size limit.');
  }
  const target = path.join(root, 'webcommander.php');
  if (process.argv.includes('--check')) {
    if (!fs.readFileSync(target).equals(output)) throw new Error('webcommander.php differs from a reproducible build.');
  } else {
    fs.writeFileSync(target, output);
  }
  console.log(JSON.stringify({fileBytes:output.length, phpBytes:Buffer.byteLength(template),
    payloadBytes:offset, assets:Object.keys(manifest).length, sha256:sha(output),
    buildRuntime:{node:process.version,zlib:process.versions.zlib}}, null, 2));
}

build().catch(error => { console.error(error); process.exitCode = 1; });
