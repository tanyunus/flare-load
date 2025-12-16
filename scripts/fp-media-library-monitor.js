class FpMediaLibraryMonitor {
    constructor() {
        this.attachments = new Map();
        this.pendingThumbnails = new Map();
        this.initialized = false;
        this.isMediaLibraryPage = window.location.pathname.includes('upload.php');
        this.ready = this.init();
    }

    async init() {
        try {
            console.log('[FP] Initializing on:', window.location.pathname);

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
                // For upload.php, we need different components
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
        // Hook into the media grid browser if available
        if (window.wp?.media?.view?.AttachmentsBrowser) {
            const originalBrowser = window.wp.media.view.AttachmentsBrowser;
            const monitor = this;

            window.wp.media.view.AttachmentsBrowser = originalBrowser.extend({
                initialize: function() {
                    originalBrowser.prototype.initialize.apply(this, arguments);
                    console.log('[FP] AttachmentsBrowser initialized');

                    // Listen to collection events
                    if (this.collection) {
                        monitor.hookCollection(this.collection);
                    }
                }
            });
        }

        // Also hook into Attachment views if they exist
        if (window.wp?.media?.view?.Attachment) {
            this.hookAttachmentViews();
        }
    }

    hookCollection(collection) {
        console.log('[FP] Hooking into collection');

        collection.on('add', (model) => {
            console.log('[FP] Model added to collection:', model.get('id'));
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
        });

        // Process existing models
        if (collection.models && collection.models.length > 0) {
            console.log('[FP] Processing existing models:', collection.models.length);
            this.handleLibraryReset(collection);
        }
    }

    hookAttachmentViews() {
        const originalAttachment = window.wp.media.view.Attachment;
        const monitor = this;

        window.wp.media.view.Attachment = originalAttachment.extend({
            initialize: function() {
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
                    viewInitialized: Date.now()
                };

                monitor.attachments.set(attachmentData.id, attachmentData);
                monitor.emitEvent('attachmentViewCreated', attachmentData);

                this.on('ready', () => {
                    monitor.handleViewReady(this);
                });
            },

            render: function() {
                const result = originalAttachment.prototype.render.apply(this, arguments);

                // Additional tracking after render
                setTimeout(() => {
                    monitor.trackRenderedAttachment(this);
                }, 0);

                return result;
            }
        });
    }

    trackRenderedAttachment(view) {
        const id = view.model.get('id');
        const img = view.$el.find('img')[0] || view.el.querySelector('img');

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

    observeMediaGrid() {
        // Find the attachments container
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

                        this.emitEvent('attachmentAddedToDOM', {
                            attachmentId: id,
                            element: node
                        });

                        // Track thumbnail
                        const img = node.querySelector('img');
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
            });
        });

        observer.observe(targetNode, {
            childList: true,
            subtree: true
        });

        this.gridObserver = observer;
    }

    hookUploader() {
        if (!window.wp?.Uploader) {
            console.log('[FP] wp.Uploader not available');
            return;
        }

        const originalInit = window.wp.Uploader.prototype.init;
        const monitor = this;

        window.wp.Uploader.prototype.init = function() {
            const ret = originalInit.apply(this, arguments);

            console.log('[FP] Uploader initialized');

            this.uploader.bind('Init', (up) => {
                console.log('[FP] Plupload initialized');
            });

            this.uploader.bind('FilesAdded', (up, files) => {
                console.log('[FP] Files added:', files.length);
                monitor.emitEvent('filesAdded', { files });
            });

            this.uploader.bind('FileUploaded', (up, file, response) => {
                console.log('[FP] File uploaded:', file.name);
                try {
                    const data = JSON.parse(response.response);
                    if (data.success && data.data) {
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
            });

            return ret;
        };

        // Check if there's already an uploader instance on the page
        if (window.uploader) {
            console.log('[FP] Found existing uploader instance');
            this.hookExistingUploader(window.uploader);
        }
    }

    hookExistingUploader(uploader) {
        const monitor = this;

        uploader.bind('FilesAdded', (up, files) => {
            console.log('[FP] Files added to existing uploader:', files.length);
            monitor.emitEvent('filesAdded', { files });
        });

        uploader.bind('FileUploaded', (up, file, response) => {
            console.log('[FP] File uploaded via existing uploader:', file.name);
            try {
                const data = JSON.parse(response.response);
                if (data.success && data.data) {
                    monitor.handleFileUploaded(data.data);
                }
            } catch (e) {
                console.error('[FP] Failed to parse upload response:', e);
            }
        });
    }

    processExistingAttachments() {
        const attachments = document.querySelectorAll('.attachment');
        console.log(`[FP] Found ${attachments.length} existing attachments`);

        attachments.forEach(element => {
            const id = element.getAttribute('data-id');
            if (id) {
                const img = element.querySelector('img');
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

    handleLibraryReset(collection) {
        const models = collection.models || [];
        console.log(`[FP] Library reset with ${models.length} items`);

        const attachments = models.map(model => ({
            id: model.get('id'),
            title: model.get('title'),
            url: model.get('url'),
            sizes: model.get('sizes')
        }));

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
            url: model.get('url')
        };

        this.emitEvent('libraryItemAdded', data);
        this.processModel(model);
    }

    handleFileUploaded(data) {
        const attachment = {
            id: data.id,
            url: data.url,
            filename: data.filename,
            uploaded: Date.now(),
            sizes: data.sizes
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

    // Utility methods remain the same...
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
            isMediaLibraryPage: this.isMediaLibraryPage,
            initialized: this.initialized
        };
    }
}

window.fp.mediaLibraryMonitor = new FpMediaLibraryMonitor();