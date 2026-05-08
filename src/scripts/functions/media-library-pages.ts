import UploadManager from "../modules/UploadManager";

export function createAndAppendSwitcher(uploadManager: UploadManager, append: HTMLElement): void {
    const switcherElement = uploadManager.createSwitcherElement();
    append.after(switcherElement);

    const checkbox = document.querySelector<HTMLInputElement>('#flarep_upload_to_cf');
    if (checkbox) {
        uploadManager.setSwitcherCheckbox(checkbox);
    }
}