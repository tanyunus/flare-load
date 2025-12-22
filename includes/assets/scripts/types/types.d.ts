// Type definitions for WordPress media library

export interface WordPressMedia {
    view?: {
        Attachment?: any;
        AttachmentsBrowser?: any;
    };
    Uploader?: any;
}

export interface WordPressGlobal {
    media?: WordPressMedia | ((options?: any) => any);
    Uploader?: any;
}

export interface PluploadFile {
    id: string;
    name: string;
    size: number;
    type: string;
}

export interface PluploadUploader {
    bind: (event: string, callback: (...args: any[]) => void) => void;
    unbind?: (event: string) => void;
    settings: {
        multipart_params: Record<string, any>;
    };
    _fpHooked?: boolean;
}

export interface WordPressUploaderInstance {
    uploader: PluploadUploader;
    _fpHooked?: boolean;
    bind: (event: string, callback: (...args: any[]) => void) => void;
}

export interface AttachmentData {
    id: string | number;
    url?: string;
    title?: string;
    filename?: string;
    size?: number;
    type?: string;
    subtype?: string;
    sizes?: any;
    fp_cf_image_id?: string;
    viewInitialized?: number;
    viewReady?: number;
    thumbnailLoaded?: number;
    thumbnailUrl?: string;
    thumbnailWidth?: number;
    thumbnailHeight?: number;
    uploaded?: number;
    fromAjax?: boolean;
    fromLibrary?: boolean;
}

export interface CfImageData {
    attachmentId: string | number;
    cfImageId: string;
    data: any;
    timestamp: number;
    element?: HTMLElement;
}

export interface UploadParams {
    [key: string]: any;
}

export type DynamicParamsCallback = (file: PluploadFile) => Record<string, any> | null | undefined;

export interface UploadResponse {
    success: boolean;
    data?: AttachmentData & {
        id: string | number;
    };
}

export interface AjaxResponse {
    success?: boolean;
    data?: AttachmentData | AttachmentData[] | any;
    id?: string | number;
}

export interface EventDetail {
    [key: string]: any;
    timestamp: number;
}

export interface RefreshResult {
    found: (string | number)[];
    notFound: (string | number)[];
    total: number;
}

export interface StatsResult {
    totalAttachments: number;
    cfImageAttachments: number;
    uploadParams: UploadParams;
    uploadCallbacks: number;
    isMediaLibraryPage: boolean;
    isMediaNewPage: boolean;
    initialized: boolean;
}

// Type definitions
export interface SyncVariantsResponse {
    data: string; // JSON string that needs to be parsed
}

export interface WpNonceResponse {
    text(): Promise<string>;
}

declare global {
    interface Window {
        wp?: WordPressGlobal;
        uploader?: PluploadUploader;
        fp_upload_to_cf?: number;
        jQuery?: any;
        fetch: typeof fetch;
    }
}