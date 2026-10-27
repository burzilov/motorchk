#!/usr/bin/env node
/**
 * Сборка CSS ядра (исходники — public_html/core/scripts/css/) → артефакты ядра:
 * - Tailwind admin.css (Grid, Hallmark) → core/assets/css/admin.css
 * - Tailwind app.css → тема example (skeleton, + копия в public_html/themes/example, если она есть)
 * - site-chrome.css  → core/assets (plain CSS поверх темы)
 *
 * Запуск из workspace (там node_modules с @tailwindcss/cli): npm run build:css
 * Папка scripts/ нужна только для разработки и в release zip не попадает.
 */
import { spawn, spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, watchFile, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const core = join(dirname(fileURLToPath(import.meta.url)), '..');
// workspace = каталог с node_modules; по умолчанию — текущий (npm run выполняется из него)
const workspace = process.env.MOTORCHK_WORKSPACE || process.cwd();
const input = join(core, 'scripts/css/app.css');
const adminInput = join(core, 'scripts/css/admin.css');
const siteChromeSrc = join(core, 'scripts/css/site-chrome.css');
const primary = join(core, 'assets/css/admin.css');
const siteChromeOut = join(core, 'assets/css/site-chrome.css');
const themePrimary = join(core, 'skeleton/themes/example/assets/css/app.css');
const siteThemes = join(core, '..', 'themes');
const copies = existsSync(join(siteThemes, 'example'))
  ? [join(siteThemes, 'example/assets/css/app.css')]
  : [];

const watch = process.argv.includes('--watch');
const cli = join(workspace, 'node_modules/@tailwindcss/cli/dist/index.mjs');
if (!existsSync(cli)) {
  console.error(`Tailwind CLI not found: ${cli}\nRun npm install in the workspace (or set MOTORCHK_WORKSPACE).`);
  process.exit(1);
}

function runCli(src, out, extraArgs = []) {
  const result = spawnSync(
    process.execPath,
    [cli, '-i', src, '-o', out, ...extraArgs],
    { cwd: workspace, stdio: 'inherit' },
  );
  if (result.status !== 0) {
    process.exit(result.status ?? 1);
  }
}

function runCliAsync(src, out, extraArgs = []) {
  return spawn(process.execPath, [cli, '-i', src, '-o', out, ...extraArgs], { cwd: workspace, stdio: 'inherit' });
}

// Tailwind drops plain comments: carry the Hallmark stamp of the admin source over to the built file.
function stampAdmin() {
  const stamp = readFileSync(adminInput, 'utf8').match(/\/\* Hallmark ·[\s\S]*?\*\//g) || [];
  if (stamp.length) writeFileSync(primary, stamp.join('\n') + '\n' + readFileSync(primary, 'utf8'));
}

function syncThemeCopies() {
  for (const target of copies) {
    mkdirSync(dirname(target), { recursive: true });
    copyFileSync(themePrimary, target);
  }
}

function syncSiteChrome() {
  mkdirSync(dirname(siteChromeOut), { recursive: true });
  copyFileSync(siteChromeSrc, siteChromeOut);
}

if (watch) {
  syncSiteChrome();
  watchFile(siteChromeSrc, { interval: 400 }, () => {
    try {
      syncSiteChrome();
      console.log('Synced site-chrome.css');
    } catch (err) {
      console.error(err);
    }
  });
  watchFile(themePrimary, { interval: 400 }, () => {
    try {
      syncThemeCopies();
      console.log('Synced theme CSS copies');
    } catch (err) {
      console.error(err);
    }
  });
  runCliAsync(adminInput, primary, ['--watch']);
  runCliAsync(input, themePrimary, ['--watch']);
} else {
  runCli(adminInput, primary);
  stampAdmin();
  runCli(input, themePrimary);
  syncThemeCopies();
  syncSiteChrome();
  console.log('Built:');
  console.log(`  ${primary}`);
  console.log(`  ${themePrimary}`);
  for (const target of copies) {
    console.log(`  ${target}`);
  }
  console.log(`  ${siteChromeOut}`);
}
