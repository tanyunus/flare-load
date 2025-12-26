import UploadManager from "../modules/UploadManager";
import {createAndAppendSwitcher} from "../functions/media-library-pages";
import {detectAndMarkCfImages} from "../functions/cf-image-detector";

function initPage(): void {
    const append =
        document.querySelector<HTMLElement>('.page-title-action') ??
        document.querySelector<HTMLElement>('.wrap h1');

    if(!append) {
        return;
    }

    const uploadManager = new UploadManager();

    createAndAppendSwitcher(uploadManager, append);

    uploadManager.hookUploader();

    detectAndMarkCfImages();
}

document.addEventListener('DOMContentLoaded', () => {
    initPage();
});