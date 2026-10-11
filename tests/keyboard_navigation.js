'use strict';

/*
 * Run: node tests/keyboard_navigation.js [path/to/webcommander.php]
 * Requires Node.js, Playwright with Chromium, and PHP 7.4+ CLI (test tooling only).
 * Optional: PHP_BINARY, PHP_INI, CHROMIUM_EXECUTABLE, QA_SCREENSHOT.
 * Exercises real keyboard/mouse events and authenticated HTTP actions in a
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
    for (const directory of ['00-first/00-child', '10-empty', '20-many', '30-files']) {
      fs.mkdirSync(path.join(root, directory), {recursive:true});
    }
    for (const name of ['alpha.txt', 'beta.txt', 'gamma.txt']) {
      fs.writeFileSync(path.join(root, '30-files', name), `Contents of ${name}\n`);
    }
    fs.writeFileSync(path.join(root, '00-first', 'child.txt'), 'Child file\n');
    fs.writeFileSync(path.join(root, '30-files', '.hidden.txt'), 'Hidden file\n');
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
    await context.route('**/*', route => new URL(route.request().url()).origin === new URL(url).origin ? route.continue() : route.abort());
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
      const editor = page.locator('textarea[name=content]');
      await editor.fill('Editable text');
      await editor.press('End');
      await page.keyboard.press('Backspace');
      await page.keyboard.press('Enter');
      await page.keyboard.type('Second line');
      assert.equal(await editor.inputValue(), 'Editable tex\nSecond line');
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
