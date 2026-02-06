// Dev build script

const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');
const sass = require('sass');

const wpExternalsPlugin = {
    name: 'wp-externals',
    setup(build) {
        build.onResolve({ filter: /^@wordpress\// }, args => {
            const moduleName = args.path.replace('@wordpress/', '');
            return {
                path: args.path,
                namespace: 'wp-external'
            };
        });

        build.onLoad({ filter: /.*/, namespace: 'wp-external' }, args => {
            const moduleName = args.path.replace('@wordpress/', '');
            const wpGlobal = moduleName.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());

            return {
                contents: `module.exports = window.wp['${wpGlobal}']`,
                loader: 'js'
            };
        });
    }
};

function getEntryPoints(dir) {
    const files = fs.readdirSync(dir);
    const entryPoints = [];

    files.forEach(file => {
        const fullPath = path.join(dir, file);
        const stat = fs.statSync(fullPath);

        if (stat.isDirectory()) {
            entryPoints.push(...getEntryPoints(fullPath));
        } else if (file.endsWith('.ts') || file.endsWith('.tsx')) {
            entryPoints.push(fullPath);
        }
    });

    return entryPoints;
}

function getScssFiles(dir) {
    if (!fs.existsSync(dir)) {
        console.log(`Directory ${dir} does not exist, skipping SCSS compilation`);
        return [];
    }

    const files = fs.readdirSync(dir);
    const scssFiles = [];

    files.forEach(file => {
        const fullPath = path.join(dir, file);
        const stat = fs.statSync(fullPath);

        if (stat.isDirectory()) {
            scssFiles.push(...getScssFiles(fullPath));
        } else if (file.endsWith('.scss') && !file.startsWith('_')) {
            scssFiles.push(fullPath);
        }
    });

    return scssFiles;
}

function compileScss(filePath) {
    try {
        const result = sass.compile(filePath, {
            sourceMap: true,
            style: 'expanded'
        });

        const fileName = path.basename(filePath, '.scss') + '.css';
        const outputPath = path.join('dist', 'css', fileName);
        const outputDir = path.dirname(outputPath);

        if (!fs.existsSync(outputDir)) {
            fs.mkdirSync(outputDir, { recursive: true });
        }

        fs.writeFileSync(outputPath, result.css);
        console.log(`✓ Compiled: ${filePath} → ${outputPath}`);
    } catch (error) {
        console.error(`✗ Error compiling ${filePath}:`, error.message);
    }
}

function watchScss(dir) {
    const scssFiles = getScssFiles(dir);

    scssFiles.forEach(file => {
        compileScss(file);

        fs.watch(file, (eventType) => {
            if (eventType === 'change') {
                console.log(`\nChange detected in ${file}`);
                compileScss(file);
            }
        });
    });

    fs.watch(dir, { recursive: true }, (eventType, filename) => {
        if (filename && filename.endsWith('.scss')) {
            const fullPath = path.join(dir, filename);
            if (fs.existsSync(fullPath) && !path.basename(filename).startsWith('_')) {
                console.log(`\nChange detected in ${fullPath}`);
                const allScssFiles = getScssFiles(dir);
                allScssFiles.forEach(compileScss);
            } else if (path.basename(filename).startsWith('_')) {
                console.log(`\nPartial changed: ${filename}, recompiling all SCSS...`);
                const allScssFiles = getScssFiles(dir);
                allScssFiles.forEach(compileScss);
            }
        }
    });
}

const scriptEntryPoints = getEntryPoints('assets/scripts/main');
const scssFiles = getScssFiles('assets/styles');

const buildConfig = {
    entryPoints: scriptEntryPoints,
    bundle: true,
    minify: false,
    sourcemap: true,
    outdir: 'dist/main',
    plugins: [wpExternalsPlugin],
    target: 'es2020',
    format: 'iife',
    loader: {
        '.tsx': 'tsx',
        '.ts': 'ts',
        '.png': 'file',
        '.jpg': 'file',
        '.jpeg': 'file',
        '.gif': 'file',
        '.svg': 'file',
        '.webp': 'file',
        '.woff': 'file',
        '.woff2': 'file',
        '.ttf': 'file',
        '.eot': 'file',
    },
};

esbuild.context(buildConfig).then(ctx => {
    ctx.watch();
    console.log('Watching for changes...');
    console.log(`Watching ${scriptEntryPoints.length} TypeScript file(s)`);
    console.log(`Watching ${scssFiles.length} SCSS file(s)`);

    watchScss('assets/styles');
}).catch(() => process.exit(1));