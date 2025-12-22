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
            // Recursively get files from subdirectories
            entryPoints.push(...getEntryPoints(fullPath));
        } else if (file.endsWith('.ts')) {
            entryPoints.push(fullPath);
        }
    });

    return entryPoints;
}

const entryPoints = getEntryPoints('assets/scripts/main');

// Build configuration
const buildConfig = {
    entryPoints: entryPoints,
    bundle: true,
    minify: true,
    sourcemap: false,
    outdir: 'dist',
    outbase: 'assets/scripts',
    target: 'es2020',
    format: 'iife',
};

// Production build
esbuild.build(buildConfig).catch(() => process.exit(1));