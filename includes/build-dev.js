const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');

// Plugin to map WordPress externals to wp global
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

// Get all .ts files from assets/scripts
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

const entryPoints = getEntryPoints('assets/scripts/main');

const buildConfig = {
    entryPoints: entryPoints,
    bundle: true,
    minify: false,
    sourcemap: true,
    outdir: 'dist',
    outbase: 'assets/scripts',
    plugins: [wpExternalsPlugin],
    target: 'es2020',
    format: 'iife',
    loader: { '.tsx': 'tsx', '.ts': 'ts' },
};

// Watch mode
esbuild.context(buildConfig).then(ctx => {
    ctx.watch();
    console.log('Watching for changes...');
});