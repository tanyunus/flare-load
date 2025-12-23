import type {
    WordPressMedia,           // Used in hookMediaGrid() and hookAttachmentViews()
    WordPressGlobal,          // Used in window.wp type checking throughout
    PluploadFile,             // Used in getUploadParams() and callbacks
    PluploadUploader,         // Used in hookExistingUploader() and uploader instances
    WordPressUploaderInstance,// Used in hookUploader() for 'this' context
    AttachmentData,           // Used in attachments Map and methods
    CfImageData,              // Used in cfImageAttachments Map
    UploadParams,             // Used in uploadParams property
    DynamicParamsCallback,    // Used in uploadParamsCallbacks array
    UploadResponse,           // Used in FileUploaded event handler
    AjaxResponse,             // Used in processAjaxResponse()
    EventDetail,              // Used in emitEvent()
    RefreshResult,            // Return type of refreshCfImageElements()
    StatsResult               // Return type of getStats()
} from '../types/types';

export default class FpMediaLibraryMonitor {
    private attachments: Map<string | number, AttachmentData>;
    private pendingThumbnails: Map<string | number, any>;
    private cfImageAttachments: Map<string | number, CfImageData>;
    private uploadParams: UploadParams;
    private uploadParamsCallbacks: DynamicParamsCallback[];
    private initialized: boolean;
    private existingUploaderHooked: boolean;
    private uploaderPrototypeHooked: boolean;
    private readonly isMediaLibraryPage: boolean;
    private readonly isMediaNewPage: boolean;
    private debug: boolean;
    private gridObserver?: MutationObserver;
    public ready: Promise<boolean>;

    constructor() {
        this.attachments = new Map();
        this.pendingThumbnails = new Map();
        this.cfImageAttachments = new Map();
        this.uploadParams = {};
        this.uploadParamsCallbacks = [];
        this.initialized = false;
        this.existingUploaderHooked = false;
        this.uploaderPrototypeHooked = false;
        this.isMediaLibraryPage = window.location.pathname.includes('upload.php');
        this.isMediaNewPage = window.location.pathname.includes('media-new.php');
        this.debug = false;
        this.ready = this.init();
    }

    private async init(): Promise<boolean> {
        try {
            console.log('[FP] Initializing on:', window.location.pathname);

            // Hook into AJAX responses to catch fp_cf_image_id
            this.hookAjaxResponses();

            // Different initialization for different pages
            if (this.isMediaLibraryPage) {
                await this.initForMediaLibraryPage();
            } else if (this.isMediaNewPage) {
                await this.initForMediaNewPage();
            } else {
                await this.initForMediaModal();
            }

            this.initialized = true;
            console.log('[FP] MediaLibraryMonitor initialized');
            return true;
        } catch (error) {
            console.error('[FP] Failed to initialize MediaLibraryMonitor:', error);
            // Don't throw, still mark as initialized
            this.initialized = true;
            return false;
        }
    }

    // Method to modify upload form data
    public modifyUploadFormData(
        customParams: UploadParams = {},
        dynamicParamsCallback?: DynamicParamsCallback | null
    ): void {
        // Store static params
        this.uploadParams = { ...this.uploadParams, ...customParams };

        // Store dynamic params callback if provided
        if (dynamicParamsCallback && typeof dynamicParamsCallback === 'function') {
            this.uploadParamsCallbacks.push(dynamicParamsCallback);
        }

        console.log('[FP] Upload params updated:', this.uploadParams);
    }

    // Method to get all upload params for a file
    private getUploadParams(file: PluploadFile): Record<string, any> {
        let params: Record<string, any> = { ...this.uploadParams };

        // Apply all dynamic callbacks
        this.uploadParamsCallbacks.forEach((callback, index) => {
            try {
                const dynamicParams = callback(file);
                console.log(`[FP] Dynamic callback ${index} returned:`, dynamicParams);
                if (dynamicParams) {
                    params = { ...params, ...dynamicParams };
                }
            } catch (e) {
                console.error('[FP] Error in upload params callback:', e);
            }
        });

        // Always add file info
        params.file_info = JSON.stringify({
            name: file.name,
            size: file.size,
            type: file.type
        });

        params.fp_upload_to_cf = window.fp_upload_to_cf;

        console.log('[FP] Final upload params:', params);
        return params;
    }

    // Clear all custom upload parameters
    public clearUploadParams(): void {
        this.uploadParams = {};
        this.uploadParamsCallbacks = [];
        console.log('[FP] Upload params cleared');
    }

    private hookUploader(): void {
        if (!window.wp?.Uploader) {
            console.log('[FP] wp.Uploader not available yet');
            return;
        }

        if (this.uploaderPrototypeHooked) {
            console.log('[FP] Uploader prototype already hooked');
            return;
        }

        this.uploaderPrototypeHooked = true;
        const monitor = this;

        // Store original init
        const originalInit = window.wp?.Uploader.prototype.init;

        window.wp.Uploader.prototype.init = function (this: WordPressUploaderInstance) {
            const ret = originalInit.apply(this, arguments as any);

            console.log('[FP] Uploader initialized');

            monitor.emitEvent('UploaderInit', {});

            // Mark this instance as hooked
            if (!this._fpHooked) {
                this._fpHooked = true;

                this.uploader.bind('Init', (up: PluploadUploader) => {
                    console.log('[FP] Plupload initialized');
                });

                // DON'T unbind - just add our handler
                this.uploader.bind('BeforeUpload', (up: PluploadUploader, file: PluploadFile) => {
                    const customParams = monitor.getUploadParams(file);

                    up.settings.multipart_params = {
                        ...up.settings.multipart_params,
                        ...customParams
                    };

                    console.log('[FP] BeforeUpload - Injected params:', customParams);

                    monitor.emitEvent('beforeUpload', {
                        file: file,
                        params: up.settings.multipart_params
                    });
                });

                this.uploader.bind('FilesAdded', (up: PluploadUploader, files: PluploadFile[]) => {
                    console.log('[FP] Files added:', files.length);
                    monitor.emitEvent('filesAdded', { files });
                });

                this.uploader.bind('FileUploaded', (up: PluploadUploader, file: PluploadFile, response: any) => {
                    console.log('[FP] File uploaded:', file.name);
                    try {
                        const data: UploadResponse = JSON.parse(response.response);
                        if (data.success && data.data) {
                            if (data.data.fp_cf_image_id) {
                                monitor.checkAndStoreCfImage(data.data);
                            }
                            monitor.handleFileUploaded(data.data);
                        }
                    } catch (e) {
                        console.error('[FP] Failed to parse upload response:', e);
                    }
                });

                this.uploader.bind('UploadComplete', (up: PluploadUploader, files: PluploadFile[]) => {
                    console.log('[FP] Upload complete:', files.length);
                    monitor.emitEvent('uploadComplete', {
                        count: files.length,
                        files: files
                    });
                });
            }

            return ret;
        };

        // Also check for existing uploader instance
        if (window.uploader) {
            console.log('[FP] Found existing uploader instance');
            this.hookExistingUploader(window.uploader);
        }
    }

    private hookExistingUploader(uploader: PluploadUploader): void {
        const monitor = this;

        // Check if already hooked
        if (uploader._fpHooked) {
            console.log('[FP] This uploader instance already hooked');
            return;
        }

        console.log('[FP] Hooking existing uploader instance');
        uploader._fpHooked = true;

        // DON'T unbind - just add our handlers
        uploader.bind('BeforeUpload', (up: PluploadUploader, file: PluploadFile) => {
            const customParams = monitor.getUploadParams(file);

            up.settings.multipart_params = {
                ...up.settings.multipart_params,
                ...customParams
            };

            console.log('[FP] BeforeUpload (existing) - Injected params:', customParams);

            monitor.emitEvent('beforeUpload', {
                file: file,
                params: up.settings.multipart_params
            });
        });

        uploader.bind('FilesAdded', (up: PluploadUploader, files: PluploadFile[]) => {
            console.log('[FP] Files added to existing uploader:', files.length);
            monitor.emitEvent('filesAdded', { files });
        });

        uploader.bind('FileUploaded', (up: PluploadUploader, file: PluploadFile, response: any) => {
            console.log('[FP] File uploaded via existing uploader:', file.name);
            try {
                const data: UploadResponse = JSON.parse(response.response);
                if (data.success && data.data) {
                    if (data.data.fp_cf_image_id) {
                        monitor.checkAndStoreCfImage(data.data);
                    }
                    monitor.handleFileUploaded(data.data);
                }
            } catch (e) {
                console.error('[FP] Failed to parse upload response:', e);
            }
        });

        uploader.bind('UploadComplete', (up: PluploadUploader, files: PluploadFile[]) => {
            console.log('[FP] Upload complete:', files.length);
            monitor.emitEvent('uploadComplete', {
                count: files.length,
                files: files
            });
        });
    }

    private setupUploaderHooksWhenReady(): void {
        const monitor = this;
        let attemptCount = 0;
        const maxAttempts = 100; // Try for 10 seconds

        const tryHook = (): boolean => {
            attemptCount++;

            // Check if wp.Uploader exists
            if (window.wp?.Uploader) {
                console.log('[FP] wp.Uploader found, hooking...');
                monitor.hookUploader();
                return true;
            }

            // Check if window.uploader exists (direct instance)
            if (window.uploader) {
                console.log('[FP] window.uploader found, hooking...');
                monitor.hookExistingUploader(window.uploader);
                monitor.existingUploaderHooked = true;
                return true;
            }

            if (attemptCount < maxAttempts) {
                setTimeout(tryHook, 100);
            } else {
                console.warn('[FP] wp.Uploader not found after 10 seconds on media-new.php');
            }

            return false;
        };

        // Start checking
        tryHook();

        // Also set up a MutationObserver for the file input
        this.observeFileInput();
    }

    private observeFileInput(): void {
        const monitor = this;

        // Watch for the plupload container to be created
        const observer = new MutationObserver((mutations) => {
            // Check if uploader now exists
            if (window.uploader && !monitor.existingUploaderHooked) {
                console.log('[FP] Uploader detected via DOM mutation');
                monitor.hookExistingUploader(window.uploader);
                monitor.existingUploaderHooked = true;
            }

            // Check if wp.Uploader now exists
            if (window.wp?.Uploader && !monitor.uploaderPrototypeHooked) {
                console.log('[FP] wp.Uploader detected via DOM mutation');
                monitor.hookUploader();
                monitor.uploaderPrototypeHooked = true;
            }
        });

        // Observe the document body for changes
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        // Stop observing after 15 seconds
        setTimeout(() => {
            observer.disconnect();
        }, 15000);
    }

    private hookAjaxResponses(): void {
        // Hook into jQuery AJAX if available
        if (window.jQuery) {
            window.jQuery(document).ajaxComplete((event: any, xhr: any, settings: any) => {
                if (settings.url && settings.url.includes('admin-ajax.php')) {
                    try {
                        const response: AjaxResponse = JSON.parse(xhr.responseText);
                        this.processAjaxResponse(response);
                    } catch (e) {
                        // Not JSON or parsing failed
                    }
                }
            });
        }

        // Also hook into native fetch
        const originalFetch = window.fetch;
        const monitor = this;

        window.fetch = async function (...args: Parameters<typeof fetch>): Promise<Response> {
            const response = await originalFetch(...args);

            // Check if it's admin-ajax
            if (args[0] && typeof args[0] === 'string' && args[0].includes('admin-ajax.php')) {
                // Clone response to read it without consuming
                const clone = response.clone();
                try {
                    const data: AjaxResponse = await clone.json();
                    monitor.processAjaxResponse(data);
                } catch (e) {
                    // Not JSON
                }
            }

            return response;
        };

        // Hook into XMLHttpRequest
        const originalOpen = XMLHttpRequest.prototype.open;
        const originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (
            this: XMLHttpRequest & { _url?: string },
            method: string,
            url: string | URL
        ) {
            this._url = url.toString();
            return originalOpen.apply(this, arguments as any);
        };

        XMLHttpRequest.prototype.send = function (this: XMLHttpRequest & { _url?: string }) {
            const xhr = this;

            if (xhr._url && xhr._url.includes('admin-ajax.php')) {
                xhr.addEventListener('load', function () {
                    try {
                        const response: AjaxResponse = JSON.parse(xhr.responseText);
                        monitor.processAjaxResponse(response);
                    } catch (e) {
                        // Not JSON
                    }
                });
            }

            return originalSend.apply(this, arguments as any);
        };
    }

    private processAjaxResponse(response: AjaxResponse): void {
        if (!response) return;

        // Check for query-attachments response
        if (response.success && response.data) {
            // Handle array of attachments
            if (Array.isArray(response.data)) {
                response.data.forEach((attachment: AttachmentData) => {
                    this.checkAndStoreCfImage(attachment);
                });
            }
            // Handle single attachment
            else if (response.data.id) {
                this.checkAndStoreCfImage(response.data);
            }
        }

        // Also check if response itself is an attachment
        if (response.id) {
            this.checkAndStoreCfImage(response as AttachmentData);
        }
    }

    private checkAndStoreCfImage(attachment: AttachmentData): void {
        if (attachment.fp_cf_image_id) {
            console.log('[FP] Found attachment with fp_cf_image_id:', attachment.id, attachment.fp_cf_image_id);

            // Store with CF image data
            this.cfImageAttachments.set(attachment.id, {
                attachmentId: attachment.id,
                cfImageId: attachment.fp_cf_image_id,
                data: attachment,
                timestamp: Date.now()
            });

            // Update main attachments map if exists
            if (this.attachments.has(attachment.id)) {
                const existing = this.attachments.get(attachment.id)!;
                existing.fp_cf_image_id = attachment.fp_cf_image_id;
                this.attachments.set(attachment.id, existing);
            } else {
                this.attachments.set(attachment.id, {
                    id: attachment.id,
                    url: attachment.url,
                    title: attachment.title,
                    fp_cf_image_id: attachment.fp_cf_image_id,
                    fromAjax: true
                });
            }

            this.emitEvent('cfImageFound', {
                attachmentId: attachment.id,
                cfImageId: attachment.fp_cf_image_id,
                attachment: attachment
            });

            // Try to find its element immediately
            this.findCfImageElement(attachment.id);
        }
    }

    private findCfImageElement(attachmentId: string | number): HTMLElement | null {
        // Try different selectors
        const selectors = [
            `[data-id="${attachmentId}"]`,
            `.attachment[data-id="${attachmentId}"]`,
            `#attachment-${attachmentId}`,
            `.attachment-${attachmentId}`
        ];

        let element: HTMLElement | null = null;
        for (const selector of selectors) {
            element = document.querySelector<HTMLElement>(selector);
            if (element) break;
        }

        if (element) {
            console.log('[FP] Found element for CF image attachment:', attachmentId);

            // Store element reference
            const cfData = this.cfImageAttachments.get(attachmentId);
            if (cfData) {
                cfData.element = element;
                this.cfImageAttachments.set(attachmentId, cfData);
            }

            this.emitEvent('cfImageElementFound', {
                attachmentId: attachmentId,
                element: element,
                cfImageId: this.cfImageAttachments.get(attachmentId)?.cfImageId
            });

            // Add a data attribute to mark it
            element.setAttribute('data-fp-cf-image-id', this.cfImageAttachments.get(attachmentId)?.cfImageId || '');

            return element;
        }

        return null;
    }

    public getCfImageAttachments(): CfImageData[] {
        return Array.from(this.cfImageAttachments.values());
    }

    public getCfImageAttachment(attachmentId: string | number): CfImageData | undefined {
        return this.cfImageAttachments.get(attachmentId);
    }

    public getCfImageAttachmentsWithElements(): Array<{
        attachmentId: string | number;
        cfImageId: string;
        element: HTMLElement;
        data: any;
    }> {
        const results: Array<{
            attachmentId: string | number;
            cfImageId: string;
            element: HTMLElement;
            data: any;
        }> = [];

        this.cfImageAttachments.forEach((data, attachmentId) => {
            // Try to find element if not already found
            if (!data.element) {
                data.element = this.findCfImageElement(attachmentId) || undefined;
            }

            if (data.element) {
                results.push({
                    attachmentId: data.attachmentId,
                    cfImageId: data.cfImageId,
                    element: data.element,
                    data: data.data
                });
            }
        });

        return results;
    }

    public refreshCfImageElements(): RefreshResult {
        console.log('[FP] Refreshing CF image elements...');

        const found: (string | number)[] = [];
        const notFound: (string | number)[] = [];

        this.cfImageAttachments.forEach((data, attachmentId) => {
            const element = this.findCfImageElement(attachmentId);
            if (element) {
                data.element = element;
                this.cfImageAttachments.set(attachmentId, data);
                found.push(attachmentId);
            } else {
                notFound.push(attachmentId);
            }
        });

        console.log('[FP] CF image elements found:', found.length, 'Not found:', notFound.length);

        return {
            found: found,
            notFound: notFound,
            total: this.cfImageAttachments.size
        };
    }

    public waitForCfImageElement(attachmentId: string | number, timeout = 5000): Promise<HTMLElement> {
        return new Promise((resolve, reject) => {
            // Check if already exists
            const existing = this.findCfImageElement(attachmentId);
            if (existing) {
                resolve(existing);
                return;
            }

            const startTime = Date.now();

            const check = (): void => {
                const element = this.findCfImageElement(attachmentId);
                if (element) {
                    resolve(element);
                } else if (Date.now() - startTime > timeout) {
                    reject(new Error(`Timeout waiting for CF image element ${attachmentId}`));
                } else {
                    setTimeout(check, 100);
                }
            };

            check();
        });
    }

    private async initForMediaLibraryPage(): Promise<void> {
        // Wait for wp.media to be available
        await this.waitForMediaComponents();

        // Hook into the uploader
        this.hookUploader();

        // For upload.php, we need to hook into the media grid differently
        this.hookMediaGrid();

        // Process existing attachments on page load
        setTimeout(() => {
            this.processExistingAttachments();
            this.observeMediaGrid();
            // Refresh CF image elements after initial load
            this.refreshCfImageElements();
        }, 500);
    }

    private async initForMediaModal(): Promise<void> {
        await this.waitForMediaComponents();
        this.hookAttachmentViews();
        this.hookUploader();
    }

    private async initForMediaNewPage(): Promise<void> {
        console.log('[FP] Initializing for media-new.php');

        // Don't wait for components, hook them when they appear
        this.setupUploaderHooksWhenReady();

        console.log('[FP] media-new.php initialization complete (uploader will be hooked when available)');
    }

    private waitForMediaComponents(timeout = 10000): Promise<void> {
        return new Promise((resolve, reject) => {
            const startTime = Date.now();

            const check = (): void => {
                // Note: media-new.php doesn't use this anymore
                if (this.isMediaLibraryPage) {
                    if (window.wp?.media && window.wp?.Uploader) {
                        resolve();
                    } else if (Date.now() - startTime > timeout) {
                        reject(new Error('Timeout waiting for media components'));
                    } else {
                        setTimeout(check, 100);
                    }
                } else {
                    if (window.wp?.media && typeof window.wp?.media === 'object' &&
                        (window.wp?.media as WordPressMedia).view?.Attachment &&
                        window.wp?.Uploader) {
                        resolve();
                    } else if (Date.now() - startTime > timeout) {
                        reject(new Error('Timeout waiting for media components'));
                    } else {
                        setTimeout(check, 100);
                    }
                }
            };

            check();
        });
    }

    private hookMediaGrid(): void {
        if (window.wp?.media && typeof window.wp?.media === 'object') {
            const wpMedia = window.wp?.media as WordPressMedia;
            if (wpMedia.view?.AttachmentsBrowser) {
                const originalBrowser = wpMedia.view.AttachmentsBrowser;
                const monitor = this;

                wpMedia.view.AttachmentsBrowser = originalBrowser.extend({
                    initialize: function (this: any) {
                        originalBrowser.prototype.initialize.apply(this, arguments);
                        console.log('[FP] AttachmentsBrowser initialized');

                        if (this.collection) {
                            monitor.hookCollection(this.collection);
                        }
                    }
                });
            }

            if (wpMedia.view?.Attachment) {
                this.hookAttachmentViews();
            }
        }
    }

    private hookCollection(collection: any): void {
        console.log('[FP] Hooking into collection');

        collection.on('add', (model: any) => {
            console.log('[FP] Model added to collection:', model.get('id'));

            // Check for fp_cf_image_id
            if (model.get('fp_cf_image_id')) {
                this.checkAndStoreCfImage({
                    id: model.get('id'),
                    fp_cf_image_id: model.get('fp_cf_image_id'),
                    url: model.get('url'),
                    title: model.get('title')
                });
            }

            this.handleLibraryAdd(model);
        });

        collection.on('reset', () => {
            console.log('[FP] Collection reset');
            this.handleLibraryReset(collection);
        });

        collection.on('sync', () => {
            console.log('[FP] Collection synced');
            this.emitEvent('collectionSynced', {
                count: collection.length
            });

            // Refresh CF image elements after sync
            setTimeout(() => {
                this.refreshCfImageElements();
            }, 500);
        });

        if (collection.models && collection.models.length > 0) {
            console.log('[FP] Processing existing models:', collection.models.length);
            this.handleLibraryReset(collection);
        }
    }

    private hookAttachmentViews(): void {
        if (!window.wp?.media || typeof window.wp?.media !== 'object') return;

        const wpMedia = window.wp?.media as WordPressMedia;
        if (!wpMedia.view?.Attachment) return;

        const originalAttachment = wpMedia.view.Attachment;
        const monitor = this;

        wpMedia.view.Attachment = originalAttachment.extend({
            initialize: function (this: any) {
                originalAttachment.prototype.initialize.apply(this, arguments);

                const attachmentData: AttachmentData = {
                    id: this.model.get('id'),
                    url: this.model.get('url'),
                    title: this.model.get('title'),
                    filename: this.model.get('filename'),
                    size: this.model.get('filesize'),
                    type: this.model.get('type'),
                    subtype: this.model.get('subtype'),
                    sizes: this.model.get('sizes'),
                    fp_cf_image_id: this.model.get('fp_cf_image_id'),
                    viewInitialized: Date.now()
                };

                monitor.attachments.set(attachmentData.id, attachmentData);

                // Check if it has fp_cf_image_id
                if (attachmentData.fp_cf_image_id) {
                    monitor.checkAndStoreCfImage(attachmentData);
                }

                monitor.emitEvent('attachmentViewCreated', attachmentData);

                this.on('ready', () => {
                    monitor.handleViewReady(this);
                });
            },

            render: function (this: any) {
                const result = originalAttachment.prototype.render.apply(this, arguments);

                setTimeout(() => {
                    monitor.trackRenderedAttachment(this);
                }, 0);

                return result;
            }
        });
    }

    private processExistingAttachments(): void {
        const attachments = document.querySelectorAll<HTMLElement>('.attachment');
        console.log(`[FP] Found ${attachments.length} existing attachments`);

        attachments.forEach(element => {
            const id = element.getAttribute('data-id');
            if (id) {
                // Check if this is a CF image attachment
                if (this.cfImageAttachments.has(id)) {
                    const cfData = this.cfImageAttachments.get(id)!;
                    cfData.element = element;
                    this.cfImageAttachments.set(id, cfData);
                    element.setAttribute('data-fp-cf-image-id', cfData.cfImageId);
                }

                const img = element.querySelector<HTMLImageElement>('img');
                if (img) {
                    if (img.complete) {
                        this.handleImageLoaded(id, img);
                    } else {
                        img.addEventListener('load', () => {
                            this.handleImageLoaded(id, img);
                        }, { once: true });
                    }
                }
            }
        });
    }

    private trackRenderedAttachment(view: any): void {
        const id = view.model.get('id');

        // Check if this is a CF image
        if (view.model.get('fp_cf_image_id')) {
            this.checkAndStoreCfImage({
                id: id,
                fp_cf_image_id: view.model.get('fp_cf_image_id'),
                url: view.model.get('url'),
                title: view.model.get('title')
            });

            // Find its element
            setTimeout(() => {
                this.findCfImageElement(id);
            }, 100);
        }

        const img: HTMLImageElement | null = view.$el?.find('img')[0] || view.el.querySelector('img');

        if (img) {
            if (img.complete) {
                this.handleImageLoaded(id, img);
            } else {
                img.addEventListener('load', () => {
                    this.handleImageLoaded(id, img);
                }, { once: true });
            }
        }
    }

    private observeMediaGrid(): void {
        const containers = [
            '.attachments-browser .attachments',
            '.media-frame-content .attachments',
            '#wp-media-grid .attachments'
        ];

        let targetNode: HTMLElement | null = null;
        for (const selector of containers) {
            targetNode = document.querySelector<HTMLElement>(selector);
            if (targetNode) break;
        }

        if (!targetNode) {
            console.log('[FP] Attachments container not found, retrying...');
            setTimeout(() => this.observeMediaGrid(), 500);
            return;
        }

        console.log('[FP] Observing attachments container:', targetNode);

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1 && (node as HTMLElement).classList?.contains('attachment')) {
                        const element = node as HTMLElement;
                        const id = element.getAttribute('data-id');
                        console.log('[FP] New attachment in DOM:', id);

                        if (id) {
                            // Check if it's a CF image attachment
                            if (this.cfImageAttachments.has(id)) {
                                const cfData = this.cfImageAttachments.get(id)!;
                                cfData.element = element;
                                this.cfImageAttachments.set(id, cfData);
                                element.setAttribute('data-fp-cf-image-id', cfData.cfImageId);

                                this.emitEvent('cfImageElementAdded', {
                                    attachmentId: id,
                                    cfImageId: cfData.cfImageId,
                                    element: element
                                });
                            }

                            this.emitEvent('attachmentAddedToDOM', {
                                attachmentId: id,
                                element: element
                            });

                            const img = element.querySelector<HTMLImageElement>('img');
                            if (img) {
                                if (img.complete) {
                                    this.handleImageLoaded(id, img);
                                } else {
                                    img.addEventListener('load', () => {
                                        this.handleImageLoaded(id, img);
                                    }, { once: true });
                                }
                            }
                        }
                    }
                });
            });
        });

        observer.observe(targetNode, {
            childList: true,
            subtree: true
        });

        this.gridObserver = observer;
    }

    private handleViewReady(view: any): void {
        const id = view.model.get('id');
        const attachment = this.attachments.get(id);

        if (attachment) {
            attachment.viewReady = Date.now();
        }

        this.trackThumbnail(view);
    }

    private trackThumbnail(view: any): void {
        const img: HTMLImageElement | null = view.el.querySelector('img');
        const id = view.model.get('id');

        if (!img) {
            this.emitEvent('thumbnailMissing', { attachmentId: id });
            return;
        }

        if (img.complete) {
            this.handleImageLoaded(id, img);
        } else {
            img.addEventListener('load', () => {
                this.handleImageLoaded(id, img);
            }, { once: true });
        }
    }

    private handleImageLoaded(id: string | number, img: HTMLImageElement): void {
        const attachment = this.attachments.get(id) || {} as AttachmentData;
        attachment.id = id;
        attachment.thumbnailLoaded = Date.now();
        attachment.thumbnailUrl = img.src;
        attachment.thumbnailWidth = img.naturalWidth;
        attachment.thumbnailHeight = img.naturalHeight;

        this.attachments.set(id, attachment);

        this.emitEvent('thumbnailLoaded', {
            attachmentId: id,
            url: img.src,
            width: img.naturalWidth,
            height: img.naturalHeight,
            attachment
        });
    }

    private handleLibraryReset(collection: any): void {
        const models = collection.models || [];
        console.log(`[FP] Library reset with ${models.length} items`);

        const attachments = models.map((model: any) => ({
            id: model.get('id'),
            title: model.get('title'),
            url: model.get('url'),
            sizes: model.get('sizes'),
            fp_cf_image_id: model.get('fp_cf_image_id')
        }));

        // Check for CF images
        attachments.forEach((att: AttachmentData) => {
            if (att.fp_cf_image_id) {
                this.checkAndStoreCfImage(att);
            }
        });

        this.emitEvent('libraryReset', {
            count: models.length,
            attachments
        });

        models.forEach((model: any) => {
            this.processModel(model);
        });
    }

    private handleLibraryAdd(model: any): void {
        const data: AttachmentData = {
            id: model.get('id'),
            title: model.get('title'),
            url: model.get('url'),
            fp_cf_image_id: model.get('fp_cf_image_id')
        };

        if (data.fp_cf_image_id) {
            this.checkAndStoreCfImage(data);
        }

        this.emitEvent('libraryItemAdded', data);
        this.processModel(model);
    }

    private handleFileUploaded(data: AttachmentData & { id: string | number }): void {
        const attachment: AttachmentData = {
            id: data.id,
            url: data.url,
            filename: data.filename,
            uploaded: Date.now(),
            sizes: data.sizes,
            fp_cf_image_id: data.fp_cf_image_id
        };

        this.attachments.set(data.id, attachment);
        this.emitEvent('fileUploaded', attachment);
    }

    private processModel(model: any): void {
        const id = model.get('id');
        if (!this.attachments.has(id)) {
            this.attachments.set(id, {
                id,
                url: model.get('url'),
                title: model.get('title'),
                sizes: model.get('sizes'),
                fp_cf_image_id: model.get('fp_cf_image_id'),
                fromLibrary: true
            });
        }
    }

    private emitEvent(eventName: string, detail: Partial<EventDetail>): void {
        const event = new CustomEvent(`fpMediaLibrary:${eventName}`, {
            detail: {
                ...detail,
                timestamp: Date.now()
            }
        });
        window.dispatchEvent(event);

        if (this.debug) {
            console.log(`[FP MediaLibrary] ${eventName}:`, detail);
        }
    }

    public getAttachment(id: string | number): AttachmentData | undefined {
        return this.attachments.get(id);
    }

    public getAllAttachments(): AttachmentData[] {
        return Array.from(this.attachments.values());
    }

    public enableDebug(): void {
        this.debug = true;
    }

    public disableDebug(): void {
        this.debug = false;
    }

    public getStats(): StatsResult {
        return {
            totalAttachments: this.attachments.size,
            cfImageAttachments: this.cfImageAttachments.size,
            uploadParams: this.uploadParams,
            uploadCallbacks: this.uploadParamsCallbacks.length,
            isMediaLibraryPage: this.isMediaLibraryPage,
            isMediaNewPage: this.isMediaNewPage,
            initialized: this.initialized
        };
    }
}

// Helper functions