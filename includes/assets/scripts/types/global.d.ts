// Global type augmentation
import {PluploadUploader, WordPressGlobal} from "./types";

export {};

declare global {
    interface Window {
        wp?: WordPressGlobal;
        uploader?: PluploadUploader;
        fp_upload_to_cf?: number;
        jQuery?: any;
        fetch: typeof fetch;
    }
}