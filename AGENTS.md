WebCommander deploys as one generated PHP file containing a binary asset pack.

- Edit PHP/HTML in build/webcommander.template.php and asset routing in build/assets.php.
- Edit readable CSS/JavaScript in assets/. Preserve vendor versions, provenance and licenses.
- Never edit webcommander.php as UTF-8 text or normalize its line endings. Rebuild it.
- Install development dependencies with npm ci --prefix build --ignore-scripts.
- Run npm run build --prefix build, then npm run check --prefix build.
- For releases, bump MC_VERSION in the PHP template before rebuilding.
- Run tests/assets.js, tests/updater.js, tests/keyboard_navigation.js and tests/metadata_symlinks.php on supported PHP versions.
- Resolve merges in readable sources and rebuild the generated PHP artifact.
