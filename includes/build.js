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

const scssPlugin = {
    name: 'scss',
    setup(build) {
        build.onLoad({ filter: /\.scss$/ }, async (args) => {
            try {
                const result = sass.compile(args.path, {
                    sourceMap: false,
                    style: 'compressed'
                });

                return {
                    contents: result.css,
                    loader: 'css',
                };
            } catch (error) {
                return {
                    errors: [{
                        text: error.message,
                        location: error.span ? {
                            file: args.path,
                            line: error.span.start.line,
                            column: error.span.start.column,
                        } : null,
                    }],
                };
            }
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

function getScssEntryPoints(dir) {
    if (!fs.existsSync(dir)) {
        console.log(`Directory ${dir} does not exist, skipping SCSS compilation`);
        return [];
    }

    const files = fs.readdirSync(dir);
    const entryPoints = [];

    files.forEach(file => {
        const fullPath = path.join(dir, file);
        const stat = fs.statSync(fullPath);

        if (stat.isDirectory()) {
            entryPoints.push(...getScssEntryPoints(fullPath));
        } else if (file.endsWith('.scss') && !file.startsWith('_')) {
            entryPoints.push(fullPath);
        }
    });

    return entryPoints;
}

const scriptEntryPoints = getEntryPoints('assets/scripts/main');
const scssEntryPoints = getScssEntryPoints('assets/styles');

const buildConfig = {
    entryPoints: [...scriptEntryPoints, ...scssEntryPoints],
    bundle: true,
    minify: true,
    sourcemap: false,
    outdir: 'dist',
    outbase: 'assets',
    plugins: [wpExternalsPlugin, scssPlugin],
    target: 'es2020',
    format: 'iife',
    loader: {
        '.tsx': 'tsx',
        '.ts': 'ts',
        '.scss': 'css',
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

esbuild.build(buildConfig).catch(() => process.exit(1));