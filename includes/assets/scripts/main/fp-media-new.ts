import UploadManager from "../modules/UploadManager";
import {createAndAppendSwitcher} from "../functions/media-library-pages";

function initPage(): void {
    const append =
        document.querySelector<HTMLElement>('.page-title-action') ??
        document.querySelector<HTMLElement>('.wrap h1');

    if(!append) {
        return;
    }

    const uploadManager = new UploadManager();

    createAndAppendSwitcher(uploadManager, append);

    uploadManager.waitForUploader(() => {
        uploadManager.hookExistingUploader();
    });

    uploadManager.hookBrowserUploaderForm();
}

document.addEventListener('DOMContentLoaded', () => {
    initPage();
});