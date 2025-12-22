import FpMediaLibraryMonitor from "../modules/FpLibraryMonitor";
import {PluploadFile} from "../types/types";

function addCfBadge(cfImageElements: Array<{ element: HTMLElement }>): void {
    cfImageElements.forEach(cfImageElement => {
        cfImageElement.element.classList.add('fp-cf-badge');
    });
}

function modifyUploadFormData(mediaLibraryMonitor: FpMediaLibraryMonitor): void {
    // Clear any existing params first
    mediaLibraryMonitor.clearUploadParams();

    // Add a dynamic callback that always checks the current checkbox state
    mediaLibraryMonitor.modifyUploadFormData({}, (file: PluploadFile) => {
        const checkbox = document.querySelector<HTMLInputElement>('#fp_upload_switcher');
        const value = checkbox ? +checkbox.checked : 0;
        console.log('[FP] Reading checkbox state:', value, 'Checkbox element:', checkbox);

        return {
            fp_upload_to_cf: value
        };
    });
}

function createUploadSwitcherElement(additionalClassName = ''): HTMLLabelElement {
    const checkboxId = 'fp_upload_switcher';

    const labelElement = document.createElement('label');
    labelElement.className = 'fp-upload-switcher' + ' ' + additionalClassName;
    labelElement.htmlFor = 'fp_upload_switcher';

    const checkBoxElement = document.createElement('input');
    checkBoxElement.name = checkboxId;
    checkBoxElement.id = checkboxId;
    checkBoxElement.type = 'checkbox';

    labelElement.appendChild(checkBoxElement);
    labelElement.innerHTML += 'Upload to Cloudflare';

    return labelElement;
}

function appendSwitcherToSideOfAddMediaButton(uploadSwitcherElement: HTMLLabelElement): boolean {
    const addMediaFileButton = document.querySelector<HTMLElement>('#wp-media-grid > a.page-title-action');

    if (!addMediaFileButton) {
        return false;
    }

    addMediaFileButton.after(uploadSwitcherElement);

    addCfUploadIndicatorToWindow();

    return true;
}

function appendSwitcherToSideOfTitle(uploadSwitcherElement: HTMLLabelElement): boolean {
    const mediaNewPageTitle = document.querySelector<HTMLHeadingElement>('body.media-new-php h1');

    if (!mediaNewPageTitle) {
        return false;
    }

    mediaNewPageTitle.after(uploadSwitcherElement);

    addCfUploadIndicatorToWindow();

    return true;
}

function handleUploadSwitcherElement(): boolean {
    const uploadSwitcherElement = createUploadSwitcherElement();

    if (appendSwitcherToSideOfAddMediaButton(uploadSwitcherElement)) {
        return true;
    }

    return appendSwitcherToSideOfTitle(uploadSwitcherElement);
}

function handleMediaLibraryListView(): void {
    const mediaTable = document.querySelector<HTMLTableElement>('.wp-list-table.media');

    if (!mediaTable) {
        return;
    }

    const rowArray = Array.from(mediaTable.rows);

    rowArray.forEach(row => {
        const newRowDetails = {
            fileName: "",
            url: ""
        };

        const cellArray = Array.from(row.cells);
        cellArray.forEach(cell => {
            if (cell.classList.contains('fp_cf_badge_column') && cell.innerHTML) {
                const cfLogoWrapper = cell.querySelector<HTMLElement>('[data-fp-file-name][data-fp-url]');

                if (cfLogoWrapper) {
                    newRowDetails.fileName = cfLogoWrapper.getAttribute('data-fp-file-name') || "";
                    newRowDetails.url = cfLogoWrapper.getAttribute('data-fp-url') || "";
                }

                const titleCell = row.querySelector<HTMLTableCellElement>('.title.column-title[data-colname="File"]');
                if (titleCell) {
                    const fileNameElement = titleCell.querySelector<HTMLElement>('.filename');
                    const copyAttachmentButton = titleCell.querySelector<HTMLElement>('.copy-attachment-url');

                    if (fileNameElement && copyAttachmentButton) {
                        let screenReaderText = titleCell.querySelector<HTMLElement>('.screen-reader-text');
                        if (screenReaderText) {
                            screenReaderText = screenReaderText.cloneNode(true) as HTMLElement;
                        }

                        fileNameElement.innerHTML = "";
                        if (screenReaderText) {
                            fileNameElement.appendChild(screenReaderText);
                        }
                        fileNameElement.innerHTML += newRowDetails.fileName;

                        copyAttachmentButton.dataset.clipboardText = newRowDetails.url;
                    }
                }
            }
        });
    });
}

function handleUploadSwitcherElementForMediaModal(): void {
    // Watch for media modal to open
    const observer = new MutationObserver(() => {
        const mediaModal = document.querySelector<HTMLElement>('.media-modal');
        if (mediaModal && mediaModal.style.display !== 'none') {
            // Check if upload view is active
            const uploadView = mediaModal.querySelector<HTMLElement>('.media-frame-content .uploader-inline');
            if (uploadView && uploadView.style.display !== 'none') {
                appendSwitcherToUploadWindow(mediaModal);
            }
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class']
    });
}

function appendSwitcherToUploadWindow(mediaModal: HTMLElement | null): boolean {
    if (!mediaModal) {
        return false;
    }

    // Check if already exists
    const existingSwitcher = mediaModal.querySelector('#fp_upload_switcher');
    if (existingSwitcher) {
        return true;
    }

    // Find the upload UI
    const uploadUI = mediaModal.querySelector<HTMLElement>('.media-frame-content .uploader-inline-content');
    if (!uploadUI) {
        return false;
    }

    const selectFilesButton = uploadUI.querySelector<HTMLButtonElement>('button.browser');
    if (!selectFilesButton) {
        return false;
    }

    const uploadSwitcherElement = createUploadSwitcherElement('fp-media-modal-switcher');

    // Insert after the button's parent paragraph
    const buttonContainer = selectFilesButton.closest('p') || selectFilesButton.parentElement;
    if (buttonContainer) {
        buttonContainer.after(uploadSwitcherElement);
    }

    addCfUploadIndicatorToWindow();

    return true;
}

function watchMediaFrameTabs(): void {
    // Listen for media frame state changes
    if (window.wp && window.wp.media) {
        const originalFrame = window.wp.media as any;

        window.wp.media = function (options?: any) {
            const frame = originalFrame(options);

            frame.on('content:render', function () {
                setTimeout(() => {
                    const mediaModal = document.querySelector<HTMLElement>('.media-modal');
                    if (mediaModal) {
                        const uploadView = mediaModal.querySelector<HTMLElement>('.media-frame-content .uploader-inline');
                        if (uploadView && uploadView.style.display !== 'none') {
                            appendSwitcherToUploadWindow(mediaModal);
                        }
                    }
                }, 100);
            });

            return frame;
        };

        // Copy static properties
        Object.setPrototypeOf(window.wp.media, originalFrame);
        Object.keys(originalFrame).forEach(key => {
            if (!(key in window.wp?.media!)) {
                (window.wp?.media as any)[key] = originalFrame[key];
            }
        });
    }
}

function addCfUploadIndicatorToWindow(): void {
    window.fp_upload_to_cf = 0;

    const newUploadSwitcherElement = document.querySelector<HTMLInputElement>('#fp_upload_switcher');

    if (newUploadSwitcherElement) {
        newUploadSwitcherElement.addEventListener('change', () => {
            window.fp_upload_to_cf = newUploadSwitcherElement.checked ? 1 : 0;
        });
    }
}

// Listen dom load
document.addEventListener('DOMContentLoaded', () => {
    handleMediaLibraryListView();

    const mediaLibraryMonitor = new FpMediaLibraryMonitor();

    watchMediaFrameTabs();

    mediaLibraryMonitor.ready.then(async () => {
        // Set up upload form data modifier once (it will work for all checkboxes)
        modifyUploadFormData(mediaLibraryMonitor);

        // Then handle the UI
        handleUploadSwitcherElement();

        handleUploadSwitcherElementForMediaModal();

        window.addEventListener('fpMediaLibrary:cfImageElementFound', () => {
            addCfBadge(mediaLibraryMonitor.getCfImageAttachmentsWithElements());
        });

        window.addEventListener('fpMediaLibrary:cfImageElementAdded', () => {
            addCfBadge(mediaLibraryMonitor.getCfImageAttachmentsWithElements());
        });
    });
});