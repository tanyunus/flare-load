// Media library monitor
class FpMediaLibraryMonitor {
    constructor() {
        this.attachments = new Map();
        this.pendingThumbnails = new Map();
        this.cfImageAttachments = new Map();
        this.uploadParams = {}; // Store custom upload parameters
        this.uploadParamsCallbacks = []; // Store callbacks for dynamic params
        this.initialized = false;
        this.isMediaLibraryPage = window.location.pathname.includes('upload.php');
        this.ready = this.init();
    }

    async init() {
        try {
            console.log('[FP] Initializing on:', window.location.pathname);

            // Hook into AJAX responses to catch fp_cf_image_id
            this.hookAjaxResponses();

            // Different initialization for media library page vs modal
            if (this.isMediaLibraryPage) {
                await this.initForMediaLibraryPage();
            } else {
                await this.initForMediaModal();
            }

            this.initialized = true;
            console.log('[FP] MediaLibraryMonitor initialized');
            return true;
        } catch (error) {
            console.error('[FP] Failed to initialize MediaLibraryMonitor:', error);
            return false;
        }
    }

    // New method to modify upload form data
    modifyUploadFormData(customParams = {}, dynamicParamsCallback = null) {
        // Store static params
        this.uploadParams = {...this.uploadParams, ...customParams};

        // Store dynamic params callback if provided
        if (dynamicParamsCallback && typeof dynamicParamsCallback === 'function') {
            this.uploadParamsCallbacks.push(dynamicParamsCallback);
        }

        console.log('[FP] Upload params updated:', this.uploadParams);
    }

    // New method to set upload params based on an element
    setUploadParamsFromElement(element, paramName, valueExtractor = null) {
        if (!element) {
            console.error('[FP] Element not provided for upload params');
            return;
        }

        // Default value extractor for checkboxes/inputs
        if (!valueExtractor) {
            valueExtractor = (el) => {
                if (el) {
                    if (el.type === 'checkbox') {
                        return +el.checked; // Convert to 0 or 1
                    }
                    return el.value;
                }
                return el.value || el.textContent;
            };
        }

        // Add a dynamic callback that reads from element
        this.uploadParamsCallbacks.push((file) => {
            return {
                [paramName]: valueExtractor(element)
            };
        });

        console.log('[FP] Added dynamic upload param from element:', paramName);
    }

    // Method to get all upload params for a file
    getUploadParams(file) {
        let params = {...this.uploadParams};

        // Apply all dynamic callbacks
        this.uploadParamsCallbacks.forEach(callback => {
            try {
                const dynamicParams = callback(file);
                if (dynamicParams) {
                    params = {...params, ...dynamicParams};
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

        return params;
    }

    // Clear all custom upload parameters
    clearUploadParams() {
        this.uploadParams = {};
        this.uploadParamsCallbacks = [];
        console.log('[FP] Upload params cleared');
    }

    hookUploader() {
        if (!window.wp?.Uploader) {
            console.log('[FP] wp.Uploader not available');
            return;
        }

        const originalInit = window.wp.Uploader.prototype.init;
        const monitor = this;

        window.wp.Uploader.prototype.init = function () {
            const ret = originalInit.apply(this, arguments);

            console.log('[FP] Uploader initialized');

            this.uploader.bind('Init', (up) => {
                console.log('[FP] Plupload initialized');
            });

            // Add BeforeUpload to inject custom params
            this.uploader.bind('BeforeUpload', (up, file) => {
                // Get all params for this file
                const customParams = monitor.getUploadParams(file);

                // Merge with existing params
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

            this.uploader.bind('FilesAdded', (up, files) => {
                console.log('[FP] Files added:', files.length);
                monitor.emitEvent('filesAdded', {files});
            });

            this.uploader.bind('FileUploaded', (up, file, response) => {
                console.log('[FP] File uploaded:', file.name);
                try {
                    const data = JSON.parse(response.response);
                    if (data.success && data.data) {
                        // Check for fp_cf_image_id in upload response
                        if (data.data.fp_cf_image_id) {
                            monitor.checkAndStoreCfImage(data.data);
                        }
                        monitor.handleFileUploaded(data.data);
                    }
                } catch (e) {
                    console.error('[FP] Failed to parse upload response:', e);
                }
            });

            this.uploader.bind('UploadComplete', (up, files) => {
                console.log('[FP] Upload complete:', files.length);
                monitor.emitEvent('uploadComplete', {
                    count: files.length,
                    files: files
                });

                // Refresh CF image elements after upload
                setTimeout(() => {
                    monitor.refreshCfImageElements();
                }, 1000);
            });

            return ret;
        };

        if (window.uploader) {
            console.log('[FP] Found existing uploader instance');
            this.hookExistingUploader(window.uploader);
        }
    }

    hookExistingUploader(uploader) {
        const monitor = this;

        uploader.bind('BeforeUpload', (up, file) => {
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

        uploader.bind('FilesAdded', (up, files) => {
            console.log('[FP] Files added to existing uploader:', files.length);
            monitor.emitEvent('filesAdded', {files});
        });

        uploader.bind('FileUploaded', (up, file, response) => {
            console.log('[FP] File uploaded via existing uploader:', file.name);
            try {
                const data = JSON.parse(response.response);
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
    }

    // Convenience method to wait for uploader (similar to your waitForUploader)
    waitForUploader(timeout = 10000) {
        return new Promise((resolve, reject) => {
            const startTime = Date.now();

            const check = () => {
                if (window.wp?.Uploader) {
                    resolve(window.wp.Uploader);
                } else if (Date.now() - startTime > timeout) {
                    reject(new Error('Timeout waiting for wp.Uploader'));
                } else {
                    setTimeout(check, 100);
                }
            };

            check();
        });
    }

    // ... rest of the methods remain the same ...

    hookAjaxResponses() {
        // Hook into jQuery AJAX if available
        if (window.jQuery) {
            jQuery(document).ajaxComplete((event, xhr, settings) => {
                if (settings.url && settings.url.includes('admin-ajax.php')) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        this.processAjaxResponse(response);
                    } catch (e) {
                        // Not JSON or parsing failed
                    }
                }
            });
        }

        // Also hook into native fetch
        const originalFetch = window.fetch;
        window.fetch = async (...args) => {
            const response = await originalFetch(...args);

            // Check if it's admin-ajax
            if (args[0] && typeof args[0] === 'string' && args[0].includes('admin-ajax.php')) {
                // Clone response to read it without consuming
                const clone = response.clone();
                try {
                    const data = await clone.json();
                    this.processAjaxResponse(data);
                } catch (e) {
                    // Not JSON
                }
            }

            return response;
        };

        // Hook into XMLHttpRequest
        const originalOpen = XMLHttpRequest.prototype.open;
        const originalSend = XMLHttpRequest.prototype.send;
        const monitor = this;

        XMLHttpRequest.prototype.open = function (method, url) {
            this._url = url;
            return originalOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function () {
            const xhr = this;

            if (xhr._url && xhr._url.includes('admin-ajax.php')) {
                xhr.addEventListener('load', function () {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        monitor.processAjaxResponse(response);
                    } catch (e) {
                        // Not JSON
                    }
                });
            }

            return originalSend.apply(this, arguments);
        };
    }

    processAjaxResponse(response) {
        if (!response) return;

        // Check for query-attachments response
        if (response.success && response.data) {
            // Handle array of attachments
            if (Array.isArray(response.data)) {
                response.data.forEach(attachment => {
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
            this.checkAndStoreCfImage(response);
        }
    }

    checkAndStoreCfImage(attachment) {
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
                const existing = this.attachments.get(attachment.id);
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

    findCfImageElement(attachmentId) {
        // Try different selectors
        const selectors = [
            `[data-id="${attachmentId}"]`,
            `.attachment[data-id="${attachmentId}"]`,
            `#attachment-${attachmentId}`,
            `.attachment-${attachmentId}`
        ];

        let element = null;
        for (const selector of selectors) {
            element = document.querySelector(selector);
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
            element.setAttribute('data-fp-cf-image-id', this.cfImageAttachments.get(attachmentId)?.cfImageId);

            return element;
        }

        return null;
    }

    getCfImageAttachments() {
        return Array.from(this.cfImageAttachments.values());
    }

    getCfImageAttachment(attachmentId) {
        return this.cfImageAttachments.get(attachmentId);
    }

    getCfImageAttachmentsWithElements() {
        const results = [];

        this.cfImageAttachments.forEach((data, attachmentId) => {
            // Try to find element if not already found
            if (!data.element) {
                data.element = this.findCfImageElement(attachmentId);
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

    refreshCfImageElements() {
        console.log('[FP] Refreshing CF image elements...');

        const found = [];
        const notFound = [];

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

    waitForCfImageElement(attachmentId, timeout = 5000) {
        return new Promise((resolve, reject) => {
            // Check if already exists
            const existing = this.findCfImageElement(attachmentId);
            if (existing) {
                resolve(existing);
                return;
            }

            const startTime = Date.now();

            const check = () => {
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

    async initForMediaLibraryPage() {
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

    async initForMediaModal() {
        await this.waitForMediaComponents();
        this.hookAttachmentViews();
        this.hookMediaFrames();
        this.hookUploader();
    }

    waitForMediaComponents(timeout = 10000) {
        return new Promise((resolve, reject) => {
            const startTime = Date.now();

            const check = () => {
                if (this.isMediaLibraryPage) {
                    if (window.wp?.media && window.wp?.Uploader) {
                        resolve();
                    } else if (Date.now() - startTime > timeout) {
                        reject(new Error('Timeout waiting for media components'));
                    } else {
                        setTimeout(check, 100);
                    }
                } else {
                    if (window.wp?.media?.view?.Attachment && window.wp?.Uploader) {
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

    hookMediaGrid() {
        if (window.wp?.media?.view?.AttachmentsBrowser) {
            const originalBrowser = window.wp.media.view.AttachmentsBrowser;
            const monitor = this;

            window.wp.media.view.AttachmentsBrowser = originalBrowser.extend({
                initialize: function () {
                    originalBrowser.prototype.initialize.apply(this, arguments);
                    console.log('[FP] AttachmentsBrowser initialized');

                    if (this.collection) {
                        monitor.hookCollection(this.collection);
                    }
                }
            });
        }

        if (window.wp?.media?.view?.Attachment) {
            this.hookAttachmentViews();
        }
    }

    hookCollection(collection) {
        console.log('[FP] Hooking into collection');

        collection.on('add', (model) => {
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

    hookAttachmentViews() {
        const originalAttachment = window.wp.media.view.Attachment;
        const monitor = this;

        window.wp.media.view.Attachment = originalAttachment.extend({
            initialize: function () {
                originalAttachment.prototype.initialize.apply(this, arguments);

                const attachmentData = {
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

            render: function () {
                const result = originalAttachment.prototype.render.apply(this, arguments);

                setTimeout(() => {
                    monitor.trackRenderedAttachment(this);
                }, 0);

                return result;
            }
        });
    }

    processExistingAttachments() {
        const attachments = document.querySelectorAll('.attachment');
        console.log(`[FP] Found ${attachments.length} existing attachments`);

        attachments.forEach(element => {
            const id = element.getAttribute('data-id');
            if (id) {
                // Check if this is a CF image attachment
                if (this.cfImageAttachments.has(id)) {
                    const cfData = this.cfImageAttachments.get(id);
                    cfData.element = element;
                    this.cfImageAttachments.set(id, cfData);
                    element.setAttribute('data-fp-cf-image-id', cfData.cfImageId);
                }

                const img = element.querySelector('img');
                if (img) {
                    if (img.complete) {
                        this.handleImageLoaded(id, img);
                    } else {
                        img.addEventListener('load', () => {
                            this.handleImageLoaded(id, img);
                        }, {once: true});
                    }
                }
            }
        });
    }

    trackRenderedAttachment(view) {
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

        const img = view.$el.find('img')[0] || view.el.querySelector('img');

        if (img) {
            if (img.complete) {
                this.handleImageLoaded(id, img);
            } else {
                img.addEventListener('load', () => {
                    this.handleImageLoaded(id, img);
                }, {once: true});
            }
        }
    }

    observeMediaGrid() {
        const containers = [
            '.attachments-browser .attachments',
            '.media-frame-content .attachments',
            '#wp-media-grid .attachments'
        ];

        let targetNode = null;
        for (const selector of containers) {
            targetNode = document.querySelector(selector);
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
                    if (node.nodeType === 1 && node.classList?.contains('attachment')) {
                        const id = node.getAttribute('data-id');
                        console.log('[FP] New attachment in DOM:', id);

                        // Check if it's a CF image attachment
                        if (this.cfImageAttachments.has(id)) {
                            const cfData = this.cfImageAttachments.get(id);
                            cfData.element = node;
                            this.cfImageAttachments.set(id, cfData);
                            node.setAttribute('data-fp-cf-image-id', cfData.cfImageId);

                            this.emitEvent('cfImageElementAdded', {
                                attachmentId: id,
                                cfImageId: cfData.cfImageId,
                                element: node
                            });
                        }

                        this.emitEvent('attachmentAddedToDOM', {
                            attachmentId: id,
                            element: node
                        });

                        const img = node.querySelector('img');
                        if (img) {
                            if (img.complete) {
                                this.handleImageLoaded(id, img);
                            } else {
                                img.addEventListener('load', () => {
                                    this.handleImageLoaded(id, img);
                                }, {once: true});
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

    handleViewReady(view) {
        const id = view.model.get('id');
        const attachment = this.attachments.get(id);

        if (attachment) {
            attachment.viewReady = Date.now();
        }

        this.trackThumbnail(view);
    }

    trackThumbnail(view) {
        const img = view.el.querySelector('img');
        const id = view.model.get('id');

        if (!img) {
            this.emitEvent('thumbnailMissing', {attachmentId: id});
            return;
        }

        if (img.complete) {
            this.handleImageLoaded(id, img);
        } else {
            img.addEventListener('load', () => {
                this.handleImageLoaded(id, img);
            }, {once: true});
        }
    }

    handleImageLoaded(id, img) {
        const attachment = this.attachments.get(id) || {};
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

    handleLibraryReset(collection) {
        const models = collection.models || [];
        console.log(`[FP] Library reset with ${models.length} items`);

        const attachments = models.map(model => ({
            id: model.get('id'),
            title: model.get('title'),
            url: model.get('url'),
            sizes: model.get('sizes'),
            fp_cf_image_id: model.get('fp_cf_image_id')
        }));

        // Check for CF images
        attachments.forEach(att => {
            if (att.fp_cf_image_id) {
                this.checkAndStoreCfImage(att);
            }
        });

        this.emitEvent('libraryReset', {
            count: models.length,
            attachments
        });

        models.forEach(model => {
            this.processModel(model);
        });
    }

    handleLibraryAdd(model) {
        const data = {
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

    handleFileUploaded(data) {
        const attachment = {
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

    processModel(model) {
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

    emitEvent(eventName, detail) {
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

    getAttachment(id) {
        return this.attachments.get(id);
    }

    getAllAttachments() {
        return Array.from(this.attachments.values());
    }

    enableDebug() {
        this.debug = true;
    }

    disableDebug() {
        this.debug = false;
    }

    getStats() {
        return {
            totalAttachments: this.attachments.size,
            cfImageAttachments: this.cfImageAttachments.size,
            uploadParams: this.uploadParams,
            uploadCallbacks: this.uploadParamsCallbacks.length,
            isMediaLibraryPage: this.isMediaLibraryPage,
            initialized: this.initialized
        };
    }
}

// Add cf badge function
addCfBadge = function (cfImageElements) {
    cfImageElements.forEach(cfImageElement => {
        cfImageElement.element.classList.add('fp-cf-badge');
    });
}

// Upload form data modifier
modifyUploadFormData = function (mediaLibraryMonitor) {
    const uploadSwitcher = document.querySelector('#fp_upload_switcher');

    if (!uploadSwitcher) {
        return;
    }

    uploadSwitcher.addEventListener('change', () => {
        mediaLibraryMonitor.setUploadParamsFromElement(
            uploadSwitcher,
            'fp_upload_to_cf'
        );
    });
}

// Upload switcher element creator function
createUploadSwitcherElement = function () {
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

// Append upload switcher element right side of
// add media button in media library page (upload.php)
appendSwitcherToSideOfAddMediaButton = function (uploadSwitcherElement) {
    const addMediaFileButton = document.querySelector(`#wp-media-grid > a.page-title-action`);

    if (!addMediaFileButton) {
        return false;
    }

    addMediaFileButton.after(uploadSwitcherElement);

    return true;
}

handleUploadSwitcherElement = function() {
    const uploadSwitcherElement = createUploadSwitcherElement();

    return appendSwitcherToSideOfAddMediaButton(uploadSwitcherElement);
}

// Listen dom load
document.addEventListener('DOMContentLoaded', () => {
    const mediaLibraryMonitor = new FpMediaLibraryMonitor();
    mediaLibraryMonitor.ready.then(async () => {
        if(handleUploadSwitcherElement()) {
            modifyUploadFormData(mediaLibraryMonitor);
        }

        window.addEventListener('fpMediaLibrary:cfImageElementFound', () => {
            addCfBadge(mediaLibraryMonitor.getCfImageAttachmentsWithElements());
        });

        window.addEventListener('fpMediaLibrary:cfImageElementAdded', () => {
            addCfBadge(mediaLibraryMonitor.getCfImageAttachmentsWithElements());
        })
    });
});