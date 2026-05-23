import UploadManager from "../modules/UploadManager";

function addSwitcherToUploadView(mediaModal: HTMLElement, uploadManager: UploadManager): void {
    if (mediaModal.querySelector('#flareload_upload_to_cf')) {
        return;
    }

    const selectFilesButton = mediaModal.querySelector<HTMLButtonElement>('.uploader-inline button.browser');
    if (!selectFilesButton) {
        return;
    }

    const switcherElement = uploadManager.createSwitcherElement('flarep-media-modal-switcher');

    selectFilesButton.after(switcherElement);

    const checkbox = switcherElement.querySelector<HTMLInputElement>('#flareload_upload_to_cf');
    if (checkbox) {
        uploadManager.setSwitcherCheckbox(checkbox);
    }
}

export function appendSwitcherToMediaModal(uploadManager: UploadManager): void {
    const observer = new MutationObserver(() => {
        const mediaModal = document.querySelector<HTMLElement>('.media-modal');

        if (mediaModal && mediaModal.style.display !== 'none') {
            const uploadView = mediaModal.querySelector<HTMLElement>('.uploader-inline');

            if (uploadView && uploadView.style.display !== 'none') {
                addSwitcherToUploadView(mediaModal, uploadManager);
            }
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style']
    });
}
