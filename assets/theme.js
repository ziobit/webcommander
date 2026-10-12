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
