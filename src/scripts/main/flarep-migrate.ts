import { __ } from '@wordpress/i18n';

declare const flarepMigrateConfig: {
    ajaxUrl: string;
    nonce: string;
    defaultVariant: string;
    variantOptions: Array<{ name: string; label: string }>;
    migrateUrl: string;
    logsUrl: string;
};

type Scope = 'all' | 'posts' | 'selected';

interface AnalysisImage {
    id: number;
    title: string;
    thumbnail: string;
    status: 'download_needed' | 'local_copy' | 'no_variant';
    parent_id: number;
    parent_title: string;
    parent_url: string;
}

interface AnalysisResult {
    total: number;
    download_needed: number;
    local_copy: number;
    no_variant: number;
    images: AnalysisImage[];
}

interface ListImagesResult {
    total: number;
    total_pages: number;
    page: number;
    images: AnalysisImage[];
}

interface MigrationState {
    remaining: number[];
    processed: number[];
    failed: Array<{ id: number; reason: string }>;
    options: { variant: string; delete_from_cf: boolean };
}

interface ProcessResult {
    status: 'downloaded' | 'local_copy' | 'skip' | 'error';
    reason?: string;
}

class FlarepMigrateWizard {
    private root: HTMLElement;
    private variant: string = flarepMigrateConfig.defaultVariant;
    private scope: Scope = 'all';
    private deleteFromCF: boolean = false;
    private selectedIds: number[] = [];
    private progressQueue: number[] = [];
    private progressDone: number = 0;
    private progressTotal: number = 0;
    private paused: boolean = false;
    private running: boolean = false;
    private counts = { processed: 0, failed: 0 };
    private failedIds: number[] = [];
    private currentPage: number = 1;
    private readonly pageSize: number = 20;

    constructor(root: HTMLElement) {
        this.root = root;
    }

    private render(html: string): void {
        this.root.innerHTML = html;
    }

    private ajax<T>(action: string, data: Record<string, unknown> = {}): Promise<T> {
        const form = new FormData();
        form.append('action', action);
        form.append('nonce', flarepMigrateConfig.nonce);
        for (const [k, v] of Object.entries(data)) {
            if (Array.isArray(v)) {
                (v as unknown[]).forEach(item => form.append(k + '[]', String(item)));
            } else {
                form.append(k, String(v ?? ''));
            }
        }
        return fetch(flarepMigrateConfig.ajaxUrl, { method: 'POST', body: form })
            .then(r => r.json())
            .then(r => {
                if (!r.success) throw new Error(r.data?.message || __('Request failed', 'flare-load'));
                return r.data as T;
            });
    }

    private on(selector: string, event: string, handler: EventListenerOrEventListenerObject): void {
        this.root.querySelector(selector)?.addEventListener(event, handler);
    }

    private escHtml(str: string): string {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async init(): Promise<void> {
        this.render(`<p class="flarep-migrate-loading">${__('Loading…', 'flare-load')}</p>`);
        try {
            const state = await this.ajax<MigrationState | null>('flareload_migrate_get_state');
            if (state && state.remaining.length > 0) {
                this.renderResumePrompt(state);
                return;
            }
        } catch {
            // fall through to config
        }
        this.renderConfig();
    }

    private renderResumePrompt(state: MigrationState): void {
        const total     = state.remaining.length + state.processed.length + state.failed.length;
        const done      = state.processed.length;
        const failed    = state.failed.length;
        const remaining = state.remaining.length;
        const pct       = total > 0 ? Math.round(((done + failed) / total) * 100) : 0;

        const failedCard = failed > 0
            ? `<div class="flarep-migrate-card flarep-card-red">
                   <span class="flarep-migrate-card-num">${failed}</span>
                   <span class="flarep-migrate-card-lbl">${__('Failed', 'flare-load')}</span>
               </div>`
            : '';

        this.render(`
            <div class="flarep-migrate-wizard">
                <div class="notice notice-warning inline flarep-migrate-resume-prompt">
                    <p><strong>${__('Paused or interrupted migration found', 'flare-load')}</strong></p>
                    <p>${__('A migration was previously started but did not complete. Each image is processed individually — only the remaining items below need to be migrated.', 'flare-load')}</p>
                    <div class="flarep-migrate-cards">
                        <div class="flarep-migrate-card flarep-card-green">
                            <span class="flarep-migrate-card-num">${done}</span>
                            <span class="flarep-migrate-card-lbl">${__('Completed', 'flare-load')}</span>
                        </div>
                        ${failedCard}
                        <div class="flarep-migrate-card flarep-card-blue">
                            <span class="flarep-migrate-card-num">${remaining}</span>
                            <span class="flarep-migrate-card-lbl">${__('Remaining', 'flare-load')}</span>
                        </div>
                        <div class="flarep-migrate-card">
                            <span class="flarep-migrate-card-num">${pct}%</span>
                            <span class="flarep-migrate-card-lbl">${__('Progress', 'flare-load')}</span>
                        </div>
                    </div>
                    <p class="submit">
                        <button id="flarep-resume-btn" class="button button-primary">${__('Continue Migration', 'flare-load')}</button>
                        &nbsp;
                        <button id="flarep-fresh-btn" class="button">${__('Start Fresh', 'flare-load')}</button>
                    </p>
                </div>
            </div>
        `);
        this.on('#flarep-resume-btn', 'click', () => {
            this.variant      = state.options.variant;
            this.deleteFromCF = !!state.options.delete_from_cf;
            this.counts.processed = state.processed.length;
            this.counts.failed    = state.failed.length;
            this.failedIds        = state.failed.map(f => f.id);
            this.startProgress(state.remaining, state.processed.length + state.failed.length, total);
        });
        this.on('#flarep-fresh-btn', 'click', async () => {
            await this.ajax('flareload_migrate_cancel').catch(() => {});
            this.renderConfig();
        });
    }

    private renderConfig(): void {
        const variants   = flarepMigrateConfig.variantOptions;
        const noVariants = variants.length === 0;
        const variantOpts = noVariants
            ? `<option value="">${__('No variants configured', 'flare-load')}</option>`
            : variants.map(v =>
                `<option value="${this.escHtml(v.name)}"${v.name === this.variant ? ' selected' : ''}>${this.escHtml(v.label)}</option>`
              ).join('');

        this.render(`
            <div class="flarep-migrate-wizard">
                <h2 class="flarep-migrate-step-title">${__('Migrate to Local', 'flare-load')}</h2>
                <p>${__('This wizard downloads your Cloudflare Images back to your server and restores them as standard WordPress attachments.', 'flare-load')}</p>
                <div class="notice notice-warning inline">
                    <p><strong>${__('Before you start:', 'flare-load')}</strong> ${__('Close all post/page editor tabs in every browser window. If an editor containing a Cloudflare image is left open and the post is saved after migration completes, the Cloudflare image reference will be written back into that post.', 'flare-load')}</p>
                </div>
                ${noVariants ? `<div class="notice notice-warning inline"><p>${__('No variants found. Please sync your variants in FlarePress settings first.', 'flare-load')}</p></div>` : ''}
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="flarep-variant-select">${__('Download Variant', 'flare-load')}</label></th>
                        <td>
                            <select id="flarep-variant-select" class="regular-text"${noVariants ? ' disabled' : ''}>${variantOpts}</select>
                            <p class="description">${__('The Cloudflare Images variant to download as the main image.', 'flare-load')}</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">${__('Images to Migrate', 'flare-load')}</th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="flarep-scope" value="all"${this.scope === 'all' ? ' checked' : ''}> ${__('All Cloudflare images', 'flare-load')}</label><br>
                                <label><input type="radio" name="flarep-scope" value="posts"${this.scope === 'posts' ? ' checked' : ''}> ${__('Images attached to posts/pages', 'flare-load')}</label><br>
                                <label><input type="radio" name="flarep-scope" value="selected"${this.scope === 'selected' ? ' checked' : ''}> ${__('Select images manually', 'flare-load')}</label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">${__('After Migration', 'flare-load')}</th>
                        <td>
                            <label>
                                <input type="checkbox" id="flarep-delete-cf"${this.deleteFromCF ? ' checked' : ''}>
                                ${__('Delete images from Cloudflare after successful migration', 'flare-load')}
                            </label>
                            <p class="description flarep-migrate-danger-note">${__('Warning: This is irreversible. Deleted Cloudflare images cannot be recovered.', 'flare-load')}</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button id="flarep-config-next" class="button button-primary"${noVariants ? ' disabled' : ''}>${__('Continue', 'flare-load')}</button>
                </p>
            </div>
        `);

        this.on('#flarep-config-next', 'click', async () => {
            const variantEl = this.root.querySelector<HTMLSelectElement>('#flarep-variant-select');
            const scopeEl   = this.root.querySelector<HTMLInputElement>('input[name="flarep-scope"]:checked');
            const deleteEl  = this.root.querySelector<HTMLInputElement>('#flarep-delete-cf');
            this.variant      = variantEl?.value ?? '';
            this.scope        = (scopeEl?.value ?? 'all') as Scope;
            this.deleteFromCF = deleteEl?.checked ?? false;

            let locked: Array<{ id: number; title: string }> = [];
            try {
                locked = await this.ajax<Array<{ id: number; title: string }>>('flareload_migrate_check_locks');
            } catch { /* proceed if check fails */ }

            if (locked.length > 0) {
                this.renderLockedWarning(locked);
                return;
            }

            if (this.scope === 'selected') {
                this.renderSelectImages();
            } else {
                this.renderAnalysis();
            }
        });
    }

    private renderLockedWarning(locked: Array<{ id: number; title: string }>): void {
        const list = locked.map(p => `<li>${this.escHtml(p.title)}</li>`).join('');
        this.render(`
            <div class="flarep-migrate-wizard">
                <div class="notice notice-warning inline">
                    <p><strong>${__('Open editor sessions detected', 'flare-load')}</strong></p>
                    <p>${__('The following posts are currently open in an editor. If they are saved after migration completes, Cloudflare image references may be written back.', 'flare-load')}</p>
                    <ul style="list-style:disc;margin-left:20px">${list}</ul>
                    <p>${__('Please close these editor tabs, then click Re-check.', 'flare-load')}</p>
                    <p class="submit">
                        <button id="flarep-lock-back" class="button">${__('Go Back', 'flare-load')}</button>
                        &nbsp;
                        <button id="flarep-lock-recheck" class="button button-primary">${__('Re-check', 'flare-load')}</button>
                        &nbsp;
                        <button id="flarep-lock-continue" class="button button-link">${__('Continue Anyway', 'flare-load')}</button>
                    </p>
                </div>
            </div>
        `);
        this.on('#flarep-lock-back', 'click', () => this.renderConfig());
        this.on('#flarep-lock-recheck', 'click', async () => {
            let locked: Array<{ id: number; title: string }> = [];
            try {
                locked = await this.ajax<Array<{ id: number; title: string }>>('flareload_migrate_check_locks');
            } catch { /* proceed if check fails */ }

            if (locked.length > 0) {
                this.renderLockedWarning(locked);
            } else {
                if (this.scope === 'selected') {
                    this.renderSelectImages();
                } else {
                    this.renderAnalysis();
                }
            }
        });
        this.on('#flarep-lock-continue', 'click', () => {
            if (this.scope === 'selected') {
                this.renderSelectImages();
            } else {
                this.renderAnalysis();
            }
        });
    }

    private async renderSelectImages(page: number = 1): Promise<void> {
        this.renderSelectShell(page, null);
        let result: ListImagesResult;
        try {
            result = await this.ajax<ListImagesResult>('flareload_migrate_list', {
                scope:    'all',
                page,
                per_page: this.pageSize,
            });
        } catch {
            this.render(`<div class="flarep-migrate-wizard"><div class="notice notice-error inline"><p>${__('Failed to load images. Please try again.', 'flare-load')}</p></div></div>`);
            return;
        }
        this.currentPage = result.page;
        this.renderSelectShell(result.page, result);
    }

    private renderSelectShell(page: number, result: ListImagesResult | null): void {
        const total      = result?.total      ?? 0;
        const totalPages = result?.total_pages ?? 1;
        const images     = result?.images      ?? [];

        const rows = result === null
            ? `<tr><td colspan="5"><span class="flarep-migrate-loading">${__('Loading…', 'flare-load')}</span></td></tr>`
            : images.length === 0
                ? `<tr><td colspan="5">${__('No Cloudflare images found.', 'flare-load')}</td></tr>`
                : images.map(img => {
                    const parentCell = img.parent_title
                        ? `<a href="${this.escHtml(img.parent_url)}" target="_blank" rel="noopener">${this.escHtml(this.truncate(img.parent_title, 40))}</a>`
                        : `<span class="flarep-muted">—</span>`;
                    return `
                        <tr>
                            <td class="flarep-migrate-select-check"><input type="checkbox" class="flarep-img-check" value="${img.id}"${this.selectedIds.includes(img.id) ? ' checked' : ''}></td>
                            <td class="flarep-migrate-thumb">${img.thumbnail ? `<img src="${this.escHtml(img.thumbnail)}" alt="">` : ''}</td>
                            <td>${this.escHtml(img.title)}</td>
                            <td>${parentCell}</td>
                            <td><span class="flarep-migrate-badge flarep-badge-${img.status}">${this.statusLabel(img.status)}</span></td>
                        </tr>`;
                }).join('');

        const pagination = totalPages > 1 ? `
            <div class="flarep-migrate-pagination">
                <button class="button flarep-page-btn" data-page="${page - 1}"${page <= 1 ? ' disabled' : ''}>&laquo; ${__('Previous', 'flare-load')}</button>
                <span class="flarep-page-info">${__('Page', 'flare-load')} ${page} / ${totalPages} &nbsp;(${total} ${__('total', 'flare-load')})</span>
                <button class="button flarep-page-btn" data-page="${page + 1}"${page >= totalPages ? ' disabled' : ''}>${__('Next', 'flare-load')} &raquo;</button>
            </div>
        ` : '';

        this.render(`
            <div class="flarep-migrate-wizard">
                <h2 class="flarep-migrate-step-title">${__('Select Images', 'flare-load')}</h2>
                <p>${__('Choose which images to migrate. Images with no variant configured will be skipped.', 'flare-load')}</p>
                <label class="flarep-migrate-select-all-label"><input type="checkbox" id="flarep-select-all"> ${__('Select all on this page', 'flare-load')}</label>
                <table class="widefat flarep-migrate-image-table">
                    <thead>
                        <tr>
                            <th style="width:32px"></th>
                            <th style="width:60px">${__('Thumbnail', 'flare-load')}</th>
                            <th>${__('Title', 'flare-load')}</th>
                            <th>${__('Attached to', 'flare-load')}</th>
                            <th style="width:140px">${__('Status', 'flare-load')}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
                ${pagination}
                <p class="flarep-migrate-selected-count">${__('Selected', 'flare-load')}: <strong id="flarep-selected-count">${this.selectedIds.length}</strong></p>
                <p class="submit">
                    <button id="flarep-select-back" class="button">${__('Back', 'flare-load')}</button>
                    <button id="flarep-select-next" class="button button-primary">${__('Continue', 'flare-load')}</button>
                </p>
            </div>
        `);

        this.on('#flarep-select-all', 'change', e => {
            const all = (e.target as HTMLInputElement).checked;
            this.root.querySelectorAll<HTMLInputElement>('.flarep-img-check').forEach(cb => { cb.checked = all; });
            this.syncPageSelections();
        });

        this.root.querySelectorAll<HTMLInputElement>('.flarep-img-check').forEach(cb => {
            cb.addEventListener('change', () => this.syncPageSelections());
        });

        this.root.querySelectorAll<HTMLButtonElement>('.flarep-page-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.syncPageSelections();
                this.renderSelectImages(parseInt(btn.dataset.page ?? '1', 10));
            });
        });

        this.on('#flarep-select-back', 'click', () => {
            this.syncPageSelections();
            this.renderConfig();
        });
        this.on('#flarep-select-next', 'click', () => {
            this.syncPageSelections();
            this.renderAnalysis();
        });
    }

    private syncPageSelections(): void {
        this.root.querySelectorAll<HTMLInputElement>('.flarep-img-check').forEach(cb => {
            const id = parseInt(cb.value, 10);
            if (cb.checked) {
                if (!this.selectedIds.includes(id)) this.selectedIds.push(id);
            } else {
                this.selectedIds = this.selectedIds.filter(x => x !== id);
            }
        });
        const countEl = this.root.querySelector('#flarep-selected-count');
        if (countEl) countEl.textContent = String(this.selectedIds.length);
    }

    private truncate(str: string, max: number): string {
        return str.length > max ? str.slice(0, max) + '…' : str;
    }

    private async renderAnalysis(): Promise<void> {
        this.render(`<div class="flarep-migrate-wizard"><p class="flarep-migrate-loading">${__('Analyzing…', 'flare-load')}</p></div>`);
        let result: AnalysisResult;
        try {
            result = await this.ajax<AnalysisResult>('flareload_migrate_analyze', {
                scope: this.scope,
                ids:   this.selectedIds,
            });
        } catch {
            this.render(`<div class="flarep-migrate-wizard"><div class="notice notice-error inline"><p>${__('Analysis failed. Please try again.', 'flare-load')}</p></div></div>`);
            return;
        }

        const migratable = result.download_needed + result.local_copy;

        this.render(`
            <div class="flarep-migrate-wizard">
                <h2 class="flarep-migrate-step-title">${__('Analysis Results', 'flare-load')}</h2>
                <div class="flarep-migrate-cards">
                    <div class="flarep-migrate-card">
                        <span class="flarep-migrate-card-num">${result.total}</span>
                        <span class="flarep-migrate-card-lbl">${__('Total', 'flare-load')}</span>
                    </div>
                    <div class="flarep-migrate-card flarep-card-green">
                        <span class="flarep-migrate-card-num">${result.download_needed}</span>
                        <span class="flarep-migrate-card-lbl">${__('Ready to migrate', 'flare-load')}</span>
                    </div>
                    <div class="flarep-migrate-card flarep-card-blue">
                        <span class="flarep-migrate-card-num">${result.local_copy}</span>
                        <span class="flarep-migrate-card-lbl">${__('Already have local copy', 'flare-load')}</span>
                    </div>
                    <div class="flarep-migrate-card flarep-card-gray">
                        <span class="flarep-migrate-card-num">${result.no_variant}</span>
                        <span class="flarep-migrate-card-lbl">${__('No variant — will skip', 'flare-load')}</span>
                    </div>
                </div>
                ${migratable === 0 ? `<div class="notice notice-warning inline"><p>${__('Nothing to migrate.', 'flare-load')}</p></div>` : ''}
                <p class="submit">
                    <button id="flarep-analysis-back" class="button">${__('Back', 'flare-load')}</button>
                    ${migratable > 0 ? `<button id="flarep-start-btn" class="button button-primary">${__('Start Migration', 'flare-load')}</button>` : ''}
                </p>
            </div>
        `);

        this.on('#flarep-analysis-back', 'click', () => {
            if (this.scope === 'selected') {
                this.renderSelectImages();
            } else {
                this.renderConfig();
            }
        });

        this.on('#flarep-start-btn', 'click', async () => {
            const btn = this.root.querySelector<HTMLButtonElement>('#flarep-start-btn');
            if (btn) btn.disabled = true;
            try {
                const state = await this.ajax<MigrationState>('flareload_migrate_start', {
                    scope:          this.scope,
                    ids:            this.selectedIds,
                    variant:        this.variant,
                    delete_from_cf: this.deleteFromCF ? '1' : '0',
                });
                this.counts    = { processed: 0, failed: 0 };
                this.failedIds = [];
                this.startProgress(state.remaining, 0, state.remaining.length);
            } catch {
                if (btn) btn.disabled = false;
            }
        });
    }

    private startProgress(remaining: number[], doneCount: number, total: number): void {
        this.progressQueue = [...remaining];
        this.progressDone  = doneCount;
        this.progressTotal = total;
        this.paused        = false;
        this.renderProgress();
        this.runMigrationLoop();
    }

    private renderProgress(): void {
        const pct = this.progressTotal > 0
            ? Math.round((this.progressDone / this.progressTotal) * 100)
            : 0;

        this.render(`
            <div class="flarep-migrate-wizard">
                <h2 class="flarep-migrate-step-title">${__('Migrating…', 'flare-load')}</h2>
                <div class="flarep-migrate-progress-wrap">
                    <div class="flarep-migrate-progress-bar">
                        <div class="flarep-migrate-progress-fill" style="width:${pct}%"></div>
                    </div>
                    <p class="flarep-migrate-progress-label">${this.progressDone} / ${this.progressTotal} &mdash; ${pct}%</p>
                    <p id="flarep-progress-current" class="flarep-migrate-current-item">&nbsp;</p>
                </div>
                <p class="submit">
                    <button id="flarep-pause-btn" class="button">${__('Pause', 'flare-load')}</button>
                </p>
                <div id="flarep-progress-log" class="flarep-migrate-log"></div>
            </div>
        `);

        this.on('#flarep-pause-btn', 'click', () => {
            this.paused = !this.paused;
            const btn = this.root.querySelector<HTMLButtonElement>('#flarep-pause-btn');
            if (btn) btn.textContent = this.paused
                ? __('Resume', 'flare-load')
                : __('Pause', 'flare-load');
            if (!this.paused) this.runMigrationLoop();
        });
    }

    private updateProgressUI(): void {
        const pct   = this.progressTotal > 0
            ? Math.round((this.progressDone / this.progressTotal) * 100)
            : 0;
        const fill  = this.root.querySelector<HTMLElement>('.flarep-migrate-progress-fill');
        const label = this.root.querySelector('.flarep-migrate-progress-label');
        if (fill)  fill.style.width = `${pct}%`;
        if (label) label.innerHTML  = `${this.progressDone} / ${this.progressTotal} &mdash; ${pct}%`;
    }

    private appendLog(message: string, type: 'success' | 'error'): void {
        const log = this.root.querySelector('#flarep-progress-log');
        if (!log) return;
        const p = document.createElement('p');
        p.className   = `flarep-log-line flarep-log-${type}`;
        p.textContent = message;
        log.prepend(p);
        while (log.children.length > 50) log.lastChild?.remove();
    }

    private async runMigrationLoop(): Promise<void> {
        if (this.running) return;
        this.running = true;

        try {
            while (this.progressQueue.length > 0 && !this.paused) {
                const id = this.progressQueue[0];

                const currentEl = this.root.querySelector('#flarep-progress-current');
                if (currentEl) currentEl.textContent = `${__('Processing', 'flare-load')} #${id}…`;

                try {
                    const result = await this.ajax<ProcessResult>('flareload_migrate_process', {
                        id,
                        variant:        this.variant,
                        delete_from_cf: this.deleteFromCF ? '1' : '0',
                    });
                    this.progressQueue.shift();
                    this.progressDone++;
                    if (result.status === 'error' || result.status === 'skip') {
                        this.counts.failed++;
                        this.failedIds.push(id);
                        this.appendLog(`#${id}: ${result.reason ?? __('error', 'flare-load')}`, 'error');
                    } else {
                        this.counts.processed++;
                        this.appendLog(`#${id}`, 'success');
                    }
                } catch (e) {
                    this.progressQueue.shift();
                    this.progressDone++;
                    this.counts.failed++;
                    this.failedIds.push(id);
                    this.appendLog(`#${id}: ${(e as Error).message}`, 'error');
                }

                this.updateProgressUI();
            }
        } finally {
            this.running = false;
        }

        if (!this.paused && this.progressQueue.length === 0) {
            await this.ajax('flareload_migrate_cancel').catch(() => {});
            this.renderSummary();
        }
    }

    private renderSummary(): void {
        const hasFailures = this.failedIds.length > 0;
        const retryBtn = hasFailures
            ? `<button id="flarep-retry-btn" class="button button-primary">
                   ${__('Retry Failed', 'flare-load')} (${this.failedIds.length})
               </button>&nbsp;`
            : '';

        this.render(`
            <div class="flarep-migrate-wizard">
                <h2 class="flarep-migrate-step-title">${__('Migration Complete', 'flare-load')}</h2>
                <div class="flarep-migrate-cards">
                    <div class="flarep-migrate-card flarep-card-green">
                        <span class="flarep-migrate-card-num">${this.counts.processed}</span>
                        <span class="flarep-migrate-card-lbl">${__('Migrated successfully', 'flare-load')}</span>
                    </div>
                    <div class="flarep-migrate-card${hasFailures ? ' flarep-card-red' : ''}">
                        <span class="flarep-migrate-card-num">${this.counts.failed}</span>
                        <span class="flarep-migrate-card-lbl">${__('Failed', 'flare-load')}</span>
                    </div>
                </div>
                <p class="submit">
                    ${retryBtn}
                    <a href="${flarepMigrateConfig.logsUrl}" class="button">${__('View Log', 'flare-load')}</a>
                    &nbsp;
                    <a href="${flarepMigrateConfig.migrateUrl}" class="button">${__('Run Again', 'flare-load')}</a>
                </p>
            </div>
        `);

        if (hasFailures) {
            this.on('#flarep-retry-btn', 'click', () => {
                this.scope       = 'selected';
                this.selectedIds = [...this.failedIds];
                this.failedIds   = [];
                this.counts      = { processed: 0, failed: 0 };
                this.renderAnalysis();
            });
        }
    }

    private statusLabel(status: AnalysisImage['status']): string {
        switch (status) {
            case 'download_needed': return __('Ready', 'flare-load');
            case 'local_copy':      return __('Has local copy', 'flare-load');
            case 'no_variant':      return __('No variant', 'flare-load');
        }
    }
}

const root = document.getElementById('flarep-migrate-root');
if (root) {
    new FlarepMigrateWizard(root).init();
}
