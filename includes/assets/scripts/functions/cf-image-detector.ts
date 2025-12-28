const cfImageIds = new Set<string | number>();

export function detectAndMarkCfImages(): void {
    // Listen for file upload completion
    window.addEventListener('fpFileUploaded', ((event: CustomEvent) => {
        const data = event.detail;
        if (data.fp_cf_image_id) {
            cfImageIds.add(data.id);
            console.log('[FP] New CF image uploaded:', data.id);
            waitForAttachmentElement(data.id);
        }
    }) as EventListener);

    // Hook into jQuery AJAX to catch media library responses
    if (window.jQuery) {
        window.jQuery(document).ajaxComplete((event: any, xhr: any, settings: any) => {
            if (settings.url?.includes('admin-ajax.php')) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    processCfImageResponse(response);
                } catch (e) {
                    // Not JSON or parsing failed
                }
            }
        });
    }

    // Also observe DOM for attachment elements
    observeAttachmentGrid();
}

function processCfImageResponse(response: any): void {
    if (!response) return;

    // Handle array of attachments
    if (Array.isArray(response.data)) {
        response.data.forEach((attachment: any) => {
            if (attachment.fp_cf_image_id) {
                cfImageIds.add(attachment.id);
                markAttachmentElement(attachment.id);
            }
        });
    }
    // Handle single attachment
    else if (response.data?.fp_cf_image_id) {
        cfImageIds.add(response.data.id);
        markAttachmentElement(response.data.id);
    }
}

function markAttachmentElement(attachmentId: string | number): void {
    const element = document.querySelector<HTMLElement>(`li.attachment[data-id="${attachmentId}"]`);

    if (element && !element.classList.contains('fp-cf-badge')) {
        element.classList.add('fp-cf-badge');
        console.log('[FP] Marked CF image:', attachmentId);
    }
}

function observeAttachmentGrid(): void {
    // Continuously re-mark CF images every 500ms
    setInterval(() => {
        cfImageIds.forEach(id => {
            const element = document.querySelector<HTMLElement>(`li.attachment[data-id="${id}"]`);
            if (element && !element.classList.contains('fp-cf-badge')) {
                element.classList.add('fp-cf-badge');
                console.log('[FP] Re-marked CF image:', id);
            }
        });
    }, 500);
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