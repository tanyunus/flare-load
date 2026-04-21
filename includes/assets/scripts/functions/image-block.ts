import UploadManager from "../modules/UploadManager";

export function addSwitcherToImageBlock(uploadManager: UploadManager): void {
    setTimeout(() => {
        const iframe = document.querySelector<HTMLIFrameElement>('iframe[name="editor-canvas"]');

        if (!iframe || !iframe.contentDocument) {
            return;
        }

        const iframeDoc = iframe.contentDocument;

        const observer = new MutationObserver(() => {
            const imagePlaceholders = iframeDoc.querySelectorAll('.block-editor-media-placeholder');

            imagePlaceholders.forEach(placeholder => {
                if (placeholder.querySelector('.fp-cf-upload-button')) {
                    return;
                }

                const uploadButton = placeholder.querySelector<HTMLButtonElement>('.block-editor-media-placeholder__button');
                const blockLabel = placeholder.querySelector('.components-placeholder__label');

                if (uploadButton && blockLabel) {
                    const cfButton = uploadManager.createCfUploadButton('components-button block-editor-media-placeholder__button block-editor-media-placeholder__upload-button is-next-40px-default-size is-secondary fp-switcher-for-block-placeholder');
                    cfButton.style.marginLeft = '10px';

                    cfButton.addEventListener('click', () => {
                        (window as any).fp_upload_to_cf_next = true;
                        uploadButton.click();
                    });

                    // Reset CF flag when user clicks the regular upload button directly
                    uploadButton.addEventListener('click', (e) => {
                        if (e.isTrusted) {
                            (window as any).fp_upload_to_cf_next = false;
                        }
                    });

                    uploadButton.parentElement?.insertBefore(cfButton, uploadButton.nextSibling);
                }
            });
        });

        observer.observe(iframeDoc.body, {
            childList: true,
            subtree: true
        });

        observeMainDocumentForDropdown(uploadManager);
    }, 2000);
}

function observeMainDocumentForDropdown(uploadManager: UploadManager): void {
    const observer = new MutationObserver(() => {
        const menus = document.querySelectorAll('[role="menu"]');

        menus.forEach(menu => {
            if (menu.querySelector('.fp-cf-upload-toolbar-button')) {
                return;
            }

            const buttons = menu.querySelectorAll('button');
            let uploadButton: HTMLButtonElement | null = null;

            buttons.forEach(btn => {
                if (btn.textContent?.includes('Upload')) {
                    uploadButton = btn as HTMLButtonElement;
                }
            });

            if (uploadButton) {
                const cfButton = uploadManager.createCfUploadButton('is-next-40px-default-size components-button fp-cf-upload-toolbar-button');
                cfButton.classList.add('components-menu-item__button');
                cfButton.style.width = '100%';
                cfButton.style.justifyContent = 'flex-start';
                cfButton.style.marginLeft = '0';

                cfButton.addEventListener('click', () => {
                    (window as any).fp_upload_to_cf_next = true;
                    uploadButton.click();
                });

                // Reset CF flag when user clicks the regular upload button directly
                uploadButton.addEventListener('click', (e) => {
                    if (e.isTrusted) {
                        (window as any).fp_upload_to_cf_next = false;
                    }
                });

                // Insert right after the upload button
                uploadButton.parentElement?.insertBefore(cfButton, uploadButton.nextSibling);
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}