import { __ } from '@wordpress/i18n';
import UploadManager from "../modules/UploadManager";
import {createAndAppendSwitcher} from "../functions/media-library-pages";
import {detectAndMarkCfImages} from "../functions/cf-image-detector";

declare const wp: any;

let fpLocationFilter = '';

function registerLocationAjaxPrefilter(): void {
    (window as any).jQuery?.ajaxPrefilter?.((options: any) => {
        if (!options?.data || options.data.action !== 'query-attachments') return;
        if (fpLocationFilter) {
            options.data.query = {...(options.data.query ?? {}), flareload_location: fpLocationFilter};
        } else if (options.data.query) {
            delete options.data.query.flareload_location;
        }
    });
}

function addLocationFilter(): void {
    if (!wp?.media?.view?.AttachmentsBrowser) return;

    const labels          = (window as any).flarepConfig?.locationFilterLabels ?? {};
    const allLabel        = (labels.all        as string) || __('All locations', 'flare-load');
    const cloudflareLabel = (labels.cloudflare as string) || __('Uploaded to Cloudflare', 'flare-load');
    const serverLabel     = (labels.server     as string) || __('This server', 'flare-load');

    const originalCreateToolbar = wp.media.view.AttachmentsBrowser.prototype.createToolbar;

    wp.media.view.AttachmentsBrowser.prototype.createToolbar = function () {
        originalCreateToolbar.apply(this, arguments);

        const browser = this;

        const LocationFilter = wp.media.view.AttachmentFilters.extend({
            id: 'flarep-location-filter',

            createFilters() {
                this.filters = {
                    all:        { text: allLabel,        props: { flareload_location: '' },           priority: 10 },
                    cloudflare: { text: cloudflareLabel, props: { flareload_location: 'cloudflare' }, priority: 20 },
                    server:     { text: serverLabel,     props: { flareload_location: 'server' },     priority: 30 },
                };
            },

            change() {
                const filter = this.filters[this.el.value];
                if (!filter) return;

                fpLocationFilter = filter.props.flareload_location || '';
                this.model.set({ flareload_location: fpLocationFilter, ignore: +new Date() });
            },
        });

        this.toolbar.set('flarep-location-filter', new LocationFilter({
            controller: this.controller,
            model:      this.collection.props,
            priority:   -75,
        }));
    };
}

function initPage(): void {
    const append =
        document.querySelector<HTMLElement>('.page-title-action') ??
        document.querySelector<HTMLElement>('.wrap h1');

    if (!append) return;

    const uploadManager = new UploadManager();

    createAndAppendSwitcher(uploadManager, append);

    uploadManager.hookUploader();
    uploadManager.hookRestApiUpload();
    uploadManager.waitForUploader(() => {
        uploadManager.hookExistingUploader();
    });

    uploadManager.watchMediaAttachments();
    uploadManager.listenHeartbeatForErrors();

    detectAndMarkCfImages();
}

registerLocationAjaxPrefilter();
addLocationFilter();

document.addEventListener('DOMContentLoaded', () => {
    initPage();
});
