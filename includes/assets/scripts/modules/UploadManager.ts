import { __ } from '@wordpress/i18n';

export default class UploadManager {
    private switcherCheckbox: HTMLInputElement | null = null;

    // Hooks into
    //  - default uploader in /wp-admin/upload.php page
    //  - media modal uploader in post-edit and new-post pages
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

    // Hooks into uploader in /wp-admin/media-new.php page
    public hookExistingUploader(): void {
        if (!window.uploader) {
            return;
        }

        this.bindBeforeUploadEvent(window.uploader);
    }

    // Hooks into browser uploader in /wp-admin/media-new.php page
    public hookBrowserUploaderForm(): void {
        const form = document.querySelector<HTMLFormElement>('form#file-form');

        if (!form) {
            return;
        }

        form.addEventListener('submit', (e) => {
            // Add hidden input with our custom param
            const existingInput = form.querySelector<HTMLInputElement>('input[name="fp_upload_to_cf"]');

            if (existingInput) {
                existingInput.value = this.isUploadToCfEnabled() ? '1' : '0';
            } else {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'fp_upload_to_cf';
                input.value = this.isUploadToCfEnabled() ? '1' : '0';
                form.appendChild(input);
            }
        });
    }

    public hookRestApiUpload(): void {
        const originalFetch = window.fetch;

        window.fetch = async function(...args: Parameters<typeof fetch>): Promise<Response> {
            const url = args[0]?.toString() || '';

            if (url.includes('/wp/v2/media')) {
                const options = args[1] || {};

                if (options.body instanceof FormData) {
                    const formData = options.body;
                    formData.append('fp_upload_to_cf', (window as any).fp_upload_to_cf_next ? '1' : '0');
                }
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
                fp_upload_to_cf: manager.isUploadToCfEnabled() ? 1 : 0
            };
        });

        uploader.bind('FileUploaded', (up: any, file: any, response: any) => {
            try {
                const data = JSON.parse(response.response);
                if (data.success && data.data) {
                    window.dispatchEvent(new CustomEvent('fpFileUploaded', {
                        detail: data.data
                    }));
                }
            } catch (e) {
                // Parsing failed
            }
        });
    }

    public createSwitcherElement(customClass: string = ''): HTMLLabelElement {
        const label = document.createElement('label');
        label.className = 'fp-upload-switcher ' + customClass;
        label.style.marginLeft = '10px';
        label.htmlFor = 'fp_upload_to_cf';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'fp_upload_to_cf';
        checkbox.name = 'fp_upload_to_cf';

        label.appendChild(checkbox);
        label.appendChild(document.createTextNode(' ' + __('Upload to Cloudflare', 'flare-press')));

        return label;
    }

    public createCfUploadButton(additionalClassName: string = ''): HTMLButtonElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'fp-cf-upload-button ' + additionalClassName;
        // Use dynamic plugin URL from wp_localize_script to support subdirectory installations
        const logoUrl = (window.fpConfig?.pluginUrl ?? '') + 'includes/dist/images/cf_logo_cropped.png';
        const img = document.createElement('img');
        img.src = logoUrl;
        img.alt = 'Cloudflare logo';

        button.appendChild(document.createTextNode(__('Upload to Cloudflare', 'flare-press')));
        button.appendChild(img);
        button.style.boxShadow = 'inset 0 0 0 1px #f78100, 0 0 0 currentColor';
        button.style.color = '#f78100';
        return button;
    }

    public setSwitcherCheckbox(checkbox: HTMLInputElement): void {
        this.switcherCheckbox = checkbox;
    }

    public isUploadToCfEnabled(): boolean {
        return this.switcherCheckbox?.checked || false;
    }
}