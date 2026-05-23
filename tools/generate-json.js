/**
 * JS translation JSON generator for FlarePress.
 *
 * WordPress loads JS translations from JSON files named:
 *   {domain}-{locale}-{md5( relative-path-from-wp-content )}.json
 *
 * This script reads the .po file, extracts JS strings, and writes
 * correctly-named JSON files into languages/.
 *
 * Run: node tools/generate-json.js
 */

const fs     = require('fs');
const path   = require('path');
const crypto = require('crypto');

const ROOT        = path.resolve(__dirname, '..');
const LANG_DIR    = path.join(ROOT, 'languages');
const DOMAIN      = 'flare-load';
// Plugin slug path relative to wp-content/ — always the same on WordPress.org
const PLUGIN_BASE = 'plugins/flare-load/';

// ── Which compiled JS handles contain which source strings ───────────────────
// key   = relative path of compiled JS from plugin root
// value = source TS file paths that were bundled into it (as in .po #: refs)
const BUNDLE_MAP = {
    'dist/main/flareload-media-library-grid.js': [
        'src/scripts/modules/UploadManager.ts',
    ],
    'dist/main/flareload-media-new.js': [
        'src/scripts/modules/UploadManager.ts',
    ],
    'dist/main/flareload-post.js': [
        'src/scripts/modules/UploadManager.ts',
        'src/scripts/main/flareload-post.tsx',
    ],
};

// ── Parse .po file ────────────────────────────────────────────────────────────

function parsePo(filePath) {
    const src     = fs.readFileSync(filePath, 'utf8');
    const entries = [];
    let   current = null;
    let   refs    = [];

    for (const raw of src.split('\n')) {
        const line = raw.trimEnd();

        if (line.startsWith('#: ')) {
            refs.push(line.slice(3).trim());
        } else if (line.startsWith('msgid "')) {
            if (current) entries.push(current);
            current = { refs: [...refs], msgid: '', msgstr: '' };
            refs    = [];
            current.msgid = line.slice(7, -1); // strip msgid " and trailing "
        } else if (line.startsWith('"') && current && !current.msgstr) {
            // continuation of msgid
            current.msgid += line.slice(1, -1);
        } else if (line.startsWith('msgstr "')) {
            if (current) current.msgstr = line.slice(8, -1);
        } else if (line === '' && current) {
            entries.push(current);
            current = null;
        }
    }
    if (current) entries.push(current);

    return entries.filter(e => e.msgid !== '');
}

// ── Build JED 1.x locale_data object ─────────────────────────────────────────

function buildJed(entries, locale, pluralForms) {
    const messages = {
        '': {
            domain: 'messages',
            'plural-forms': pluralForms,
            lang: locale,
        }
    };

    for (const e of entries) {
        if (e.msgstr) {
            messages[e.msgid] = [e.msgstr];
        }
    }

    return {
        'translation-revision-date': new Date().toISOString(),
        generator: 'FlarePress generate-json.js',
        source: `languages/${DOMAIN}-${locale}.po`,
        domain: 'messages',
        locale_data: { messages },
    };
}

// ── Main ──────────────────────────────────────────────────────────────────────

const poFiles = fs.readdirSync(LANG_DIR).filter(f => f.match(/^flare-load-[a-z]{2}_[A-Z]{2}\.po$/));

for (const poFile of poFiles) {
    const locale = poFile.replace(`${DOMAIN}-`, '').replace('.po', ''); // e.g. tr_TR
    const all    = parsePo(path.join(LANG_DIR, poFile));

    // Extract plural-forms from header entry
    const header     = all.find(e => e.msgid === '');
    const pf         = (header?.msgstr ?? '').match(/Plural-Forms:\s*([^\\]+)/)?.[1]?.trim()
                       || 'nplurals=2; plural=(n != 1);';

    let totalFiles = 0;

    for (const [bundlePath, sourcePaths] of Object.entries(BUNDLE_MAP)) {
        // Entries whose #: ref matches one of the source files for this bundle
        const entries = all.filter(e =>
            e.refs.some(r => sourcePaths.some(s => r.includes(s)))
        );

        if (entries.length === 0) continue;

        const jed  = buildJed(entries, locale, pf);
        const rel  = PLUGIN_BASE + bundlePath;           // e.g. plugins/flare-load/includes/dist/...
        const hash = crypto.createHash('md5').update(rel).digest('hex');
        const out  = path.join(LANG_DIR, `${DOMAIN}-${locale}-${hash}.json`);

        fs.writeFileSync(out, JSON.stringify(jed, null, 2), 'utf8');
        console.log(`✓ ${path.basename(out)}  (${entries.length} strings, src: ${bundlePath})`);
        totalFiles++;
    }

    console.log(`  Locale ${locale}: ${totalFiles} JSON file(s) written`);
}
