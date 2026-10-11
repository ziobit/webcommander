'use strict';

/*
 * Run: node tests/keyboard_navigation.js [path/to/webcommander.php]
 * Requires Node.js, Playwright with Chromium, and PHP 7.4+ CLI (test tooling only).
 * Optional: PHP_BINARY, PHP_INI, CHROMIUM_EXECUTABLE, QA_SCREENSHOT.
 * Exercises keyboard/mouse navigation, offline syntax view/edit, and HTTP actions in a
 * disposable root. No existing deployment or user files are touched.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn} = require('node:child_process');
const {once} = require('node:events');
const {chromium} = require('playwright');

async function main() {
  const base = fs.mkdtempSync(path.join(os.tmpdir(), 'webcommander-keyboard-'));
  const root = path.join(base, 'root');
  const sessions = path.join(base, 'sessions');
  let server;
  let browser;
  let serverLog = '';
  let passed = 0;
  try {
    fs.mkdirSync(root);
    fs.mkdirSync(sessions);
    fs.copyFileSync(process.argv[2] || path.join(__dirname, '..', 'webcommander.php'), path.join(root, 'webcommander.php'));
    for (const directory of ['00-first/00-child', '10-empty', '20-many', '30-files', '40-code']) {
      fs.mkdirSync(path.join(root, directory), {recursive:true});
    }
    for (const name of ['alpha.txt', 'beta.txt', 'gamma.txt']) {
      fs.writeFileSync(path.join(root, '30-files', name), `Contents of ${name}\n`);
    }
    fs.writeFileSync(path.join(root, '00-first', 'child.txt'), 'Child file\n');
    fs.writeFileSync(path.join(root, '30-files', '.hidden.txt'), 'Hidden file\n');
    const samples = {
      'sample.html':['HTML', '<!doctype html>\n<html><style>body { color: red; }</style><script>const count = 42; window.highlightProbe = true;</script><body>Literal &lt;code&gt;</body></html>\n'],
      'sample.js':['JavaScript', '// A comment\nconst count = 42;\nfunction greet(name) { return "Hello " + name; }\n'],
      'sample.php':['PHP', '<?php\n// A PHP comment\n$count = 42;\necho "<script>window.highlightProbe = true;</script>";\n?>\n<div>Hello</div><script>const value = 7;</script>\n'],
      'sample.py':['Python', '# A Python comment\ndef greet(name):\n  return "Hello " + name\ncount = 42\n'],
      'sample.c':['C', '#include <stdio.h>\n/* A C comment */\nint main(void) { printf("Hello"); return 42; }\n'],
      'sample.cpp':['C++', '#include <iostream>\nclass Example { public: int value = 42; };\nint main() { return 0; }\n'],
      'sample.pas':['Pascal', 'program Example;\n{ A Pascal comment }\nvar count: integer;\nbegin\n  count := 42;\n  writeln(\'Hello\');\nend.\n'],
      'sample.css':['CSS', '/* A CSS comment */\nbody { color: red; padding: 2px; }\n'],
      'sample.json':['JSON', '{"count": 42, "enabled": true, "name": "Example"}\n'],
      'sample.sql':['SQL', '-- A SQL comment\nSELECT id, name FROM users WHERE id = 42;\n'],
      'sample.ts':['TypeScript', 'interface Example { count: number; }\nconst item: Example = { count: 42 };\n'],
      'sample.jsx':['JSX', 'const element = <div className="example">Hello</div>;\n'],
      'sample.yaml':['YAML', '# A YAML comment\nname: Example\ncount: 42\n']
    };
    for (const [name, [, content]] of Object.entries(samples)) fs.writeFileSync(path.join(root, '40-code', name), content);
    const multilineCode = '/*\n' + 'A comment that crosses the old page boundary.\n'.repeat(2800) + '*/\nconst afterComment = 42;\n';
    fs.writeFileSync(path.join(root, '40-code', 'multiline.js'), multilineCode);
    const largeCode = 'const value = 42;\n'.repeat(330000);
    fs.writeFileSync(path.join(root, '40-code', 'large.js'), largeCode);
    const largeText = 'A plain-text log entry.\n'.repeat(10000);
    fs.writeFileSync(path.join(root, '40-code', 'log.txt'), largeText);
    const windowsCode = '\uFEFF// Windows line endings\r\nconst count = 42;\r\n';
    fs.writeFileSync(path.join(root, '40-code', 'windows.js'), windowsCode);
    fs.writeFileSync(path.join(root, '40-code', 'empty.py'), '');
    fs.writeFileSync(path.join(root, '40-code', 'unknown.blob'), 'Plain text without a recognized extension\n');
    fs.writeFileSync(path.join(root, '40-code', 'script'), '#!/usr/bin/env python3\nprint("Hello")\n');
    fs.writeFileSync(path.join(root, '40-code', 'UPPER.PHP'), samples['sample.php'][1]);
    fs.writeFileSync(path.join(root, '40-code', 'legacy.pas'), Buffer.concat([Buffer.from('{ Windows-1252: caf'), Buffer.from([0xe8]), Buffer.from(' }\n'.repeat(40000))]));
    for (let index = 0; index < 60; index++) {
      fs.writeFileSync(path.join(root, '20-many', String(index).padStart(2, '0') + '.txt'), 'Scroll fixture\n');
    }

    const socket = net.createServer();
    socket.listen(0, '127.0.0.1');
    await once(socket, 'listening');
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const args = process.env.PHP_INI ? ['-c', process.env.PHP_INI] : [];
    args.push('-d', 'session.save_path=' + sessions, '-S', '127.0.0.1:' + port, '-t', root);
    server = spawn(process.env.PHP_BINARY || 'php', args, {stdio:['ignore', 'pipe', 'pipe']});
    let serverError;
    server.on('error', error => { serverError = error; });
    server.stdout.on('data', chunk => { serverLog += chunk; });
    server.stderr.on('data', chunk => { serverLog += chunk; });
    const url = `http://127.0.0.1:${port}/webcommander.php`;
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
      if (serverError) throw serverError;
      if (server.exitCode !== null) throw new Error('PHP server exited: ' + serverLog);
      try {
        await fetch(url);
        ready = true;
        break;
      } catch (error) {
        await new Promise(resolve => setTimeout(resolve, 50));
      }
    }
    assert.ok(ready, 'PHP server did not start');

    browser = await chromium.launch({
      headless:true,
      ...(process.env.CHROMIUM_EXECUTABLE ? {executablePath:process.env.CHROMIUM_EXECUTABLE} : {})
    });
    const context = await browser.newContext({viewport:{width:1280, height:720}});
    // The app's own CSS and JavaScript are inline; external fonts/icons are not
    // needed to verify its behavior and must not make the suite network-dependent.
    const externalRequests = [];
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin === new URL(url).origin) return route.continue();
      externalRequests.push(route.request().url());
      return route.abort();
    });
    const setup = await context.request.post(url, {form:{
      auth_action:'setup', password:'keyboard-regression-password', confirm_password:'keyboard-regression-password'
    }});
    assert.equal(setup.status(), 200, 'Authentication setup failed');
    const page = await context.newPage();
    const errors = [];
    const deletions = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      if (new URL(request.url()).searchParams.get('action') === 'delete') deletions.push(request.postDataJSON());
    });
    await page.goto(url, {waitUntil:'domcontentloaded'});
    const pane = id => page.locator('#pane-' + id);
    const rows = id => pane(id).locator('tr[data-path]');
    const current = id => pane(id).locator('tr.focused').getAttribute('data-path');
    const list = id => pane(id).locator('.wc-table-wrap');
    const dialog = page.locator('#mainDialog');
    const idle = () => page.waitForFunction(() => WC.busy === 0 && WC.panes.left && WC.panes.right);
    const dialogClosed = () => page.waitForFunction(() => !document.querySelector('#mainDialog').open);
    const navigate = async (id, destination) => {
      await idle();
      await pane(id).locator('.wc-path').fill('/' + destination);
      await pane(id).locator('.wc-path').press('Enter');
      await page.waitForFunction(({id, destination}) => WC.busy === 0 && WC.panes[id].path === destination,
        {id, destination});
    };
    const test = async (name, callback) => {
      await callback();
      passed++;
      console.log('PASS ' + name);
    };
    await idle();

    await test('first actual item is current in both panes at startup', async () => {
      assert.equal(await current('left'), '00-first');
      assert.equal(await current('right'), '00-first');
      assert.equal(await page.locator(':focus').getAttribute('aria-label'), 'Left file pane');
      assert.equal(await pane('left').locator('.row-check:checked').count(), 0);
    });
    await test('arrows move the current row and stop at the upper boundary', async () => {
      await page.keyboard.press('ArrowUp');
      assert.equal(await current('left'), '00-first');
      await page.keyboard.press('ArrowDown');
      assert.equal(await current('left'), '10-empty');
      await page.keyboard.press('ArrowUp');
      assert.equal(await current('left'), '00-first');
    });
    await test('Enter enters a directory and makes its first child current', async () => {
      await page.keyboard.press('Enter');
      await idle();
      assert.equal(await pane('left').locator('.wc-path').inputValue(), '/00-first');
      assert.equal(await current('left'), '00-first/00-child');
      assert.equal(await pane('left').locator('.wc-parent.focused').count(), 0);
    });
    await test('Backspace goes up one level and stays within the root', async () => {
      await page.keyboard.press('Backspace');
      await idle();
      assert.equal(await pane('left').locator('.wc-path').inputValue(), '/');
      assert.equal(await current('left'), '00-first');
      await page.keyboard.press('Backspace');
      await idle();
      assert.equal(await pane('left').locator('.wc-path').inputValue(), '/');
    });
    await test('Tab and Shift-Tab switch panes, retaining each current row', async () => {
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('Tab');
      assert.equal(await page.locator(':focus').getAttribute('aria-label'), 'Right file pane');
      assert.equal(await current('right'), '00-first');
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('Shift+Tab');
      assert.equal(await page.locator(':focus').getAttribute('aria-label'), 'Left file pane');
      assert.equal(await current('left'), '10-empty');
      assert.equal(await current('right'), '20-many');
    });
    await test('empty directories have no current file and Enter/Delete do nothing', async () => {
      await page.keyboard.press('Enter');
      await idle();
      assert.equal(await rows('left').count(), 0);
      for (const key of ['ArrowDown', 'ArrowUp', 'Enter', 'Delete']) await page.keyboard.press(key);
      assert.equal(await dialog.getAttribute('open'), null);
      assert.equal(deletions.length, 0);
      await page.keyboard.press('Backspace');
      await idle();
      assert.equal(await current('left'), '00-first');
    });
    await test('the path input transfers focus to the listing after navigation', async () => {
      await navigate('left', '30-files');
      assert.equal(await current('left'), '30-files/alpha.txt');
      assert.equal(await page.locator(':focus').getAttribute('aria-label'), 'Left file pane');
    });
    await test('mouse selection remains intact while arrows move the current row', async () => {
      await rows('left').first().locator('.row-check').click();
      await page.keyboard.press('ArrowDown');
      assert.equal(await current('left'), '30-files/beta.txt');
      assert.equal(await rows('left').first().locator('.row-check').isChecked(), true);
      assert.equal(await rows('left').nth(1).locator('.row-check').isChecked(), false);
    });
    await test('Enter views the current file even when another file is checked', async () => {
      await page.keyboard.press('Enter');
      await page.waitForFunction(() => document.querySelector('#mainDialog').open);
      assert.equal(await page.locator('#dialogTitle').textContent(), 'beta.txt');
      assert.ok((await page.locator('#dialogBody').textContent()).includes('Contents of beta.txt'));
      await page.keyboard.press('Escape');
      await dialogClosed();
      assert.equal(await page.locator(':focus').getAttribute('aria-label'), 'Left file pane');
    });
    await test('Delete warns about only the current file and focuses Cancel', async () => {
      await page.keyboard.press('Delete');
      assert.deepEqual(await page.locator('#dialogBody li').allTextContents(), ['beta.txt']);
      assert.equal(await page.locator(':focus').textContent(), 'Cancel');
      await page.keyboard.press('Enter');
      await dialogClosed();
      assert.equal(deletions.length, 0);
      assert.equal(fs.existsSync(path.join(root, '30-files/beta.txt')), true);
    });
    await test('rapid Delete-Enter always cancels without issuing a delete request', async () => {
      for (let attempt = 0; attempt < 5; attempt++) {
        await page.keyboard.press('Delete');
        await page.keyboard.press('Enter');
        await dialogClosed();
      }
      assert.equal(deletions.length, 0);
      assert.equal(fs.existsSync(path.join(root, '30-files/beta.txt')), true);
    });
    await test('holding Delete still leaves Enter on Cancel', async () => {
      await page.keyboard.down('Delete');
      await page.keyboard.down('Delete');
      await page.keyboard.up('Delete');
      assert.equal(await page.locator(':focus').textContent(), 'Cancel');
      await page.keyboard.press('Enter');
      await dialogClosed();
      assert.equal(deletions.length, 0);
    });
    await test('F8 retains bulk deletion and also defaults to Cancel', async () => {
      await page.keyboard.press('Insert');
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('F8');
      assert.deepEqual((await page.locator('#dialogBody li').allTextContents()).sort(), ['alpha.txt', 'beta.txt']);
      assert.equal(await page.locator(':focus').textContent(), 'Cancel');
      await page.keyboard.press('Enter');
      await dialogClosed();
      assert.equal(deletions.length, 0);
    });
    await test('deliberate keyboard confirmation deletes only the current file', async () => {
      await page.keyboard.press('ArrowUp');
      await page.keyboard.press('Delete');
      await page.keyboard.press('Shift+Tab');
      assert.equal(await page.locator(':focus').getAttribute('name'), 'confirm');
      await page.keyboard.type('DELETE');
      await page.keyboard.press('Tab');
      assert.equal(await page.locator(':focus').textContent(), 'Cancel');
      await page.keyboard.press('Tab');
      assert.equal(await page.locator(':focus').getAttribute('id'), 'dialogSubmit');
      await page.keyboard.press('Enter');
      await dialogClosed();
      await idle();
      assert.equal(fs.existsSync(path.join(root, '30-files/beta.txt')), false);
      assert.equal(fs.existsSync(path.join(root, '30-files/alpha.txt')), true);
      assert.deepEqual(deletions[0].paths, ['30-files/beta.txt']);
      assert.equal(await pane('left').getAttribute('class'), 'wc-pane active');
      assert.equal(await current('left'), '30-files/alpha.txt');
    });
    await test('function-key View works on the current item without checked rows', async () => {
      await page.keyboard.press('F3');
      await page.waitForFunction(() => document.querySelector('#mainDialog').open);
      assert.equal(await page.locator('#dialogTitle').textContent(), 'alpha.txt');
      await page.keyboard.press('Escape');
      await dialogClosed();
    });
    await test('keyboard shortcuts do not interfere with the text editor', async () => {
      await page.keyboard.press('F4');
      await page.waitForFunction(() => document.querySelector('#mainDialog').open);
      const editor = page.locator('#dialogBody .CodeMirror');
      await page.keyboard.press('ControlOrMeta+A');
      await page.keyboard.type('Editable text');
      await page.keyboard.press('End');
      await page.keyboard.press('Backspace');
      await page.keyboard.press('Enter');
      await page.keyboard.type('Second line');
      assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), 'Editable tex\nSecond line');
      assert.equal(deletions.length, 1);
      await page.keyboard.press('Escape');
      await dialogClosed();
      assert.equal(fs.readFileSync(path.join(root, '30-files/alpha.txt'), 'utf8'), 'Contents of alpha.txt\n');
    });
    await test('filters choose a visible current file and keep native text editing', async () => {
      const filter = pane('left').locator('.wc-filter');
      await filter.fill('gamma');
      assert.equal(await current('left'), '30-files/gamma.txt');
      await filter.press('End');
      await filter.press('Backspace');
      assert.equal(await filter.inputValue(), 'gamm');
      assert.equal(await pane('left').locator('.wc-path').inputValue(), '/30-files');
      await filter.fill('no-such-file');
      assert.equal(await pane('left').locator('tr.focused').count(), 0);
      await filter.fill('');
      assert.equal(await current('left'), '30-files/alpha.txt');
    });
    await test('hiding the current dotfile selects the first remaining visible file', async () => {
      await pane('left').locator('.show-hidden').check();
      await list('left').focus();
      await page.keyboard.press('ArrowUp');
      assert.equal(await current('left'), '30-files/.hidden.txt');
      await pane('left').locator('.show-hidden').uncheck();
      assert.equal(await current('left'), '30-files/alpha.txt');
    });
    await test('cursor movement follows sorted visible rows', async () => {
      await pane('left').locator('th[data-sort=name]').click();
      await list('left').focus();
      await page.keyboard.press('ArrowUp');
      assert.equal(await current('left'), '30-files/gamma.txt');
      await page.keyboard.press('ArrowDown');
      assert.equal(await current('left'), '30-files/alpha.txt');
      await pane('left').locator('th[data-sort=name]').click();
    });
    await test('arrows scroll the current row into view and stop at the lower boundary', async () => {
      await navigate('left', '20-many');
      for (let index = 0; index < 65; index++) await page.keyboard.press('ArrowDown');
      assert.equal(await current('left'), '20-many/59.txt');
      assert.ok(await list('left').evaluate(element => element.scrollTop > 0));
      const visible = await list('left').evaluate(element => {
        const row = element.querySelector('tr.focused').getBoundingClientRect();
        const bounds = element.getBoundingClientRect();
        return row.top >= bounds.top && row.bottom <= bounds.bottom;
      });
      assert.equal(visible, true);
      await page.keyboard.press('Backspace');
      await idle();
      assert.equal(await list('left').evaluate(element => element.scrollTop), 0);
    });
    await test('mouse double-click still enters directories and focuses their first item', async () => {
      await rows('left').first().locator('.wc-name').dblclick();
      await idle();
      assert.equal(await current('left'), '00-first/00-child');
      assert.equal(await page.locator(':focus').getAttribute('aria-label'), 'Left file pane');
    });
    await test('toolbar Enter activates its button instead of viewing the current file', async () => {
      const button = page.locator('.wc-toolbar [data-action=mkdir]');
      await button.focus();
      await page.keyboard.press('Enter');
      await page.waitForFunction(() => document.querySelector('#mainDialog').open);
      assert.equal(await page.locator('#dialogTitle').textContent(), 'Create directory');
      await page.keyboard.press('Escape');
      await dialogClosed();
    });
    await test('right-pane navigation and safe deletion work after Tab', async () => {
      await list('left').focus();
      await page.keyboard.press('Tab');
      assert.equal(await current('right'), '00-first');
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('ArrowDown');
      await page.keyboard.press('Enter');
      await idle();
      assert.equal(await pane('right').locator('.wc-path').inputValue(), '/20-many');
      assert.equal(await current('right'), '20-many/00.txt');
      await page.keyboard.press('Delete');
      assert.equal(await page.locator(':focus').textContent(), 'Cancel');
      await page.keyboard.press('Enter');
      await dialogClosed();
      assert.equal(deletions.length, 1);
      assert.equal(fs.existsSync(path.join(root, '20-many/00.txt')), true);
    });
    const openCode = async (name, editing = false) => {
      await navigate('left', '40-code');
      const filter = pane('left').locator('.wc-filter');
      await filter.fill(name);
      await list('left').focus();
      await page.keyboard.press(editing ? 'F4' : 'Enter');
      await page.waitForFunction(() => document.querySelector('#mainDialog').open && document.querySelector('#dialogBody .CodeMirror'));
      await idle();
      return page.locator('#dialogBody .CodeMirror');
    };
    const closeCode = async () => {
      await page.keyboard.press('Escape');
      await dialogClosed();
    };
    await test('embedded highlighter and UI versions are recorded and load offline', async () => {
      assert.equal(await page.evaluate(() => CodeMirror.version), '5.65.21');
      assert.equal(await page.locator('link[rel=stylesheet]').count(), 0);
      assert.equal(await page.locator('script[src]').count(), 0);
      const source = fs.readFileSync(path.join(root, 'webcommander.php'), 'utf8');
      for (const line of ["MC_CODEMIRROR_VERSION', '5.65.21'", "MC_BOOTSTRAP_VERSION', '5.3.3'", "MC_FONT_AWESOME_VERSION', '6.5.2'"]) assert.ok(source.includes(line));
      assert.ok(source.includes('Permission is hereby granted'));
      assert.ok(fs.statSync(path.join(root, 'webcommander.php')).size < 2 * 1024 * 1024);
      assert.deepEqual(externalRequests, []);
    });
    for (const [name, [language, content]] of Object.entries(samples)) {
      await test(`syntax View and Edit automatically recognize ${language}`, async () => {
        for (const editing of [false, true]) {
          const editor = await openCode(name, editing);
          assert.equal(await page.locator('[data-code-language] option').first().textContent(), 'Auto (' + language + ')');
          assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), content);
          await page.waitForFunction(() => document.querySelector('#dialogBody .CodeMirror [class*=cm-]'));
          assert.ok(await editor.locator('[class*=cm-]').count() > 0);
          assert.equal(await page.evaluate(() => window.highlightProbe), undefined);
          await closeCode();
        }
      });
    }
    await test('HTML/PHP highlighting never executes embedded software', async () => {
      const editor = await openCode('sample.html');
      assert.ok(await editor.locator('.cm-tag').count() > 0);
      assert.ok(await editor.locator('.cm-keyword').count() > 0);
      assert.equal(await page.evaluate(() => window.highlightProbe), undefined);
      await closeCode();
    });
    await test('readonly syntax View cannot change the file or its content', async () => {
      const editor = await openCode('sample.js');
      await editor.click();
      await page.keyboard.press('ControlOrMeta+A');
      await page.keyboard.type('Changed by mistake');
      assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), samples['sample.js'][1]);
      assert.equal(fs.readFileSync(path.join(root, '40-code/sample.js'), 'utf8'), samples['sample.js'][1]);
      await closeCode();
    });
    await test('syntax can be overridden and wrap can be toggled without changing text', async () => {
      const editor = await openCode('unknown.blob', true);
      assert.equal(await page.locator('[data-code-language] option').first().textContent(), 'Auto (Plain text)');
      await page.locator('[data-code-language]').selectOption('text/javascript');
      assert.equal(await editor.evaluate(element => element.CodeMirror.getOption('mode')), 'text/javascript');
      await page.locator('[data-code-wrap]').check();
      assert.equal(await editor.evaluate(element => element.CodeMirror.getOption('lineWrapping')), true);
      await page.locator('[data-code-language]').selectOption('text/plain');
      assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), 'Plain text without a recognized extension\n');
      await closeCode();
    });
    await test('extension aliases, uppercase names and shebang scripts are detected', async () => {
      for (const [name, language] of [['UPPER.PHP', 'PHP'], ['script', 'Python']]) {
        await openCode(name);
        assert.equal(await page.locator('[data-code-language] option').first().textContent(), 'Auto (' + language + ')');
        await closeCode();
      }
    });
    await test('source across a page boundary retains multiline syntax state', async () => {
      const editor = await openCode('multiline.js');
      assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), multilineCode);
      assert.equal(await page.locator('#viewPagination').getAttribute('hidden'), '');
      await editor.evaluate(element => element.CodeMirror.scrollIntoView({line:2803, ch:0}));
      await page.waitForFunction(() => [...document.querySelectorAll('#dialogBody .cm-keyword')].some(element => element.textContent === 'const'));
      await closeCode();
    });
    await test('large source and plain-text logs retain bounded pagination', async () => {
      for (const [name, content] of [['large.js', largeCode], ['log.txt', largeText]]) {
        const editor = await openCode(name);
        assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), content.slice(0, 100 * 1024));
        assert.equal(await page.locator('#viewPagination').getAttribute('hidden'), null);
        await page.locator('#viewNext').click();
        await page.waitForFunction(() => document.querySelector('#viewPage').value === '2' && document.querySelector('#textViewer').getAttribute('aria-busy') === 'false');
        assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), content.slice(100 * 1024, 200 * 1024));
        await closeCode();
      }
    });
    await test('legacy text encoding still has a readable paginated source preview', async () => {
      const editor = await openCode('legacy.pas');
      assert.ok((await editor.evaluate(element => element.CodeMirror.getValue())).startsWith('{ Windows-1252: caf'));
      assert.equal(await page.locator('#viewPagination').getAttribute('hidden'), null);
      await closeCode();
    });
    await test('editing highlights live, preserves undo, indents and saves with a backup', async () => {
      const editor = await openCode('sample.js', true);
      await page.keyboard.press('ControlOrMeta+A');
      await page.keyboard.type('const changed = 99;');
      await page.keyboard.press('Enter');
      await page.keyboard.press('Tab');
      await page.keyboard.type('return "Updated";');
      assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), 'const changed = 99;\n  return "Updated";');
      await page.waitForFunction(() => [...document.querySelectorAll('#dialogBody .cm-number')].some(element => element.textContent === '99'));
      await page.keyboard.press('ControlOrMeta+Z');
      assert.notEqual(await editor.evaluate(element => element.CodeMirror.getValue()), 'const changed = 99;\n  return "Updated";');
      await page.keyboard.press('ControlOrMeta+Shift+Z');
      const saved = await editor.evaluate(element => element.CodeMirror.getValue());
      await page.keyboard.press('ControlOrMeta+Enter');
      await dialogClosed();
      await idle();
      assert.equal(fs.readFileSync(path.join(root, '40-code/sample.js'), 'utf8'), saved);
      assert.ok(fs.readdirSync(path.join(root, '40-code')).some(name => name.startsWith('sample.js.bak')));
    });
    await test('unmodified save and changed save preserve BOM and CRLF endings', async () => {
      let editor = await openCode('windows.js', true);
      await page.keyboard.press('ControlOrMeta+Enter');
      await dialogClosed();
      await idle();
      assert.equal(fs.readFileSync(path.join(root, '40-code/windows.js'), 'utf8'), windowsCode);
      editor = await openCode('windows.js', true);
      await page.keyboard.press('ControlOrMeta+End');
      await page.keyboard.type('// Added');
      await page.keyboard.press('Enter');
      await page.keyboard.press('ControlOrMeta+Enter');
      await dialogClosed();
      await idle();
      const saved = fs.readFileSync(path.join(root, '40-code/windows.js'), 'utf8');
      assert.ok(saved.startsWith('\uFEFF'));
      assert.ok(saved.endsWith('// Added\r\n'));
      assert.equal(/(?<!\r)\n/.test(saved), false);
    });
    await test('empty source can be edited and Cancel leaves it empty', async () => {
      const editor = await openCode('empty.py', true);
      assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), '');
      await page.keyboard.type('print("Not saved")');
      await closeCode();
      assert.equal(fs.readFileSync(path.join(root, '40-code/empty.py'), 'utf8'), '');
    });
    await test('syntax colors adapt to light and dark themes', async () => {
      const editor = await openCode('sample.py');
      for (const theme of ['norton', 'paper']) {
        await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
        const colors = await editor.evaluate(element => ({
          text:getComputedStyle(element).color,
          keyword:getComputedStyle(element.querySelector('.cm-keyword')).color,
          background:getComputedStyle(element).backgroundColor
        }));
        assert.notEqual(colors.keyword, colors.text);
        assert.notEqual(colors.keyword, colors.background);
      }
      await page.evaluate(() => { document.documentElement.dataset.theme = 'norton'; });
      if (process.env.QA_SCREENSHOT) await page.screenshot({path:process.env.QA_SCREENSHOT.replace(/\.png$/, '-view.png')});
      await closeCode();
    });
    await test('mobile syntax editor fits and wraps with keyboard editing intact', async () => {
      await page.setViewportSize({width:390, height:844});
      const editor = await openCode('sample.py', true);
      assert.equal(await editor.evaluate(element => element.CodeMirror.getOption('lineWrapping')), true);
      const bounds = await editor.boundingBox();
      assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 390);
      await page.keyboard.press('ControlOrMeta+A');
      await page.keyboard.type('print("Mobile")');
      assert.equal(await editor.evaluate(element => element.CodeMirror.getValue()), 'print("Mobile")');
      if (process.env.QA_SCREENSHOT) await page.screenshot({path:process.env.QA_SCREENSHOT.replace(/\.png$/, '-mobile-edit.png')});
      await closeCode();
      await page.setViewportSize({width:1280, height:720});
    });
    await test('all styles, icon glyphs, fonts and highlighting work without CDN requests', async () => {
      await page.evaluate(() => document.fonts.load('900 16px "WebCommander Icons"'));
      assert.equal(await page.evaluate(() => document.fonts.check('900 16px "WebCommander Icons"')), true);
      assert.notEqual(await page.locator('[data-action=view] i').first().evaluate(element => getComputedStyle(element, '::before').content), 'none');
      assert.deepEqual(externalRequests, []);
    });
    await test('no browser JavaScript errors occurred', async () => assert.deepEqual(errors, []));
    if (process.env.QA_SCREENSHOT) await page.screenshot({path:process.env.QA_SCREENSHOT});
    console.log(`${passed} passed, 0 failed`);
  } finally {
    if (browser) await browser.close();
    if (server && server.exitCode === null && server.pid) {
      const stopped = once(server, 'exit');
      server.kill();
      await stopped;
    }
    fs.rmSync(base, {recursive:true, force:true});
  }
}

main().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
