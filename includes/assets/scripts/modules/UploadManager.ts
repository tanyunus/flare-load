export default class UploadManager {
    private switcherCheckbox: HTMLInputElement | null = null;

    // Hooks into
    //  - default uploader in /wp-admin/upload.php page
    //  - media modal uploader in post-edit and new-post pages
    public hookUploader(): void {
        if (!window.wp?.Uploader) {
            console.log('[FP] wp.Uploader not available');
            return;
        }

        const originalInit = window.wp.Uploader.prototype.init;
        const manager = this;

        window.wp.Uploader.prototype.init = function() {
            const result = originalInit.apply(this, arguments as any);

            console.log('[FP] wp.Uploader.prototype.init intercepted');

            manager.bindBeforeUploadEvent(this.uploader);

            return result;
        };
    }

    // Hooks into uploader in /wp-admin/media-new.php page
    public hookExistingUploader(): void {
        if (!window.uploader) {
            console.log('[FP] window.uploader not available');
            return;
        }

        console.log('[FP] Hooking existing uploader instance');
        this.bindBeforeUploadEvent(window.uploader);
    }

    // Hooks into browser uploader in /wp-admin/media-new.php page
    public hookBrowserUploaderForm(): void {
        const form = document.querySelector<HTMLFormElement>('form#file-form');

        if (!form) {
            console.log('[FP] media-new form not found');
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

            console.log('[FP] Form submit - fp_upload_to_cf:', this.isUploadToCfEnabled() ? 1 : 0);
        });
    }

    public waitForUploader(callback: () => void): void {
        const checkUploader = (): void => {
            if (window.uploader) {
                console.log('[FP] window.uploader found');
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
            console.log('[FP] BeforeUpload event fired for file:', file.name);

            up.settings.multipart_params = {
                ...up.settings.multipart_params,
                fp_upload_to_cf: manager.isUploadToCfEnabled() ? 1 : 0
            };

            console.log('[FP] Injected fp_upload_to_cf:', manager.isUploadToCfEnabled() ? 1 : 0);
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
        label.appendChild(document.createTextNode(' Upload to Cloudflare'));

        return label;
    }

    public setSwitcherCheckbox(checkbox: HTMLInputElement): void {
        this.switcherCheckbox = checkbox;
    }

    public isUploadToCfEnabled(): boolean {
        return this.switcherCheckbox?.checked || false;
    }
}