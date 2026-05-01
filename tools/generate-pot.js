/**
 * POT file generator for FlarePress.
 *
 * Extracts translatable strings from:
 *  - app/Data/Constants.php  (PHP constants passed to Utils::localize())
 *  - includes/assets/scripts (TS/TSX files using __() from @wordpress/i18n)
 *
 * Run: node tools/generate-pot.js
 */

const fs   = require('fs');
const path = require('path');

const ROOT      = path.resolve(__dirname, '..');
const POT_PATH  = path.join(ROOT, 'languages', 'flare-press.pot');
const DOMAIN    = 'flare-press';
const VERSION   = require(path.join(ROOT, 'package.json')).version;

// ── 1. Extract PHP constants ──────────────────────────────────────────────────

function extractPhpConstants(filePath) {
    const src     = fs.readFileSync(filePath, 'utf8');
    const entries = [];

    // Match: public const SOME_NAME = 'string value';
    const re = /public\s+const\s+(\w+)\s*=\s*'((?:[^'\\]|\\.)*)'\s*;/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        const name  = m[1];
        const value = m[2].replace(/\\'/g, "'").replace(/\\\\/g, '\\');
        entries.push({ name, value, file: path.relative(ROOT, filePath).replace(/\\/g, '/') });
    }
    return entries;
}

// Only constants whose names start with UI_ or are known menu-title constants
const UI_PREFIXES   = ['UI_'];
const UI_EXTRA_KEYS = ['DASHBOARD_MENU_TITLE', 'LOG_MENU_TITLE'];

function isUiConstant(name) {
    return UI_PREFIXES.some(p => name.startsWith(p)) || UI_EXTRA_KEYS.includes(name);
}

// ── 1b. Extract PHP __() calls from source files ──────────────────────────────

const PHP_DIRS   = ['app', 'flare-press.php'];
const PHP_I18N_RE = /__\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'flare-press'/g;

function extractPhpStrings() {
    const entries = [];

    function scanFile(filePath) {
        const rel = path.relative(ROOT, filePath).replace(/\\/g, '/');
        const src = fs.readFileSync(filePath, 'utf8');
        const re  = new RegExp(PHP_I18N_RE.source, 'g');
        let m;
        while ((m = re.exec(src)) !== null) {
            const value = m[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\');
            entries.push({ value, file: rel });
        }
    }

    function scanDir(dir) {
        for (const f of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, f.name);
            if (f.isDirectory()) {
                scanDir(full);
            } else if (f.name.endsWith('.php')) {
                scanFile(full);
            }
        }
    }

    for (const target of PHP_DIRS) {
        const full = path.join(ROOT, target);
        if (fs.statSync(full).isDirectory()) {
            scanDir(full);
        } else {
            scanFile(full);
        }
    }

    return entries;
}

// ── 2. Extract JS __() calls ──────────────────────────────────────────────────

function extractJsStrings(dir) {
    const entries = [];
    const files   = fs.readdirSync(dir, { withFileTypes: true });

    for (const f of files) {
        const full = path.join(dir, f.name);
        if (f.isDirectory()) {
            entries.push(...extractJsStrings(full));
        } else if (/\.(ts|tsx)$/.test(f.name)) {
            const src = fs.readFileSync(full, 'utf8');
            const re  = /__\(\s*['"]([^'"]+)['"]\s*,\s*['"]flare-press['"]/g;
            let m;
            while ((m = re.exec(src)) !== null) {
                entries.push({
                    value: m[1],
                    file:  path.relative(ROOT, full).replace(/\\/g, '/')
                });
            }
        }
    }
    return entries;
}

// ── 3. Build POT ──────────────────────────────────────────────────────────────

function escapePot(str) {
    return str.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n');
}

function buildPot(phpConstEntries, phpSrcEntries, jsEntries) {
    const now  = new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00');
    const seen = new Map(); // msgid → [locations]

    function add(value, file) {
        if (!seen.has(value)) seen.set(value, []);
        if (!seen.get(value).includes(file)) seen.get(value).push(file);
    }

    // Collect PHP constant entries (UI strings from Constants.php)
    for (const e of phpConstEntries) {
        if (!isUiConstant(e.name)) continue;
        add(e.value, e.file);
    }

    // Collect PHP __() calls from source files
    for (const e of phpSrcEntries) {
        add(e.value, e.file);
    }

    // Collect JS entries
    for (const e of jsEntries) {
        add(e.value, e.file);
    }

    let out = `# Copyright (C) ${new Date().getFullYear()} Yunus Tan
# This file is distributed under the GPL-2.0+.
msgid ""
msgstr ""
"Project-Id-Version: FlarePress ${VERSION}\\n"
"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/flare-press\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: ${now}\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"X-Generator: FlarePress generate-pot.js\\n"
"X-Domain: ${DOMAIN}\\n"

#. Plugin Name of the plugin
#: flare-press.php
msgid "FlarePress"
msgstr ""

#. Description of the plugin
#: flare-press.php
msgid "WordPress plugin for uploading media directly to Cloudflare Images alongside the default uploader."
msgstr ""

#. Author of the plugin
#: flare-press.php
msgid "Yunus Tan"
msgstr ""

`;

    for (const [msgid, locs] of seen) {
        // Skip the plugin name — already added above as header entry
        if (msgid === 'FlarePress') continue;

        out += locs.map(l => `#: ${l}`).join('\n') + '\n';
        out += `msgid "${escapePot(msgid)}"\n`;
        out += `msgstr ""\n\n`;
    }

    return out.trimEnd() + '\n';
}

// ── Main ──────────────────────────────────────────────────────────────────────

const phpConst = extractPhpConstants(path.join(ROOT, 'app/Data/Constants.php'));
const phpSrc   = extractPhpStrings();
const jsAll    = extractJsStrings(path.join(ROOT, 'src/scripts'));

const pot      = buildPot(phpConst, phpSrc, jsAll);

fs.writeFileSync(POT_PATH, pot, 'utf8');

const phpConstCount = phpConst.filter(e => isUiConstant(e.name)).length;
console.log(`✓ Generated ${POT_PATH}`);
console.log(`  PHP strings : ${phpConstCount + phpSrc.length} (${phpConstCount} constants + ${phpSrc.length} __() calls)`);
console.log(`  JS strings  : ${jsAll.length}`);
