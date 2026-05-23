import { __ } from '@wordpress/i18n';

export default class UploadManager {
    private switcherCheckbox: HTMLInputElement | null = null;

    public hookUploader(): void {
        if (!window.wp?.Uploader) {
            return;
        }

        const originalInit = window.wp.Uploader.prototype.init;
        const manager = this;

        window.wp.Uploader.prototype.init = function() {
            const result = originalInit.apply(this, arguments as any);

            manager.bindBeforeUploadEvent(this.uploader);

            return result;
        };
    }

    public hookExistingUploader(): void {
        if (!window.uploader) {
            return;
        }

        this.bindBeforeUploadEvent(window.uploader);
    }

    public hookBrowserUploaderForm(): void {
        const form = document.querySelector<HTMLFormElement>('form#file-form');

        if (!form) {
            return;
        }

        form.addEventListener('submit', (e) => {
            const existingInput = form.querySelector<HTMLInputElement>('input[name="flareload_upload_to_cf"]');

            if (existingInput) {
                existingInput.value = this.isUploadToCfEnabled() ? '1' : '0';
            } else {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'flareload_upload_to_cf';
                input.value = this.isUploadToCfEnabled() ? '1' : '0';
                form.appendChild(input);
            }
        });
    }

    public hookRestApiUpload(): void {
        const originalFetch = window.fetch;
        const manager = this;

        window.fetch = async function(...args: Parameters<typeof fetch>): Promise<Response> {
            const url = args[0]?.toString() || '';

            if (url.includes('/wp/v2/media')) {
                const options = args[1] || {};

                const uploadToCf = (window as any).flareload_upload_to_cf_next;

                if (options.body instanceof FormData) {
                    options.body.append('flareload_upload_to_cf', uploadToCf ? '1' : '0');
                }

                const response = await originalFetch.apply(this, args);

                if (response.ok && uploadToCf) {
                    manager.checkUploadError();
                }

                return response;
            }

            return originalFetch.apply(this, args);
        };
    }

    public waitForUploader(callback: () => void): void {
        const checkUploader = (): void => {
            if (window.uploader) {
                callback();
            } else {
                setTimeout(checkUploader, 100);
            }
        };

        checkUploader();
    }

    private bindBeforeUploadEvent(uploader: any): void {
        const manager = this;

        uploader.bind('BeforeUpload', (up: any, file: any) => {
            up.settings.multipart_params = {
                ...up.settings.multipart_params,
                flareload_upload_to_cf: manager.isUploadToCfEnabled() ? 1 : 0
            };
        });

        uploader.bind('FileUploaded', (up: any, file: any, response: any) => {
            manager.checkUploadError();
            try {
                const data = JSON.parse(response.response);
                if (data.success && data.data) {
                    window.dispatchEvent(new CustomEvent('fpFileUploaded', {
                        detail: data.data
                    }));
                }
            } catch (e) {
            }
        });
    }

    public createSwitcherElement(customClass: string = ''): HTMLLabelElement {
        const label = document.createElement('label');
        label.className = 'flareload-upload-switcher ' + customClass;
        label.style.marginLeft = '10px';
        label.htmlFor = 'flareload_upload_to_cf';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'flareload_upload_to_cf';
        checkbox.name = 'flareload_upload_to_cf';

        label.appendChild(checkbox);
        label.appendChild(document.createTextNode(' ' + __('Upload to Cloudflare', 'flare-load')));

        return label;
    }

    public createCfUploadButton(additionalClassName: string = ''): HTMLButtonElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'flareload-cf-upload-button ' + additionalClassName;
        button.appendChild(document.createTextNode(__('Upload to Cloudflare', 'flare-load')));
        button.style.boxShadow = 'inset 0 0 0 1px #f78100, 0 0 0 currentColor';
        button.style.color = '#f78100';
        return button;
    }

    public checkUploadError(): void {
        const ajaxUrl = (window as any).ajaxurl;
        if (!ajaxUrl) return;

        const body = new FormData();
        body.append('action', 'flareload_check_upload_error');

        fetch(ajaxUrl, { method: 'POST', body })
            .then(r => r.json())
            .then((data: any) => {
                if (data.success && data.data === true) {
                    this.showUploadError();
                }
            })
            .catch(() => { console.error('[FlareLoad] Upload error check failed.'); });
    }

    public listenHeartbeatForErrors(): void {
        const manager = this;
        (window as any).jQuery?.(document).on('heartbeat-tick', (_e: any, data: any) => {
            if (data.flareload_upload_error) {
                manager.showUploadError();
            }
        });
    }

    public watchMediaAttachments(): void {
        const manager = this;
        const tryBind = () => {
            const attachments = (window as any).wp?.media?.model?.Attachments?.all;
            if (attachments) {
                attachments.on('add', (model: any) => {
                    if (model.get('flareload_upload_error')) {
                        manager.showUploadError();
                    }
                });
            } else {
                setTimeout(tryBind, 500);
            }
        };
        tryBind();
    }

    private buildErrorMessage(count: number): string {
        const logsUrl = (window as any).flareloadConfig?.logsUrl ?? '';
        const logsLink = logsUrl ? ` <a href="${logsUrl}" target="_blank" rel="noopener noreferrer">${__('Check FlareLoad logs for details.', 'flare-load')}</a>` : '';

        if (count === 1) {
            return __('Upload to Cloudflare failed. The image was saved locally.', 'flare-load') + logsLink;
        }
        return count + ' ' + __('uploads to Cloudflare failed. The images were saved locally.', 'flare-load') + logsLink;
    }

    public showUploadError(): void {
        const mediaModal = document.querySelector<HTMLElement>('.media-modal .media-frame-content');
        const blockEditor = document.querySelector<HTMLElement>('.interface-interface-skeleton__content');

        const container = mediaModal
            ?? blockEditor
            ?? document.querySelector<HTMLElement>('#wpcontent')
            ?? document.body;
        const existing = container.querySelector<HTMLElement>('.flareload-upload-error-notice');

        if (existing) {
            const count = parseInt(existing.dataset.errorCount || '1', 10) + 1;
            existing.dataset.errorCount = String(count);
            const p = existing.querySelector('p');
            if (p) p.innerHTML = this.buildErrorMessage(count);
            return;
        }

        const notice = document.createElement('div');
        notice.className = 'notice notice-error is-dismissible flareload-upload-error-notice';
        notice.dataset.errorCount = '1';
        notice.innerHTML = `<p>${this.buildErrorMessage(1)}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>`;
        notice.querySelector('.notice-dismiss')?.addEventListener('click', () => notice.remove());

        container.insertBefore(notice, container.firstChild);
    }

    public setSwitcherCheckbox(checkbox: HTMLInputElement): void {
        this.switcherCheckbox = checkbox;
    }

    public isUploadToCfEnabled(): boolean {
        return this.switcherCheckbox?.checked || false;
    }
}
