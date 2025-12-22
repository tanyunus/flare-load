const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');

// Get all .ts files from assets/scripts
function getEntryPoints(dir) {
    const files = fs.readdirSync(dir);
    const entryPoints = [];

    files.forEach(file => {
        const fullPath = path.join(dir, file);
        const stat = fs.statSync(fullPath);

        if (stat.isDirectory()) {
            entryPoints.push(...getEntryPoints(fullPath));
        } else if (file.endsWith('.ts')) {
            entryPoints.push(fullPath);
        }
    });

    return entryPoints;
}

const entryPoints = getEntryPoints('assets/scripts/main');

const buildConfig = {
    entryPoints: entryPoints,
    bundle: true,
    minify: false,
    sourcemap: true,
    outdir: 'dist',
    outbase: 'assets/scripts',
    target: 'es2020',
    format: 'iife',
};

// Watch mode
esbuild.context(buildConfig).then(ctx => {
    ctx.watch();
    console.log('Watching for changes...');
});