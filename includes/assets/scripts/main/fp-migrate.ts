import { __ } from '@wordpress/i18n';

declare const fpMigrateConfig: {
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

class FpMigrateWizard {
    private root: HTMLElement;
    private variant: string = fpMigrateConfig.defaultVariant;
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
        form.append('nonce', fpMigrateConfig.nonce);
        for (const [k, v] of Object.entries(data)) {
            if (Array.isArray(v)) {
                (v as unknown[]).forEach(item => form.append(k + '[]', String(item)));
            } else {
                form.append(k, String(v ?? ''));
            }
        }
        return fetch(fpMigrateConfig.ajaxUrl, { method: 'POST', body: form })
            .then(r => r.json())
            .then(r => {
                if (!r.success) throw new Error(r.data?.message || __('Request failed', 'flare-press'));
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
        this.render(`<p class="fp-migrate-loading">${__('Loading…', 'flare-press')}</p>`);
        try {
            const state = await this.ajax<MigrationState | null>('fp_migrate_get_state');
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
            ? `<div class="fp-migrate-card fp-card-red">
                   <span class="fp-migrate-card-num">${failed}</span>
                   <span class="fp-migrate-card-lbl">${__('Failed', 'flare-press')}</span>
               </div>`
            : '';

        this.render(`
            <div class="fp-migrate-wizard">
                <div class="notice notice-warning inline fp-migrate-resume-prompt">
                    <p><strong>${__('Paused or interrupted migration found', 'flare-press')}</strong></p>
                    <p>${__('A migration was previously started but did not complete. Each image is processed individually — only the remaining items below need to be migrated.', 'flare-press')}</p>
                    <div class="fp-migrate-cards">
                        <div class="fp-migrate-card fp-card-green">
                            <span class="fp-migrate-card-num">${done}</span>
                            <span class="fp-migrate-card-lbl">${__('Completed', 'flare-press')}</span>
                        </div>
                        ${failedCard}
                        <div class="fp-migrate-card fp-card-blue">
                            <span class="fp-migrate-card-num">${remaining}</span>
                            <span class="fp-migrate-card-lbl">${__('Remaining', 'flare-press')}</span>
                        </div>
                        <div class="fp-migrate-card">
                            <span class="fp-migrate-card-num">${pct}%</span>
                            <span class="fp-migrate-card-lbl">${__('Progress', 'flare-press')}</span>
                        </div>
                    </div>
                    <p class="submit">
                        <button id="fp-resume-btn" class="button button-primary">${__('Continue Migration', 'flare-press')}</button>
                        &nbsp;
                        <button id="fp-fresh-btn" class="button">${__('Start Fresh', 'flare-press')}</button>
                    </p>
                </div>
            </div>
        `);
        this.on('#fp-resume-btn', 'click', () => {
            this.variant      = state.options.variant;
            this.deleteFromCF = !!state.options.delete_from_cf;
            this.counts.processed = state.processed.length;
            this.counts.failed    = state.failed.length;
            this.failedIds        = state.failed.map(f => f.id);
            this.startProgress(state.remaining, state.processed.length + state.failed.length, total);
        });
        this.on('#fp-fresh-btn', 'click', async () => {
            await this.ajax('fp_migrate_cancel').catch(() => {});
            this.renderConfig();
        });
    }

    private renderConfig(): void {
        const variants   = fpMigrateConfig.variantOptions;
        const noVariants = variants.length === 0;
        const variantOpts = noVariants
            ? `<option value="">${__('No variants configured', 'flare-press')}</option>`
            : variants.map(v =>
                `<option value="${this.escHtml(v.name)}"${v.name === this.variant ? ' selected' : ''}>${this.escHtml(v.label)}</option>`
              ).join('');

        this.render(`
            <div class="fp-migrate-wizard">
                <h2 class="fp-migrate-step-title">${__('Migrate to Local', 'flare-press')}</h2>
                <p>${__('This wizard downloads your Cloudflare Images back to your server and restores them as standard WordPress attachments.', 'flare-press')}</p>
                ${noVariants ? `<div class="notice notice-warning inline"><p>${__('No variants found. Please sync your variants in FlarePress settings first.', 'flare-press')}</p></div>` : ''}
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="fp-variant-select">${__('Download Variant', 'flare-press')}</label></th>
                        <td>
                            <select id="fp-variant-select" class="regular-text"${noVariants ? ' disabled' : ''}>${variantOpts}</select>
                            <p class="description">${__('The Cloudflare Images variant to download as the main image.', 'flare-press')}</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">${__('Images to Migrate', 'flare-press')}</th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="fp-scope" value="all"${this.scope === 'all' ? ' checked' : ''}> ${__('All Cloudflare images', 'flare-press')}</label><br>
                                <label><input type="radio" name="fp-scope" value="posts"${this.scope === 'posts' ? ' checked' : ''}> ${__('Images attached to posts/pages', 'flare-press')}</label><br>
                                <label><input type="radio" name="fp-scope" value="selected"${this.scope === 'selected' ? ' checked' : ''}> ${__('Select images manually', 'flare-press')}</label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">${__('After Migration', 'flare-press')}</th>
                        <td>
                            <label>
                                <input type="checkbox" id="fp-delete-cf"${this.deleteFromCF ? ' checked' : ''}>
                                ${__('Delete images from Cloudflare after successful migration', 'flare-press')}
                            </label>
                            <p class="description fp-migrate-danger-note">${__('Warning: This is irreversible. Deleted Cloudflare images cannot be recovered.', 'flare-press')}</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button id="fp-config-next" class="button button-primary"${noVariants ? ' disabled' : ''}>${__('Continue', 'flare-press')}</button>
                </p>
            </div>
        `);

        this.on('#fp-config-next', 'click', () => {
            const variantEl = this.root.querySelector<HTMLSelectElement>('#fp-variant-select');
            const scopeEl   = this.root.querySelector<HTMLInputElement>('input[name="fp-scope"]:checked');
            const deleteEl  = this.root.querySelector<HTMLInputElement>('#fp-delete-cf');
            this.variant      = variantEl?.value ?? '';
            this.scope        = (scopeEl?.value ?? 'all') as Scope;
            this.deleteFromCF = deleteEl?.checked ?? false;
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
            result = await this.ajax<ListImagesResult>('fp_migrate_list', {
                scope:    'all',
                page,
                per_page: this.pageSize,
            });
        } catch {
            this.render(`<div class="fp-migrate-wizard"><div class="notice notice-error inline"><p>${__('Failed to load images. Please try again.', 'flare-press')}</p></div></div>`);
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
            ? `<tr><td colspan="5"><span class="fp-migrate-loading">${__('Loading…', 'flare-press')}</span></td></tr>`
            : images.length === 0
                ? `<tr><td colspan="5">${__('No Cloudflare images found.', 'flare-press')}</td></tr>`
                : images.map(img => {
                    const parentCell = img.parent_title
                        ? `<a href="${this.escHtml(img.parent_url)}" target="_blank" rel="noopener">${this.escHtml(this.truncate(img.parent_title, 40))}</a>`
                        : `<span class="fp-muted">—</span>`;
                    return `
                        <tr>
                            <td class="fp-migrate-select-check"><input type="checkbox" class="fp-img-check" value="${img.id}"${this.selectedIds.includes(img.id) ? ' checked' : ''}></td>
                            <td class="fp-migrate-thumb">${img.thumbnail ? `<img src="${this.escHtml(img.thumbnail)}" alt="">` : ''}</td>
                            <td>${this.escHtml(img.title)}</td>
                            <td>${parentCell}</td>
                            <td><span class="fp-migrate-badge fp-badge-${img.status}">${this.statusLabel(img.status)}</span></td>
                        </tr>`;
                }).join('');

        const pagination = totalPages > 1 ? `
            <div class="fp-migrate-pagination">
                <button class="button fp-page-btn" data-page="${page - 1}"${page <= 1 ? ' disabled' : ''}>&laquo; ${__('Previous', 'flare-press')}</button>
                <span class="fp-page-info">${__('Page', 'flare-press')} ${page} / ${totalPages} &nbsp;(${total} ${__('total', 'flare-press')})</span>
                <button class="button fp-page-btn" data-page="${page + 1}"${page >= totalPages ? ' disabled' : ''}>${__('Next', 'flare-press')} &raquo;</button>
            </div>
        ` : '';

        this.render(`
            <div class="fp-migrate-wizard">
                <h2 class="fp-migrate-step-title">${__('Select Images', 'flare-press')}</h2>
                <p>${__('Choose which images to migrate. Images with no variant configured will be skipped.', 'flare-press')}</p>
                <label class="fp-migrate-select-all-label"><input type="checkbox" id="fp-select-all"> ${__('Select all on this page', 'flare-press')}</label>
                <table class="widefat fp-migrate-image-table">
                    <thead>
                        <tr>
                            <th style="width:32px"></th>
                            <th style="width:60px">${__('Thumbnail', 'flare-press')}</th>
                            <th>${__('Title', 'flare-press')}</th>
                            <th>${__('Attached to', 'flare-press')}</th>
                            <th style="width:140px">${__('Status', 'flare-press')}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
                ${pagination}
                <p class="fp-migrate-selected-count">${__('Selected', 'flare-press')}: <strong id="fp-selected-count">${this.selectedIds.length}</strong></p>
                <p class="submit">
                    <button id="fp-select-back" class="button">${__('Back', 'flare-press')}</button>
                    <button id="fp-select-next" class="button button-primary">${__('Continue', 'flare-press')}</button>
                </p>
            </div>
        `);

        this.on('#fp-select-all', 'change', e => {
            const all = (e.target as HTMLInputElement).checked;
            this.root.querySelectorAll<HTMLInputElement>('.fp-img-check').forEach(cb => { cb.checked = all; });
            this.syncPageSelections();
        });

        this.root.querySelectorAll<HTMLInputElement>('.fp-img-check').forEach(cb => {
            cb.addEventListener('change', () => this.syncPageSelections());
        });

        this.root.querySelectorAll<HTMLButtonElement>('.fp-page-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.syncPageSelections();
                this.renderSelectImages(parseInt(btn.dataset.page ?? '1', 10));
            });
        });

        this.on('#fp-select-back', 'click', () => {
            this.syncPageSelections();
            this.renderConfig();
        });
        this.on('#fp-select-next', 'click', () => {
            this.syncPageSelections();
            this.renderAnalysis();
        });
    }

    private syncPageSelections(): void {
        this.root.querySelectorAll<HTMLInputElement>('.fp-img-check').forEach(cb => {
            const id = parseInt(cb.value, 10);
            if (cb.checked) {
                if (!this.selectedIds.includes(id)) this.selectedIds.push(id);
            } else {
                this.selectedIds = this.selectedIds.filter(x => x !== id);
            }
        });
        const countEl = this.root.querySelector('#fp-selected-count');
        if (countEl) countEl.textContent = String(this.selectedIds.length);
    }

    private truncate(str: string, max: number): string {
        return str.length > max ? str.slice(0, max) + '…' : str;
    }

    private async renderAnalysis(): Promise<void> {
        this.render(`<div class="fp-migrate-wizard"><p class="fp-migrate-loading">${__('Analyzing…', 'flare-press')}</p></div>`);
        let result: AnalysisResult;
        try {
            result = await this.ajax<AnalysisResult>('fp_migrate_analyze', {
                scope: this.scope,
                ids:   this.selectedIds,
            });
        } catch {
            this.render(`<div class="fp-migrate-wizard"><div class="notice notice-error inline"><p>${__('Analysis failed. Please try again.', 'flare-press')}</p></div></div>`);
            return;
        }

        const migratable = result.download_needed + result.local_copy;

        this.render(`
            <div class="fp-migrate-wizard">
                <h2 class="fp-migrate-step-title">${__('Analysis Results', 'flare-press')}</h2>
                <div class="fp-migrate-cards">
                    <div class="fp-migrate-card">
                        <span class="fp-migrate-card-num">${result.total}</span>
                        <span class="fp-migrate-card-lbl">${__('Total', 'flare-press')}</span>
                    </div>
                    <div class="fp-migrate-card fp-card-green">
                        <span class="fp-migrate-card-num">${result.download_needed}</span>
                        <span class="fp-migrate-card-lbl">${__('Ready to migrate', 'flare-press')}</span>
                    </div>
                    <div class="fp-migrate-card fp-card-blue">
                        <span class="fp-migrate-card-num">${result.local_copy}</span>
                        <span class="fp-migrate-card-lbl">${__('Already have local copy', 'flare-press')}</span>
                    </div>
                    <div class="fp-migrate-card fp-card-gray">
                        <span class="fp-migrate-card-num">${result.no_variant}</span>
                        <span class="fp-migrate-card-lbl">${__('No variant — will skip', 'flare-press')}</span>
                    </div>
                </div>
                ${migratable === 0 ? `<div class="notice notice-warning inline"><p>${__('Nothing to migrate.', 'flare-press')}</p></div>` : ''}
                <p class="submit">
                    <button id="fp-analysis-back" class="button">${__('Back', 'flare-press')}</button>
                    ${migratable > 0 ? `<button id="fp-start-btn" class="button button-primary">${__('Start Migration', 'flare-press')}</button>` : ''}
                </p>
            </div>
        `);

        this.on('#fp-analysis-back', 'click', () => {
            if (this.scope === 'selected') {
                this.renderSelectImages();
            } else {
                this.renderConfig();
            }
        });

        this.on('#fp-start-btn', 'click', async () => {
            const btn = this.root.querySelector<HTMLButtonElement>('#fp-start-btn');
            if (btn) btn.disabled = true;
            try {
                const state = await this.ajax<MigrationState>('fp_migrate_start', {
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
            <div class="fp-migrate-wizard">
                <h2 class="fp-migrate-step-title">${__('Migrating…', 'flare-press')}</h2>
                <div class="fp-migrate-progress-wrap">
                    <div class="fp-migrate-progress-bar">
                        <div class="fp-migrate-progress-fill" style="width:${pct}%"></div>
                    </div>
                    <p class="fp-migrate-progress-label">${this.progressDone} / ${this.progressTotal} &mdash; ${pct}%</p>
                    <p id="fp-progress-current" class="fp-migrate-current-item">&nbsp;</p>
                </div>
                <p class="submit">
                    <button id="fp-pause-btn" class="button">${__('Pause', 'flare-press')}</button>
                </p>
                <div id="fp-progress-log" class="fp-migrate-log"></div>
            </div>
        `);

        this.on('#fp-pause-btn', 'click', () => {
            this.paused = !this.paused;
            const btn = this.root.querySelector<HTMLButtonElement>('#fp-pause-btn');
            if (btn) btn.textContent = this.paused
                ? __('Resume', 'flare-press')
                : __('Pause', 'flare-press');
            if (!this.paused) this.runMigrationLoop();
        });
    }

    private updateProgressUI(): void {
        const pct   = this.progressTotal > 0
            ? Math.round((this.progressDone / this.progressTotal) * 100)
            : 0;
        const fill  = this.root.querySelector<HTMLElement>('.fp-migrate-progress-fill');
        const label = this.root.querySelector('.fp-migrate-progress-label');
        if (fill)  fill.style.width = `${pct}%`;
        if (label) label.innerHTML  = `${this.progressDone} / ${this.progressTotal} &mdash; ${pct}%`;
    }

    private appendLog(message: string, type: 'success' | 'error'): void {
        const log = this.root.querySelector('#fp-progress-log');
        if (!log) return;
        const p = document.createElement('p');
        p.className   = `fp-log-line fp-log-${type}`;
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

                const currentEl = this.root.querySelector('#fp-progress-current');
                if (currentEl) currentEl.textContent = `${__('Processing', 'flare-press')} #${id}…`;

                try {
                    const result = await this.ajax<ProcessResult>('fp_migrate_process', {
                        id,
                        variant:        this.variant,
                        delete_from_cf: this.deleteFromCF ? '1' : '0',
                    });
                    this.progressQueue.shift();
                    this.progressDone++;
                    if (result.status === 'error' || result.status === 'skip') {
                        this.counts.failed++;
                        this.failedIds.push(id);
                        this.appendLog(`#${id}: ${result.reason ?? __('error', 'flare-press')}`, 'error');
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
            await this.ajax('fp_migrate_cancel').catch(() => {});
            this.renderSummary();
        }
    }

    private renderSummary(): void {
        const hasFailures = this.failedIds.length > 0;
        const retryBtn = hasFailures
            ? `<button id="fp-retry-btn" class="button button-primary">
                   ${__('Retry Failed', 'flare-press')} (${this.failedIds.length})
               </button>&nbsp;`
            : '';

        this.render(`
            <div class="fp-migrate-wizard">
                <h2 class="fp-migrate-step-title">${__('Migration Complete', 'flare-press')}</h2>
                <div class="fp-migrate-cards">
                    <div class="fp-migrate-card fp-card-green">
                        <span class="fp-migrate-card-num">${this.counts.processed}</span>
                        <span class="fp-migrate-card-lbl">${__('Migrated successfully', 'flare-press')}</span>
                    </div>
                    <div class="fp-migrate-card${hasFailures ? ' fp-card-red' : ''}">
                        <span class="fp-migrate-card-num">${this.counts.failed}</span>
                        <span class="fp-migrate-card-lbl">${__('Failed', 'flare-press')}</span>
                    </div>
                </div>
                <p class="submit">
                    ${retryBtn}
                    <a href="${fpMigrateConfig.logsUrl}" class="button">${__('View Log', 'flare-press')}</a>
                    &nbsp;
                    <a href="${fpMigrateConfig.migrateUrl}" class="button">${__('Run Again', 'flare-press')}</a>
                </p>
            </div>
        `);

        if (hasFailures) {
            this.on('#fp-retry-btn', 'click', () => {
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
            case 'download_needed': return __('Ready', 'flare-press');
            case 'local_copy':      return __('Has local copy', 'flare-press');
            case 'no_variant':      return __('No variant', 'flare-press');
        }
    }
}

const root = document.getElementById('fp-migrate-root');
if (root) {
    new FpMigrateWizard(root).init();
}
