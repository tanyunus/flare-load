const cfImageIds = new Set<string | number>();

export function detectAndMarkCfImages(): void {
    window.addEventListener('fpFileUploaded', ((event: CustomEvent) => {
        const data = event.detail;
        if (data.fp_cf_image_id) {
            cfImageIds.add(data.id);
            waitForAttachmentElement(data.id);
        }
    }) as EventListener);

    if (window.jQuery) {
        window.jQuery(document).ajaxComplete((event: any, xhr: any, settings: any) => {
            if (settings.url?.includes('admin-ajax.php')) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    processCfImageResponse(response);
                } catch (e) {
                }
            }
        });
    }

    observeAttachmentGrid();
}

function processCfImageResponse(response: any): void {
    if (!response) return;

    if (Array.isArray(response.data)) {
        response.data.forEach((attachment: any) => {
            if (attachment.fp_cf_image_id) {
                cfImageIds.add(attachment.id);
                markAttachmentElement(attachment.id);
            }
        });
    } else if (response.data?.fp_cf_image_id) {
        cfImageIds.add(response.data.id);
        markAttachmentElement(response.data.id);
    }
}

function markAttachmentElement(attachmentId: string | number): void {
    const element = document.querySelector<HTMLElement>(`li.attachment[data-id="${attachmentId}"]`);

    if (element && !element.classList.contains('fp-cf-badge')) {
        element.classList.add('fp-cf-badge');
    }
}

function observeAttachmentGrid(): void {
    function reMarkAll(): void {
        cfImageIds.forEach(id => markAttachmentElement(id));
    }

    function attachGridObserver(): void {
        const grid = document.querySelector('.attachments');
        if (grid) {
            new MutationObserver(reMarkAll).observe(grid, { childList: true, subtree: true });
        }
    }

    const bodyObserver = new MutationObserver(() => {
        if (document.querySelector('.attachments')) {
            bodyObserver.disconnect();
            attachGridObserver();
        }
    });

    bodyObserver.observe(document.body, { childList: true, subtree: true });
}

function waitForAttachmentElement(attachmentId: string | number): void {
    const checkElement = (): void => {
        const element = document.querySelector<HTMLElement>(`li.attachment[data-id="${attachmentId}"]`);
        if (element) {
            markAttachmentElement(attachmentId);
        } else {
            setTimeout(checkElement, 100);
        }
    };

    checkElement();
}
