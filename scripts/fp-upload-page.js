document.addEventListener('DOMContentLoaded', () => {
    console.log('Script for upload page loaded.');

    const addMediaFileButton = document.querySelector(`#wp-media-grid > a.page-title-action`);
    const uploadSwitcherElement = createUploadSwitcherElement();

    addMediaFileButton.after(uploadSwitcherElement);

    waitForUploader().then(Uploader => modifyUploadFormData(Uploader, uploadSwitcherElement)).catch(error => {
        console.error('Failed to initialize uploader hooks:', error);
    });
});

function modifyUploadFormData(uploader, uploadSwitcherElement) {
    const originalInit = uploader.prototype.init;

    uploader.prototype.init = function() {
        const ret = originalInit.apply(this, arguments);

        this.uploader.bind('BeforeUpload', (up, file) => {
            up.settings.multipart_params = {
                ...up.settings.multipart_params,
                fp_upload_to_cf: + uploadSwitcherElement.querySelector('input').checked
            }

            Object.assign(up.settings.multipart_params, {
                file_info: JSON.stringify({
                    name: file.name,
                    size: file.size,
                    type: file.type
                })
            });
        });

        return ret;
    };
}

function createUploadSwitcherElement() {
    const checkboxId = 'fp_upload_switcher';

    const labelElement = document.createElement('label');
    labelElement.className = 'fp-upload-switcher';
    labelElement.htmlFor = 'fp_upload_switcher';

    const checkBoxElement = document.createElement('input');
    checkBoxElement.name = checkboxId;
    checkBoxElement.id = checkboxId;
    checkBoxElement.type = 'checkbox';

    labelElement.appendChild(checkBoxElement);
    labelElement.innerHTML += 'Upload to Cloudflare';

    return labelElement;
}

function waitForUploader() {
    return new Promise((resolve, reject) => {
        const startTime = Date.now();

        const check = () => {
            if(typeof window.wp !== 'undefined' && window.wp.Uploader) {
                resolve(window.wp.Uploader);
            } else if(Date.now() - startTime > 10000) {
                reject(new Error('Timeout waiting for wp.Uploader'));
            } else {
                setTimeout(check, 100);
            }
        }

        check();
    })
}