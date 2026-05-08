import UploadManager from "../modules/UploadManager";

function injectEditorStyles(iframeDoc: Document): void {
    if (iframeDoc.querySelector('#fp-editor-styles')) return;

    const style = iframeDoc.createElement('style');
    style.id = 'fp-editor-styles';
    style.textContent = `
        .fp-cf-upload-button {
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            white-space: nowrap !important;
        }
        .fp-cf-upload-button img {
            height: 12px !important;
            width: auto !important;
            flex-shrink: 0 !important;
        }
    `;
    iframeDoc.head.appendChild(style);
}

export function addSwitcherToImageBlock(uploadManager: UploadManager): void {
    setTimeout(() => {
        const iframe = document.querySelector<HTMLIFrameElement>('iframe[name="editor-canvas"]');

        if (!iframe || !iframe.contentDocument) {
            return;
        }

        const iframeDoc = iframe.contentDocument;
        injectEditorStyles(iframeDoc);

        const CF_SUPPORTED_BLOCKS = ['core/image', 'core/cover', 'core/media-text', 'core/gallery'];

        const observer = new MutationObserver(() => {
            const imagePlaceholders = iframeDoc.querySelectorAll('.block-editor-media-placeholder');

            imagePlaceholders.forEach(placeholder => {
                if (placeholder.querySelector('.fp-cf-upload-button')) {
                    return;
                }

                const blockWrapper = placeholder.closest<HTMLElement>('[data-type]');
                if (!blockWrapper || !CF_SUPPORTED_BLOCKS.includes(blockWrapper.getAttribute('data-type') ?? '')) {
                    return;
                }

                const uploadButton = placeholder.querySelector<HTMLButtonElement>('.block-editor-media-placeholder__button');
                const blockLabel = placeholder.querySelector('.components-placeholder__label');

                if (uploadButton && blockLabel) {
                    const cfButton = uploadManager.createCfUploadButton('components-button block-editor-media-placeholder__button block-editor-media-placeholder__upload-button is-next-40px-default-size is-secondary fp-switcher-for-block-placeholder');

                    cfButton.addEventListener('click', () => {
                        (window as any).flarep_upload_to_cf_next = true;
                        uploadButton.click();
                    });

                    uploadButton.addEventListener('click', (e) => {
                        if (e.isTrusted) {
                            (window as any).flarep_upload_to_cf_next = false;
                        }
                    });

                    uploadButton.parentElement?.insertBefore(cfButton, uploadButton.nextSibling);

                    const parentEl = uploadButton.parentElement!;
                    const iframeWin = iframeDoc.defaultView!;

                    const applyMargin = () => {
                        iframeWin.requestAnimationFrame(() => {
                            const uploadTop = uploadButton.getBoundingClientRect().top;
                            const cfTop     = cfButton.getBoundingClientRect().top;
                            const sameRow   = Math.abs(uploadTop - cfTop) < 5;
                            cfButton.style.marginLeft = sameRow ? '8px' : '0px';
                            cfButton.style.marginTop  = sameRow ? '0px' : '8px';
                        });
                    };

                    applyMargin();
                    new iframeWin.ResizeObserver(applyMargin).observe(parentEl);
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
                    (window as any).flarep_upload_to_cf_next = true;
                    uploadButton.click();
                });

                uploadButton.addEventListener('click', (e) => {
                    if (e.isTrusted) {
                        (window as any).flarep_upload_to_cf_next = false;
                    }
                });

                uploadButton.parentElement?.insertBefore(cfButton, uploadButton.nextSibling);
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}
