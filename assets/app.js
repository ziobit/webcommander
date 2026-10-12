'use strict';
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

function isArchive(item) {
  return item.type !== 'dir' && !item.navigable && /\.(?:zip|7z|rar|tar|gz|gzip|tgz|bz|bz2|bzip2|tbz|tbz2|xz|txz|zst|zstd|tzst|lz|lzma|lzh|lzo|cab|arj|ace|jar|war|ear|apk|deb|rpm|iso|r\d{2}|z\d{2}|(?:zip|7z)\.\d{3})$/i.test(item.name);
}

function fileIcon(item) {
  if (item.type === 'dir' || item.navigable) return '<i class="fa-solid fa-folder wc-folder"></i>';
  if (item.type === 'link') return '<i class="fa-solid fa-link wc-link"></i>';
  if (isArchive(item)) return '<i class="fa-solid fa-file-zipper wc-archive-icon"></i>';
  const ext = item.name.includes('.') ? item.name.split('.').pop().toLowerCase() : '';
  const map = {
    php:'fa-file-code', html:'fa-file-code', htm:'fa-file-code', js:'fa-file-code', css:'fa-file-code', json:'fa-file-code', xml:'fa-file-code',
    jpg:'fa-file-image', jpeg:'fa-file-image', png:'fa-file-image', gif:'fa-file-image', webp:'fa-file-image', svg:'fa-file-image',
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

const THEME_OPTIONS = [
  ['norton', 'Norton Commander'], ['midnight', 'Midnight Blue'], ['solarized-dark', 'Solarized Dark'],
  ['solarized-light', 'Solarized Light'], ['nord', 'Nord'], ['gruvbox', 'Gruvbox Dark'],
  ['dracula', 'Dracula'], ['monokai', 'Monokai'], ['forest', 'Forest'], ['paper', 'Paper Light'], ['amber', 'Amber Terminal']
];
const FONT_OPTIONS = [
  ['lucida', 'Lucida Console'], ['consolas', 'Consolas'], ['cascadia', 'Cascadia Mono'],
  ['jetbrains', 'JetBrains Mono'], ['fira', 'Fira Code'], ['source-code', 'Source Code Pro'],
  ['ibm-plex', 'IBM Plex Mono'], ['roboto', 'Roboto Mono'], ['ubuntu', 'Ubuntu Mono'], ['courier', 'Courier New']
];
const THEME_IDS = new Set(THEME_OPTIONS.map(option => option[0]));
const FONT_IDS = new Set(FONT_OPTIONS.map(option => option[0]));
const PANE_COLUMNS = [
  {id:'select', label:'Selection', min:28}, {id:'name', label:'Name', min:80},
  {id:'size', label:'Size', min:45}, {id:'date', label:'Modified', min:65}, {id:'mode', label:'Mode', min:40}
];

function fontProfileKey() {
  const root = document.documentElement;
  return `${root.dataset.font}:${root.dataset.fontSize}`;
}

function columnProfileKey() {
  return `webcommander-columns-v1:${fontProfileKey()}`;
}

function paneSplitProfileKey() {
  return `webcommander-split-v1:${fontProfileKey()}`;
}

function refreshPaneColumns(restore = false) {
  Object.values(WC.panes).forEach(pane => {
    if (restore) pane.restoreColumns();
    else if (!pane.customColumns) pane.fitColumns();
    else pane.applyColumnWidths();
  });
}

let paneSplit = {vertical:.5, horizontal:.5};

function paneSplitLayout() {
  const panes = $('#panes');
  const separator = $('#paneSeparator');
  const horizontal = window.matchMedia('(max-width: 900px)').matches;
  const style = getComputedStyle(panes);
  const padding = horizontal ? parseFloat(style.paddingTop) + parseFloat(style.paddingBottom) : parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
  const available = Math.max(0, (horizontal ? panes.clientHeight - separator.offsetHeight : panes.clientWidth - separator.offsetWidth) - padding);
  const min = available > 0 ? Math.max(.15, Math.min(.45, (horizontal ? 140 : 200) / available)) : .15;
  return {orientation:horizontal ? 'horizontal' : 'vertical', available, min, max:1 - min};
}

function applyPaneSplit() {
  const layout = paneSplitLayout();
  const ratio = Math.max(layout.min, Math.min(layout.max, paneSplit[layout.orientation]));
  const panes = $('#panes');
  panes.style.setProperty('--wc-pane-first', ratio + 'fr');
  panes.style.setProperty('--wc-pane-second', (1 - ratio) + 'fr');
  const separator = $('#paneSeparator');
  const percent = Math.round(ratio * 100);
  separator.setAttribute('aria-orientation', layout.orientation);
  separator.setAttribute('aria-valuemin', Math.round(layout.min * 100));
  separator.setAttribute('aria-valuemax', Math.round(layout.max * 100));
  separator.setAttribute('aria-valuenow', percent);
  separator.setAttribute('aria-valuetext', (layout.orientation === 'horizontal' ? 'Top' : 'Left') + ' pane ' + percent + '%');
  separator.title = `Drag to resize panes (${percent}% / ${100 - percent}%); double-click or Enter for equal panes. Arrow keys resize; Shift makes larger steps.`;
  return {...layout, ratio};
}

function restorePaneSplit() {
  paneSplit = {vertical:.5, horizontal:.5};
  try {
    const saved = JSON.parse(localStorage.getItem(paneSplitProfileKey()) || '{}');
    for (const orientation of ['vertical', 'horizontal']) {
      if (Number.isFinite(saved[orientation]) && saved[orientation] >= .15 && saved[orientation] <= .85) paneSplit[orientation] = saved[orientation];
    }
  } catch (error) {}
  applyPaneSplit();
}

function changePaneSplit(ratio, remember = true) {
  const layout = paneSplitLayout();
  paneSplit[layout.orientation] = Math.max(layout.min, Math.min(layout.max, ratio));
  applyPaneSplit();
  refreshPaneColumns();
  if (remember) {
    try { localStorage.setItem(paneSplitProfileKey(), JSON.stringify(paneSplit)); } catch (error) {}
  }
}

function bindPaneSeparator() {
  const separator = $('#paneSeparator');
  separator.addEventListener('dblclick', event => { event.preventDefault(); changePaneSplit(.5); });
  separator.addEventListener('keydown', event => {
    if (event.key === 'Tab') { event.stopPropagation(); return; }
    const layout = applyPaneSplit();
    const backward = layout.orientation === 'horizontal' ? 'ArrowUp' : 'ArrowLeft';
    const forward = layout.orientation === 'horizontal' ? 'ArrowDown' : 'ArrowRight';
    if (![backward, forward, 'Home', 'End', 'Enter'].includes(event.key)) return;
    event.preventDefault(); event.stopPropagation();
    const step = event.shiftKey ? .05 : .01;
    const ratio = event.key === 'Enter' ? .5 : event.key === 'Home' ? layout.min : event.key === 'End' ? layout.max : layout.ratio + (event.key === forward ? step : -step);
    changePaneSplit(ratio);
  });
  separator.addEventListener('pointerdown', event => {
    if (event.button !== 0) return;
    event.preventDefault();
    const layout = applyPaneSplit();
    if (layout.available <= 0) return;
    const horizontal = layout.orientation === 'horizontal';
    const initial = horizontal ? event.clientY : event.clientX;
    const pointerId = event.pointerId;
    document.body.classList.add('wc-pane-resizing');
    document.body.classList.toggle('wc-pane-resizing-horizontal', horizontal);
    const move = pointer => {
      if (pointer.pointerId !== pointerId) return;
      const delta = (horizontal ? pointer.clientY : pointer.clientX) - initial;
      changePaneSplit(layout.ratio + delta / layout.available, false);
    };
    const finish = pointer => {
      if (pointer.pointerId !== undefined && pointer.pointerId !== pointerId) return;
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', finish);
      document.removeEventListener('pointercancel', finish);
      separator.removeEventListener('lostpointercapture', finish);
      window.removeEventListener('blur', finish);
      document.body.classList.remove('wc-pane-resizing', 'wc-pane-resizing-horizontal');
      try { if (separator.hasPointerCapture(pointerId)) separator.releasePointerCapture(pointerId); } catch (error) {}
      changePaneSplit(paneSplit[paneSplitLayout().orientation]);
    };
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', finish);
    document.addEventListener('pointercancel', finish);
    separator.addEventListener('lostpointercapture', finish);
    window.addEventListener('blur', finish);
    try { separator.setPointerCapture(pointerId); } catch (error) {}
  });
}

function appearanceSettings() {
  const root = document.documentElement;
  return {theme:root.dataset.theme, font:root.dataset.font, fontSize:Number(root.dataset.fontSize)};
}

function applyAppearance(settings, remember = true) {
  const nextTheme = THEME_IDS.has(settings.theme) ? settings.theme : 'norton';
  const nextFont = FONT_IDS.has(settings.font) ? settings.font : 'lucida';
  const parsed = Number.parseInt(String(settings.fontSize), 10);
  const nextSize = Number.isInteger(parsed) && parsed >= 6 && parsed <= 24 ? parsed : 13;
  const root = document.documentElement;
  const changedProfile = root.dataset.font !== nextFont || root.dataset.fontSize !== String(nextSize);
  root.dataset.theme = nextTheme;
  root.dataset.font = nextFont;
  root.dataset.fontSize = String(nextSize);
  root.style.setProperty('--wc-font-size', nextSize + 'px');
  [['themeSelect', nextTheme], ['fontSelect', nextFont], ['fontSizeSelect', String(nextSize)]].forEach(([id, value]) => {
    const select = $('#' + id);
    if (select) select.value = value;
  });
  const themeName = THEME_OPTIONS.find(option => option[0] === nextTheme)[1];
  const fontName = FONT_OPTIONS.find(option => option[0] === nextFont)[1];
  $('#themeButton').title = `${themeName} · ${fontName} · ${nextSize} px`;
  if (remember) {
    try {
      localStorage.setItem('webcommander-theme', nextTheme);
      localStorage.setItem('webcommander-font', nextFont);
      localStorage.setItem('webcommander-font-size', String(nextSize));
    } catch (error) {}
  }
  if (changedProfile) {
    restorePaneSplit();
    refreshPaneColumns(true);
  }
}

function applyTheme(theme, remember = true) { applyAppearance({...appearanceSettings(), theme}, remember); }
function applyFont(font, remember = true) { applyAppearance({...appearanceSettings(), font}, remember); }
function applyFontSize(fontSize, remember = true) { applyAppearance({...appearanceSettings(), fontSize}, remember); }

applyAppearance(appearanceSettings(), false);
restorePaneSplit();
bindPaneSeparator();
window.addEventListener('resize', () => { applyPaneSplit(); refreshPaneColumns(); });
if (document.fonts) {
  document.fonts.ready.then(() => refreshPaneColumns());
  document.fonts.addEventListener('loadingdone', () => refreshPaneColumns());
}

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
    this.list = $('.wc-table-wrap', this.el);
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
    this.table = $('.wc-table', this.el);
    this.headers = $$('thead th[data-column]', this.table);
    this.columnWidths = [];
    this.customColumns = false;
    this.bind();
    this.bindColumns();
    this.restoreColumns();
  }

  bind() {
    this.el.addEventListener('mousedown', () => this.activate());
    this.el.addEventListener('focusin', () => this.activate());
    $('.pane-home', this.el).addEventListener('click', () => this.load('', true));
    $('.pane-up', this.el).addEventListener('click', () => this.load(this.parent, true));
    $('.pane-refresh', this.el).addEventListener('click', () => this.load(this.path, true));
    $('.pane-columns', this.el).addEventListener('click', () => this.editColumns().catch(error => toast(error.message, true, 6000)));
    const pathInput = $('.wc-path', this.el);
    pathInput.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        if (!event.repeat && !WC.busy) this.load(pathInput.value.replace(/^\/+|\/+$/g, ''), true);
      }
    });
    $('.wc-filter', this.el).addEventListener('input', event => { this.filter = event.target.value; this.render(); });
    $('.show-hidden', this.el).addEventListener('change', event => { this.showHidden = event.target.checked; this.render(); });
    $('.select-all', this.el).addEventListener('change', event => {
      if (event.target.checked) this.visibleItems.forEach(item => this.selected.add(item.path));
      else this.visibleItems.forEach(item => this.selected.delete(item.path));
      this.updateRowSelection();
    });
    $('.select-pattern', this.el).addEventListener('click', () => this.selectPattern());
    $('.invert-selection', this.el).addEventListener('click', () => {
      this.visibleItems.forEach(item => this.selected.has(item.path) ? this.selected.delete(item.path) : this.selected.add(item.path));
      this.updateRowSelection();
    });
    $$('th[data-sort]', this.el).forEach(th => th.addEventListener('click', event => {
      if (event.target.closest('.wc-col-resize')) return;
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

  bindColumns() {
    this.headers.forEach((header, index) => {
      const handle = document.createElement('span');
      handle.className = 'wc-col-resize';
      handle.tabIndex = 0;
      handle.setAttribute('role', 'separator');
      handle.setAttribute('aria-orientation', 'vertical');
      handle.setAttribute('aria-label', `Resize ${PANE_COLUMNS[index].label} column`);
      handle.setAttribute('aria-valuemin', PANE_COLUMNS[index].min);
      handle.setAttribute('aria-valuemax', '5000');
      handle.title = 'Drag to resize; double-click to fit this column. Arrow keys resize, Enter fits.';
      header.appendChild(handle);
      handle.addEventListener('click', event => event.stopPropagation());
      handle.addEventListener('dblclick', event => {
        event.preventDefault(); event.stopPropagation();
        this.columnWidths[index] = this.measureColumnWidth(index);
        this.applyColumnWidths();
        this.saveColumns();
      });
      handle.addEventListener('keydown', event => {
        if (event.key === 'Tab') { event.stopPropagation(); return; }
        if (!['ArrowLeft', 'ArrowRight', 'Enter'].includes(event.key)) return;
        event.preventDefault(); event.stopPropagation();
        this.activate();
        const step = event.shiftKey ? 20 : 5;
        this.columnWidths[index] = event.key === 'Enter' ? this.measureColumnWidth(index) : Math.max(PANE_COLUMNS[index].min, Math.min(5000, this.columnWidths[index] + (event.key === 'ArrowRight' ? step : -step)));
        this.applyColumnWidths();
        this.saveColumns();
      });
      handle.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        event.preventDefault(); event.stopPropagation();
        this.activate();
        const initialX = event.clientX;
        const initialWidth = this.columnWidths[index];
        const pointerId = event.pointerId;
        this.customColumns = true;
        document.body.classList.add('wc-column-resizing');
        const move = pointer => {
          if (pointer.pointerId !== pointerId) return;
          this.columnWidths[index] = Math.max(PANE_COLUMNS[index].min, Math.min(5000, Math.round(initialWidth + pointer.clientX - initialX)));
          this.applyColumnWidths();
        };
        const finish = pointer => {
          if (pointer.pointerId !== undefined && pointer.pointerId !== pointerId) return;
          document.removeEventListener('pointermove', move);
          document.removeEventListener('pointerup', finish);
          document.removeEventListener('pointercancel', finish);
          handle.removeEventListener('lostpointercapture', finish);
          window.removeEventListener('blur', finish);
          document.body.classList.remove('wc-column-resizing');
          try { if (handle.hasPointerCapture(pointerId)) handle.releasePointerCapture(pointerId); } catch (error) {}
          this.saveColumns();
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', finish);
        document.addEventListener('pointercancel', finish);
        handle.addEventListener('lostpointercapture', finish);
        window.addEventListener('blur', finish);
        try { handle.setPointerCapture(pointerId); } catch (error) {}
      });
    });
    if (typeof ResizeObserver === 'function') {
      let previousWidth = 0;
      this.columnObserver = new ResizeObserver(entries => {
        const width = entries[0].contentRect.width;
        if (Math.abs(width - previousWidth) < 1) return;
        previousWidth = width;
        if (!this.customColumns) this.fitColumns();
        else this.applyColumnWidths();
      });
      this.columnObserver.observe($('.wc-table-wrap', this.el));
    }
  }

  visibleColumn(index) {
    return getComputedStyle(this.headers[index]).display !== 'none';
  }

  measureColumnWidth(index) {
    if (index === 0) return 32;
    const values = [PANE_COLUMNS[index].label];
    if (index === 2) values.push('1023.9 PB', '<LINK>');
    if (index === 3) values.push('2099-12-31 23:59');
    if (index === 4) values.push('7777');
    this.visibleItems.slice(0, 200).forEach(item => {
      if (index === 1) values.push(item.name + (item.type === 'link' ? ' → ' + (item.linkTarget || '?') : ''));
      if (index === 2) values.push(item.type === 'file' ? formatBytes(item.size) : '<DIR>');
      if (index === 3) values.push(formatDate(item.mtime));
      if (index === 4) values.push(item.mode || '');
    });
    const probe = document.createElement('span');
    const style = getComputedStyle(this.table);
    probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;pointer-events:none';
    probe.style.fontFamily = style.fontFamily;
    probe.style.fontSize = style.fontSize;
    this.el.appendChild(probe);
    const fallback = (Number(document.documentElement.dataset.fontSize) || 13) * .92 * .65;
    let width = 0;
    values.forEach(value => {
      probe.textContent = value;
      width = Math.max(width, probe.getBoundingClientRect().width || Array.from(value).length * fallback);
    });
    probe.remove();
    return Math.max(PANE_COLUMNS[index].min, Math.min(5000, Math.ceil(width + (index === 1 ? 36 : 20))));
  }

  autoColumnWidths() {
    const widths = PANE_COLUMNS.map((column, index) => this.measureColumnWidth(index));
    const available = $('.wc-table-wrap', this.el).clientWidth;
    const otherWidths = widths.reduce((total, width, index) => total + (index !== 1 && this.visibleColumn(index) ? width : 0), 0);
    if (available > 0) widths[1] = Math.max(PANE_COLUMNS[1].min, Math.floor(available - otherWidths));
    return widths;
  }

  fitColumns() {
    this.columnWidths = this.autoColumnWidths();
    this.applyColumnWidths();
  }

  applyColumnWidths() {
    let total = 0;
    this.headers.forEach((header, index) => {
      const width = this.columnWidths[index];
      if (!Number.isFinite(width)) return;
      header.style.width = width + 'px';
      $('.wc-col-resize', header).setAttribute('aria-valuenow', Math.round(width));
      if (this.visibleColumn(index)) total += width;
    });
    if (total > 0) this.table.style.width = total + 'px';
  }

  restoreColumns() {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(columnProfileKey()) || '{}')[this.id]; } catch (error) {}
    this.customColumns = Array.isArray(saved) && saved.length === PANE_COLUMNS.length && saved.every((width, index) => Number.isInteger(width) && width >= PANE_COLUMNS[index].min && width <= 5000);
    if (this.customColumns) {
      this.columnWidths = [...saved];
      this.applyColumnWidths();
    } else this.fitColumns();
  }

  saveColumns() {
    this.customColumns = true;
    try {
      const key = columnProfileKey();
      let profiles = JSON.parse(localStorage.getItem(key) || '{}');
      if (!profiles || typeof profiles !== 'object' || Array.isArray(profiles)) profiles = {};
      profiles[this.id] = [...this.columnWidths];
      localStorage.setItem(key, JSON.stringify(profiles));
    } catch (error) {}
  }

  async editColumns() {
    this.activate();
    const label = this.id === 'left' ? 'Left' : 'Right';
    const profile = FONT_OPTIONS.find(option => option[0] === document.documentElement.dataset.font)[1] + ' · ' + document.documentElement.dataset.fontSize + ' px';
    const pending = showForm(`${label} pane column widths`, [
      {type:'html', html:`<div class="wc-result-note mb-2">Saved separately for this pane and ${escapeHtml(profile)}. You can also drag any header edge or double-click it to fit a column.</div><button class="wc-btn" type="button" id="columnsAutoFit"><i class="fa-solid fa-arrows-left-right-to-line"></i> Fit to pane</button>`},
      ...PANE_COLUMNS.map((column, index) => ({name:column.id, label:column.label + ' (px)', type:'number', value:this.columnWidths[index], min:column.min, max:5000, step:1, required:true}))
    ], {submitLabel:'Save widths'});
    $('#columnsAutoFit').addEventListener('click', () => {
      const widths = this.autoColumnWidths();
      PANE_COLUMNS.forEach((column, index) => { $(`[name="${column.id}"]`, $('#dialogBody')).value = widths[index]; });
    });
    const values = await pending;
    if (!values) return;
    this.columnWidths = PANE_COLUMNS.map(column => {
      const width = Number(values[column.id]);
      if (!Number.isInteger(width) || width < column.min || width > 5000) throw new Error('Enter valid column widths.');
      return width;
    });
    this.applyColumnWidths();
    this.saveColumns();
  }

  activate(focus = false) {
    WC.active = this.id;
    Object.values(WC.panes).forEach(pane => pane.el.classList.toggle('active', pane.id === this.id));
    if (focus) this.list.focus({preventScroll:true});
    this.updateStatus();
  }

  async load(path = this.path, focus = false) {
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
      this.list.scrollTop = 0;
      if (focus && WC.active === this.id && !dialog.open) this.activate(true);
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
    if (!this.visibleItems.some(item => item.path === this.focused)) {
      this.focused = this.visibleItems[0]?.path ?? null;
      this.anchorIndex = this.focused === null ? null : 0;
    }
    this.renderRowsOnly();
  }

  moveCurrent(direction) {
    if (!this.visibleItems.length) return;
    const current = this.visibleItems.findIndex(item => item.path === this.focused);
    const index = Math.max(0, Math.min(this.visibleItems.length - 1, current + direction));
    this.focused = this.visibleItems[index].path;
    this.anchorIndex = index;
    this.activate(true);
    this.updateRowSelection();
    $$('tr[data-path]', this.body)[index].scrollIntoView({block:'nearest', inline:'nearest'});
  }

  renderRowsOnly() {
    const rows = [];
    if (this.path !== '') {
      rows.push(`<tr class="wc-row wc-parent" data-parent="1"><td></td><td colspan="4" class="wc-name"><span class="wc-icon"><i class="fa-solid fa-turn-up wc-up"></i></span>..</td></tr>`);
    }
    this.visibleItems.forEach((item, index) => {
      const selected = this.selected.has(item.path);
      const title = item.type === 'link' ? `${item.name} → ${item.linkTarget || '?'}` : item.name;
      rows.push(`<tr class="wc-row${selected ? ' selected' : ''}${this.focused === item.path ? ' focused' : ''}${item.protected ? ' protected' : ''}${isArchive(item) ? ' archive' : ''}" data-index="${index}" data-path="${escapeHtml(item.path)}" draggable="true" title="${escapeHtml(title)}">
        <td><input class="row-check" type="checkbox" ${selected ? 'checked' : ''} aria-label="Select ${escapeHtml(item.name)}"></td>
        <td class="wc-name"><span class="wc-icon">${fileIcon(item)}</span>${escapeHtml(item.name)}${item.type === 'link' ? ` <span class="text-secondary">→ ${escapeHtml(item.linkTarget || '?')}</span>` : ''}</td>
        <td class="size">${item.type === 'file' ? formatBytes(item.size) : item.type === 'dir' ? '&lt;DIR&gt;' : '&lt;LINK&gt;'}</td>
        <td class="date">${formatDate(item.mtime)}</td>
        <td class="perms">${escapeHtml(item.mode)}</td>
      </tr>`);
    });
    this.body.innerHTML = rows.join('');
    $('.wc-empty', this.el).hidden = rows.length !== 0;
    this.updateRowSelection();
    if (!this.customColumns) this.fitColumns();
  }

  updateRowSelection() {
    // Keep the clicked row in place so native double-click and drag events survive.
    $$('tr[data-path]', this.body).forEach(row => {
      const selected = this.selected.has(row.dataset.path);
      row.classList.toggle('selected', selected);
      row.classList.toggle('focused', this.focused === row.dataset.path);
      row.setAttribute('aria-current', this.focused === row.dataset.path ? 'true' : 'false');
      $('.row-check', row).checked = selected;
    });
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
    this.updateRowSelection();
    this.activate(true);
  }

  handleDoubleClick(event) {
    if (event.target.closest('.row-check')) return;
    event.preventDefault();
    this.activate();
    const row = event.target.closest('tr');
    if (!row) return;
    if (row.dataset.parent) {
      this.load(this.parent, true);
      return;
    }
    const item = this.rowItem(row);
    if (!item) return;
    if (item.type === 'dir' || item.navigable) this.load(item.path, true);
    else {
      this.selected.clear();
      this.selected.add(item.path);
      this.focused = item.path;
      this.updateRowSelection();
      runAction('view');
    }
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
    this.updateRowSelection();
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
      this.updateRowSelection();
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
    this.updateRowSelection();
  }

  getSelected(require = true) {
    const result = this.items.filter(item => this.selected.has(item.path));
    if (require && result.length === 0) {
      const current = this.getCurrent();
      if (current) result.push(current);
    }
    if (require && result.length === 0) throw new Error('Select at least one item.');
    return result;
  }

  getCurrent() {
    return this.visibleItems.find(item => item.path === this.focused);
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
  return `<div class="wc-field"><label>${escapeHtml(field.label)}</label><input class="wc-input" name="${escapeHtml(field.name)}" type="${escapeHtml(field.type || 'text')}" value="${escapeHtml(field.value ?? '')}" ${field.placeholder ? `placeholder="${escapeHtml(field.placeholder)}"` : ''} ${field.required ? 'required' : ''} ${field.minlength ? `minlength="${field.minlength}"` : ''} ${field.min !== undefined ? `min="${escapeHtml(field.min)}"` : ''} ${field.max !== undefined ? `max="${escapeHtml(field.max)}"` : ''} ${field.step !== undefined ? `step="${escapeHtml(field.step)}"` : ''} autocomplete="${field.type === 'password' ? 'new-password' : 'off'}">${field.help ? `<div class="wc-result-note mt-1">${escapeHtml(field.help)}</div>` : ''}</div>`;
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
  // Destructive forms always start on Cancel, before another key can arrive.
  if (options.danger) $('#dialogActions [data-dialog-close]').focus();
  else if (first) first.focus();
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

async function actionTheme() {
  const initial = appearanceSettings();
  const pending = showForm('Theme', [
    {name:'theme', label:'Color scheme', type:'select', value:initial.theme, options:THEME_OPTIONS},
    {name:'font', label:'Font', type:'select', value:initial.font, options:FONT_OPTIONS},
    {name:'font_size', label:'Font size', type:'select', value:initial.fontSize, options:Array.from({length:19}, (_, index) => [index + 6, (index + 6) + ' px'])},
    {type:'html', html:'<div class="wc-result-note">Changes are previewed immediately. Apply saves your choices; Cancel restores the previous appearance. Pane positions and column widths are remembered separately for each font and size.</div>'}
  ], {submitLabel:'Apply'});
  const body = $('#dialogBody');
  [['theme', 'themeSelect'], ['font', 'fontSelect'], ['font_size', 'fontSizeSelect']].forEach(([name, id]) => {
    const select = $(`[name="${name}"]`, body);
    select.id = id;
    select.closest('.wc-field').querySelector('label').htmlFor = id;
    select.addEventListener('change', () => applyAppearance({
      theme:$('#themeSelect').value, font:$('#fontSelect').value, fontSize:$('#fontSizeSelect').value
    }, false));
  });
  const values = await pending;
  applyAppearance(values ? {theme:values.theme, font:values.font, fontSize:values.font_size} : initial, !!values);
}

function collisionField() {
  return {name:'collision', label:'If destination exists', type:'select', value:'rename', options:[['rename','Keep both (automatic new name)'],['overwrite','Overwrite'],['skip','Skip']]};
}

function activePane() { return WC.panes[WC.active]; }
function otherPane() { return WC.panes[WC.active === 'left' ? 'right' : 'left']; }

async function reloadBoth() {
  const pane = activePane();
  await Promise.all([WC.panes.left.load(WC.panes.left.path), WC.panes.right.load(WC.panes.right.path)]);
  pane.activate();
}

function selectedPaths() {
  return activePane().getSelected().map(item => item.path);
}

const CODE_LANGUAGES = CodeMirror.modeInfo.filter(info => info.mode !== 'null' && Object.prototype.hasOwnProperty.call(CodeMirror.modes, info.mode))
  .map(info => ({name:info.name, mime:info.mime || info.mimes[0]}))
  .sort((a, b) => a.name.localeCompare(b.name));

function codeLanguage(path, content = '') {
  const name = baseName(path);
  const aliases = {mjs:'js', cjs:'js', php7:'php', php8:'php', pyw:'py', dpr:'pas', dpk:'pas', pp:'pas', bash:'sh', zsh:'sh', env:'ini', yml:'yaml', svg:'xml', xhtml:'html'};
  const extension = name.split('.').pop().toLowerCase();
  const lookup = aliases[extension] ? `file.${aliases[extension]}` : name;
  let info = CodeMirror.findModeByFileName(lookup);
  if (!info && /^#!.*\bpython(?:[\d.]*)\b/.test(content.split('\n', 1)[0])) info = CodeMirror.findModeByName('Python');
  if (!info && /^#!.*\b(?:ba|da|z|k)?sh\b/.test(content.split('\n', 1)[0])) info = CodeMirror.findModeByName('Shell');
  if (!info && /^#!.*\b(?:node|nodejs)\b/.test(content.split('\n', 1)[0])) info = CodeMirror.findModeByName('JavaScript');
  if (!info && /<\?(?:php\b|=)/i.test(content.slice(0, 8192))) info = CodeMirror.findModeByName('PHP');
  return info && Object.prototype.hasOwnProperty.call(CodeMirror.modes, info.mode) ? {name:info.name, mime:info.mime || info.mimes[0]} : {name:'Plain text', mime:'text/plain'};
}

function codeToolsHtml(path, content, editing = false) {
  const language = codeLanguage(path, content);
  return `<div class="wc-code-tools"><label>Syntax <select class="wc-select" data-code-language aria-label="Syntax language"><option value="auto">Auto (${escapeHtml(language.name)})</option><option value="text/plain">Plain text</option>${CODE_LANGUAGES.map(info => `<option value="${escapeHtml(info.mime)}">${escapeHtml(info.name)}</option>`).join('')}</select></label><label class="wc-check"><input type="checkbox" data-code-wrap ${matchMedia('(max-width: 720px)').matches ? 'checked' : ''}> Wrap lines</label>${editing ? '<span class="wc-result-note">Tab indents · Ctrl/Cmd+Enter saves · Esc cancels</span>' : ''}</div>`;
}

function createCodeMirror(host, tools, path, content, editing = false) {
  const select = $('[data-code-language]', tools);
  const wrap = $('[data-code-wrap]', tools);
  const save = () => $('#dialogForm').requestSubmit();
  const options = {
    value:content, mode:codeLanguage(path, content).mime, theme:'webcommander', readOnly:!editing,
    lineNumbers:true, lineWrapping:wrap.checked, indentUnit:2, tabSize:2, indentWithTabs:false,
    matchBrackets:editing, styleActiveLine:editing, inputStyle:'textarea', autofocus:false,
    screenReaderLabel:editing ? 'File contents editor' : 'File contents viewer',
    maxHighlightLength:10000, workTime:50, workDelay:100, leaveSubmitMethodAlone:true,
    extraKeys:editing ? {
      Esc:false,
      Tab:cm => cm.somethingSelected() ? cm.indentSelection('add') : cm.replaceSelection('  ', 'end', '+input'),
      'Ctrl-Enter':save, 'Cmd-Enter':save, 'Ctrl-S':save, 'Cmd-S':save
    } : {Esc:false, Tab:false, 'Shift-Tab':false}
  };
  const editor = editing ? CodeMirror.fromTextArea(host, options) : CodeMirror(host, options);
  if (editing) {
    editor.on('change', () => editor.save());
    editor.focus();
  }
  select.addEventListener('change', () => editor.setOption('mode', select.value === 'auto' ? codeLanguage(path, editor.getValue()).mime : select.value));
  wrap.addEventListener('change', () => editor.setOption('lineWrapping', wrap.checked));
  if (document.fonts) document.fonts.ready.then(() => { if (editor.getWrapperElement().isConnected) editor.refresh(); });
  return editor;
}

async function showTextView(item) {
  let result = await api('read_text_page', {path:item.path, page:1});
  // Keep multiline strings/comments and mixed PHP/HTML/JS in one syntax state.
  // Large files retain the bounded paginated reader.
  if (codeLanguage(item.path, result.content).mime !== 'text/plain' && result.pages > 1 && result.size <= WC.codeViewBytes) {
    try {
      const full = await api('read_text', {path:item.path});
      result = {...result, content:full.content, size:full.size, page:1, pages:1, offset:0, endOffset:full.size};
    } catch (error) {
      // The existing page is still usable, including for legacy text encodings.
    }
  }
  const tools = `<div class="wc-view-tools"><div class="wc-view-pages" id="viewPagination"><button class="wc-btn" id="viewPrevious" type="button"><i class="fa-solid fa-chevron-left"></i> Previous</button><label>Page <input class="wc-input wc-view-page" id="viewPage" type="number" min="1" step="1" required aria-label="Page number"></label><span>of <span id="viewPageCount"></span></span><button class="wc-btn" id="viewGo" type="button">Go</button><button class="wc-btn" id="viewNext" type="button">Next <i class="fa-solid fa-chevron-right"></i></button></div><span class="wc-result-note" id="viewPosition" aria-live="polite"></span><a class="wc-btn primary" href="${escapeHtml(rawUrl(item.path, true))}"><i class="fa-solid fa-download"></i> Download full file</a></div>`;
  showContent(item.name, tools + codeToolsHtml(item.path, result.content) + '<div class="wc-viewer wc-text-viewer" id="textViewer"><div id="textViewContent"></div></div>');
  const viewer = $('#textViewer');
  const content = $('#textViewContent');
  const editor = createCodeMirror(content, $('.wc-code-tools', $('#dialogBody')), item.path, result.content);
  const pagination = $('#viewPagination');
  const input = $('#viewPage');
  const count = $('#viewPageCount');
  const position = $('#viewPosition');
  const previous = $('#viewPrevious');
  const next = $('#viewNext');
  const go = $('#viewGo');
  let loading = false;

  const updateControls = () => {
    previous.disabled = loading || result.page <= 1;
    next.disabled = loading || result.page >= result.pages;
    input.disabled = go.disabled = loading;
    viewer.setAttribute('aria-busy', String(loading));
  };
  const renderPage = () => {
    editor.setValue(result.content);
    editor.clearHistory();
    editor.scrollTo(0, 0);
    pagination.hidden = result.pages <= 1;
    input.value = result.page;
    input.max = result.pages;
    count.textContent = Number(result.pages).toLocaleString();
    position.textContent = result.size ? `Bytes ${(result.offset + 1).toLocaleString()}–${result.endOffset.toLocaleString()} of ${Number(result.size).toLocaleString()}${result.pages > 1 ? ` · ${formatBytes(result.pageBytes)} per page` : ''}` : 'Empty file';
    viewer.scrollTop = 0;
    $('#dialogBody').scrollTop = 0;
    updateControls();
  };
  const loadPage = async page => {
    if (loading || !viewer.isConnected) return;
    loading = true;
    updateControls();
    try {
      const response = await api('read_text_page', {path:item.path, page});
      if (!viewer.isConnected) return;
      result = response;
      renderPage();
    } catch (error) {
      if (viewer.isConnected) toast(error.message, true, 6000);
    } finally {
      loading = false;
      updateControls();
    }
  };
  const goToPage = () => {
    if (input.reportValidity()) loadPage(Number(input.value));
  };
  previous.addEventListener('click', () => loadPage(result.page - 1));
  next.addEventListener('click', () => loadPage(result.page + 1));
  go.addEventListener('click', goToPage);
  input.addEventListener('keydown', event => {
    if (event.key === 'Enter') { event.preventDefault(); goToPage(); }
  });
  renderPage();
}

async function actionView(item = activePane().getSelected()[0]) {
  if (item.type === 'dir' || item.navigable) { await activePane().load(item.path, true); return; }
  try {
    const props = await api('properties', {paths:[item.path]});
    const mime = props.details[0]?.mime || 'application/octet-stream';
    const url = rawUrl(item.path);
    if (mime.startsWith('image/')) showContent(item.name, `<div class="wc-viewer"><img src="${escapeHtml(url)}" alt="${escapeHtml(item.name)}"></div>`);
    else if (mime === 'application/pdf') showContent(item.name, `<div class="wc-viewer"><iframe src="${escapeHtml(url)}"></iframe></div>`);
    else if (mime.startsWith('audio/')) showContent(item.name, `<div class="wc-viewer"><audio controls autoplay src="${escapeHtml(url)}"></audio></div>`);
    else if (mime.startsWith('video/')) showContent(item.name, `<div class="wc-viewer"><video controls autoplay src="${escapeHtml(url)}"></video></div>`);
    else {
      await showTextView(item);
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
    const pending = showForm(`Edit: ${baseName(path)}`, [
      {type:'html', html:codeToolsHtml(path, content, true)},
      {name:'content', label:fullPathDisplay(path), type:'textarea', value:content},
      {name:'backup', label:'Create a backup before saving', type:'checkbox', value:!isNew}
    ], {wide:true, submitLabel:'Save'});
    const textarea = $('textarea[name="content"]', $('#dialogBody'));
    const editor = createCodeMirror(textarea, $('.wc-code-tools', $('#dialogBody')), path, content, true);
    const values = await pending;
    if (values) {
      const lineSeparator = content.match(/\r\n|\n|\r/)?.[0] || '\n';
      values.content = editor.getValue() === content.replace(/\r\n?/g, '\n') ? content : editor.getValue(lineSeparator);
    }
    editor.toTextArea();
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

async function actionDelete(items = activePane().getSelected()) {
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
  const rows = result.results.map(item => `<div class="wc-result${isArchive(item) ? ' archive' : ''}" data-result-path="${escapeHtml(item.path)}" data-result-type="${escapeHtml(item.type)}"><div><div class="wc-result-path">${fileIcon(item)} ${escapeHtml(fullPathDisplay(item.path))}</div>${item.snippet ? `<div class="wc-result-note">${escapeHtml(item.snippet)}</div>` : ''}</div><div class="wc-result-note">${item.type === 'file' ? formatBytes(item.size) : item.type}</div></div>`).join('');
  showContent(`Search results (${result.results.length})`, `<div class="mb-2 text-secondary">Scanned ${result.scanned.toLocaleString()} items${result.limited ? ' — result limit reached' : ''}</div><div class="wc-result-list">${rows || '<div class="p-3 text-secondary">No matches.</div>'}</div>`);
  $$('.wc-result[data-result-path]', $('#dialogBody')).forEach(node => node.addEventListener('dblclick', async () => {
    const path = node.dataset.resultPath;
    const type = node.dataset.resultType;
    closeDialog();
    if (type === 'dir') pane.load(path);
    else {
      await pane.load(parentPath(path));
      pane.selected.add(path); pane.focused = path; pane.updateRowSelection(); pane.activate();
      actionView();
    }
  }));
}

function treePercentage(size, total, isBaseline = false) {
  if (isBaseline) return 100;
  if (total <= 0) return 0;
  return Math.max(0, Math.min(100, size / total * 100));
}

function treePercentageText(percent) {
  return percent > 0 && percent < 0.1 ? '<0.1%' : percent.toLocaleString(undefined, {maximumFractionDigits:1}) + '%';
}

function treeNodeHtml(node, baselineSize, depth = 0) {
  const children = Array.isArray(node.children) ? node.children : [];
  const folderLabel = node.folders === 1 ? 'folder' : 'folders';
  const fileLabel = node.files === 1 ? 'file' : 'files';
  const classes = ['wc-tree-node'];
  if (!children.length) classes.push('leaf');
  if (node.unreadable) classes.push('unreadable');
  const size = Math.max(0, Number(node.size) || 0);
  const percent = treePercentage(size, baselineSize, depth === 0);
  const percentText = treePercentageText(percent);
  const open = depth === 0 ? ' open' : '';
  const childrenHtml = children.length ? '<div class="wc-tree-children">' + children.map(child => treeNodeHtml(child, baselineSize, depth + 1)).join('') + '</div>' : '';
  const unreadable = node.unreadable ? ' · unreadable' : '';
  const usage = '<span class="wc-tree-usage" title="' + escapeHtml(percentText) + ' of the current 100% folder (' + escapeHtml(formatBytes(baselineSize)) + ')"><span class="wc-tree-bar" aria-hidden="true"><span style="width:' + percent.toFixed(3) + '%"></span></span><span class="wc-tree-percent">' + escapeHtml(percentText) + '</span></span>';
  return '<details class="' + classes.join(' ') + '"' + open + ' data-tree-size="' + size + '"><summary data-tree-path="' + escapeHtml(node.path) + '" title="Double-click to open this folder"><span class="wc-tree-label"><i class="fa-solid fa-folder wc-folder"></i><span class="wc-tree-name">' + escapeHtml(node.name) + '</span></span>' + usage + '<span class="wc-tree-size">' + formatBytes(size) + '</span><span class="wc-tree-meta">' + Number(node.folders).toLocaleString() + ' ' + folderLabel + ' · ' + Number(node.files).toLocaleString() + ' ' + fileLabel + unreadable + '</span></summary>' + childrenHtml + '</details>';
}

function updateTreeNodePercentage(details, baselineSize, baselineName, isBaseline = false) {
  const summary = details.firstElementChild;
  if (!summary) return;
  const size = Math.max(0, Number(details.dataset.treeSize) || 0);
  const percent = treePercentage(size, baselineSize, isBaseline);
  const percentText = treePercentageText(percent);
  const bar = $('.wc-tree-bar > span', summary);
  const percentNode = $('.wc-tree-percent', summary);
  const usage = $('.wc-tree-usage', summary);
  if (bar) bar.style.width = percent.toFixed(3) + '%';
  if (percentNode) percentNode.textContent = percentText;
  if (usage) usage.title = percentText + ' of ' + baselineName + ' (' + formatBytes(baselineSize) + ')';
}

function setTreeBaseline(treeView, baseline) {
  if (!baseline) return;
  const root = $('.wc-tree-node', treeView);
  if (!root) return;

  const rootSummary = root.firstElementChild;
  const rootName = $('.wc-tree-name', rootSummary)?.textContent || '/';
  const rootSize = Math.max(0, Number(root.dataset.treeSize) || 0);
  const allNodes = [root, ...$$('.wc-tree-node', root)];
  allNodes.forEach(node => updateTreeNodePercentage(node, rootSize, rootName, node === root));

  const baselineSummary = baseline.firstElementChild;
  const baselineName = $('.wc-tree-name', baselineSummary)?.textContent || '/';
  const baselineSize = Math.max(0, Number(baseline.dataset.treeSize) || 0);
  const branchNodes = [baseline, ...$$('.wc-tree-node', baseline)];
  branchNodes.forEach(node => updateTreeNodePercentage(node, baselineSize, baselineName, node === baseline));

  $$('.wc-tree-baseline', treeView).forEach(node => node.classList.remove('wc-tree-baseline'));
  baseline.classList.add('wc-tree-baseline');
  const note = $('#treeBaselineNote');
  if (note) note.textContent = '100% = ' + baselineName + ' (' + formatBytes(baselineSize) + ')';
}

async function actionTree(basePath = null) {
  const pane = activePane();
  const treePath = basePath === null ? pane.path : basePath;
  const result = await api('tree', {path:treePath, hidden:pane.showHidden});
  const rootSize = Math.max(0, Number(result.tree.size) || 0);
  const hiddenNote = result.hidden ? 'Hidden items included' : 'Hidden items excluded';
  const unreadable = result.unreadableCount ? '<div class="alert alert-warning py-2 mb-2">' + Number(result.unreadableCount).toLocaleString() + ' item(s) could not be fully read, so affected totals may be incomplete.</div>' : '';
  const tools = '<div class="wc-tree-tools"><button class="wc-btn" id="treeExpandAll" type="button"><i class="fa-solid fa-angles-down"></i> Expand all</button><button class="wc-btn" id="treeCollapseAll" type="button"><i class="fa-solid fa-angles-up"></i> Collapse all</button><div class="wc-result-note">' + formatBytes(result.tree.size) + ' · ' + Number(result.folderCount).toLocaleString() + ' folders · ' + Number(result.fileCount).toLocaleString() + ' files · ' + hiddenNote + '</div></div>';
  const help = '<div class="wc-result-note mb-2"><strong id="treeBaselineNote">100% = ' + escapeHtml(result.tree.name) + ' (' + escapeHtml(formatBytes(rootSize)) + ')</strong> · Opening a folder makes it the new 100% baseline; every descendant is measured against that folder’s recursive total. Subfolders start collapsed, and symlinks are not followed. Double-click opens a folder; Shift + double-click opens it in the other pane.</div>';
  showContent('Folder tree: ' + fullPathDisplay(result.base), tools + help + unreadable + '<div class="wc-tree-view" id="treeView">' + treeNodeHtml(result.tree, rootSize) + '</div>');

  const treeView = $('#treeView');
  const rootNode = $('.wc-tree-node', treeView);
  const treeNodes = $$('.wc-tree-node', treeView);
  let suppressTreeToggle = false;
  setTreeBaseline(treeView, rootNode);

  const finishBulkToggle = () => {
    setTimeout(() => {
      suppressTreeToggle = false;
      setTreeBaseline(treeView, rootNode);
    }, 50);
  };

  $('#treeExpandAll').addEventListener('click', () => {
    suppressTreeToggle = true;
    treeNodes.forEach(node => { node.open = true; });
    finishBulkToggle();
  });
  $('#treeCollapseAll').addEventListener('click', () => {
    suppressTreeToggle = true;
    treeNodes.forEach((node, index) => { node.open = index === 0; });
    finishBulkToggle();
  });

  treeNodes.forEach(node => {
    node.addEventListener('toggle', () => {
      if (suppressTreeToggle) return;
      if (node.open) {
        setTreeBaseline(treeView, node);
        return;
      }
      const current = $('.wc-tree-baseline', treeView);
      if (current && (current === node || node.contains(current))) {
        const parent = node.parentElement ? node.parentElement.closest('.wc-tree-node') : null;
        setTreeBaseline(treeView, parent || rootNode);
      }
    });
  });

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

function permissionSymbol(mode) {
  const bits = [0o400, 0o200, 0o100, 0o40, 0o20, 0o10, 0o4, 0o2, 0o1];
  const chars = bits.map((bit, index) => mode & bit ? 'rwx'[index % 3] : '-');
  if (mode & 0o4000) chars[2] = mode & 0o100 ? 's' : 'S';
  if (mode & 0o2000) chars[5] = mode & 0o10 ? 's' : 'S';
  if (mode & 0o1000) chars[8] = mode & 0o1 ? 't' : 'T';
  return chars.join('');
}

async function actionPermissions() {
  const paths = selectedPaths();
  const info = await api('permission_info', {paths});
  const items = info.details;
  if (items.some(item => item.protected)) throw new Error('Protected application files cannot be changed.');
  const first = items[0];
  const mixed = items.some(item => item.mode !== first.mode);
  const regular = items.some(item => item.type !== 'link');
  const initialMode = /^[0-7]{3,4}$/.test(first.mode) ? first.mode.padStart(4, '0') : '0000';
  const bitInput = (bit, label) => `<input type="checkbox" data-permission-bit="${bit}" aria-label="${escapeHtml(label)}">`;
  const grid = [['Owner', 0o400, 0o200, 0o100], ['Group', 0o40, 0o20, 0o10], ['Others', 0o4, 0o2, 0o1]].map(([label, read, write, execute]) => `<tr><th scope="row">${label}</th><td>${bitInput(read, label + ' read')}</td><td>${bitInput(write, label + ' write')}</td><td>${bitInput(execute, label + ' execute')}</td></tr>`).join('');
  const special = [[0o4000, 'Set UID'], [0o2000, 'Set GID'], [0o1000, 'Sticky bit']].map(([bit, label]) => `<label class="wc-check">${bitInput(bit, label)} ${label}</label>`).join('');
  const presets = ['0644', '0664', '0755', '0750', '0600', '0700'].map(mode => `<button class="wc-btn" type="button" data-permission-preset="${mode}">${mode}</button>`).join('');
  const ownershipOptions = (accounts, field, idField) => {
    const choices = new Map();
    [...accounts, ...items.map(item => ({name:item[field], id:item[idField]}))].forEach(account => {
      choices.set(String(account.id), `${account.name} (${account.id})`);
    });
    return [['', 'Keep existing ' + (field === 'owner' ? 'owner' : 'group')], ...Array.from(choices).sort((a, b) => a[1].localeCompare(b[1])), ['__custom__', 'Other name or ID…']];
  };
  const currentOwners = [...new Set(items.map(item => `${item.owner} (${item.uid})`))].join(', ');
  const currentGroups = [...new Set(items.map(item => `${item.group} (${item.gid})`))].join(', ');
  const selectedNote = paths.length === 1 ? fullPathDisplay(first.path) : paths.length.toLocaleString() + ' selected items';
  const note = mixed ? 'Selected items have different modes. Enable “Change permissions” to apply the grid to all regular items.' : 'Use the grid or enter an octal value. Ownership can be changed independently.';
  const pending = showForm('Permissions and ownership', [
    {type:'html', html:`<div class="wc-mono mb-2">${escapeHtml(selectedNote)}</div><div class="wc-result-note">${escapeHtml(note)}${items.some(item => item.type === 'link') ? '<br>Links keep their permissions; ownership changes apply to the link itself.' : ''}</div>`},
    {name:'change_mode', label:'Change permissions', type:'checkbox', value:regular && !mixed},
    {type:'html', html:`<table class="wc-permission-grid"><thead><tr><th>Who</th><th>Read</th><th>Write</th><th>Execute</th></tr></thead><tbody>${grid}</tbody></table><div class="wc-permission-special">${special}</div><div class="wc-permission-presets">${presets}</div><div class="wc-permission-preview" id="permissionPreview" aria-live="polite"></div>`},
    {name:'mode', label:'Octal permissions', value:initialMode},
    {type:'html', html:`<div class="wc-result-note">Current owner: ${escapeHtml(currentOwners)}<br>Current group: ${escapeHtml(currentGroups)}</div>`},
    {name:'owner', label:'New owner', type:'select', value:'', options:ownershipOptions(info.users, 'owner', 'uid')},
    {name:'owner_custom', label:'Owner name or UID', value:''},
    {name:'group', label:'New group', type:'select', value:'', options:ownershipOptions(info.groups, 'group', 'gid')},
    {name:'group_custom', label:'Group name or GID', value:''},
    {name:'recursive', label:'Apply recursively inside selected directories', type:'checkbox', value:false}
  ], {submitLabel:'Apply'});
  const body = $('#dialogBody');
  const modeInput = $('[name="mode"]', body);
  const changeMode = $('[name="change_mode"]', body);
  const checks = $$('[data-permission-bit]', body);
  const presetButtons = $$('[data-permission-preset]', body);
  const preview = $('#permissionPreview');
  modeInput.pattern = '[0-7]{3,4}';
  modeInput.maxLength = 4;
  const syncMode = () => {
    if (!/^[0-7]{3,4}$/.test(modeInput.value)) { preview.textContent = 'Enter three or four octal digits (0–7).'; return; }
    const mode = Number.parseInt(modeInput.value, 8);
    checks.forEach(check => { check.checked = !!(mode & Number(check.dataset.permissionBit)); });
    preview.textContent = mode.toString(8).padStart(4, '0') + ' · ' + permissionSymbol(mode);
  };
  const toggleMode = () => {
    modeInput.disabled = !changeMode.checked || !regular;
    modeInput.required = !modeInput.disabled;
    checks.forEach(check => { check.disabled = modeInput.disabled; });
    presetButtons.forEach(button => { button.disabled = modeInput.disabled; });
    preview.style.opacity = modeInput.disabled ? '.5' : '1';
  };
  if (!regular) changeMode.disabled = true;
  changeMode.addEventListener('change', toggleMode);
  modeInput.addEventListener('input', syncMode);
  checks.forEach(check => check.addEventListener('change', () => {
    const mode = checks.reduce((value, current) => value | (current.checked ? Number(current.dataset.permissionBit) : 0), 0);
    modeInput.value = mode.toString(8).padStart(4, '0');
    syncMode();
  }));
  presetButtons.forEach(button => button.addEventListener('click', () => { modeInput.value = button.dataset.permissionPreset; syncMode(); }));
  ['owner', 'group'].forEach(field => {
    const select = $(`[name="${field}"]`, body);
    const custom = $(`[name="${field}_custom"]`, body);
    const update = () => {
      const active = select.value === '__custom__';
      custom.closest('.wc-field').hidden = !active;
      custom.disabled = !active;
      custom.required = active;
    };
    select.addEventListener('change', update);
    update();
  });
  syncMode(); toggleMode();
  const values = await pending;
  if (!values) return;
  const owner = String(values.owner === '__custom__' ? values.owner_custom : values.owner || '').trim();
  const group = String(values.group === '__custom__' ? values.group_custom : values.group || '').trim();
  const mode = values.change_mode && regular ? values.mode : '';
  if (mode === '' && owner === '' && group === '') { toast('No changes selected.'); return; }
  const result = await api('chmod_chown', {paths, mode, owner, group, recursive:values.recursive});
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

  const result = await api('update_install', {expected_version:check.latestVersion, expected_sha256:check.sha256});
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
  permissions:actionPermissions, touch:actionTouch, link:actionLink, checksum:actionChecksum, refresh:reloadBoth, update:actionUpdate, theme:actionTheme, password:actionPassword, logout:actionLogout
};

async function runAction(name, item) {
  hideContext();
  const action = actionMap[name];
  if (!action) return;
  try { await action(name === 'delete' && item ? [item] : item); }
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
  if (event.defaultPrevented || dialog.open || event.target.isContentEditable || event.altKey || event.ctrlKey || event.metaKey) return;
  if (event.target.matches('input,textarea,select') && !event.target.matches('.row-check,.select-all')) return;
  if (event.key === 'Tab') { event.preventDefault(); otherPane().activate(true); return; }
  const keys = {F3:'view',F4:'edit',F5:'copy',F6:'move',F7:'mkdir',F8:'delete',F9:'password',F10:'logout'};
  if (keys[event.key]) {
    event.preventDefault();
    if (!event.repeat && !WC.busy) runAction(keys[event.key]);
    return;
  }
  const pane = activePane();
  if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
    event.preventDefault();
    if (!WC.busy) pane.moveCurrent(event.key === 'ArrowUp' ? -1 : 1);
  }
  if (event.key === 'Backspace') {
    event.preventDefault();
    if (!event.repeat && !WC.busy) pane.load(pane.parent, true);
  }
  if (event.key === 'Enter' || event.key === 'Delete') {
    if (event.key === 'Enter' && event.target.closest('button,a,[role=button],#paneSeparator,.wc-col-resize')) return;
    event.preventDefault();
    const item = pane.getCurrent();
    if (item && !event.repeat && !WC.busy) runAction(event.key === 'Enter' ? 'view' : 'delete', item);
  }
  if (event.key === 'Insert' && pane.focused) {
    event.preventDefault();
    if (!event.repeat && !WC.busy) {
      pane.selected.has(pane.focused) ? pane.selected.delete(pane.focused) : pane.selected.add(pane.focused);
      pane.updateRowSelection();
    }
  }
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
Promise.all([WC.panes.left.load(''), WC.panes.right.load('')]).then(() => WC.panes.left.activate(true));
