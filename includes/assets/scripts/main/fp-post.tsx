import {addFilter} from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import {createHigherOrderComponent} from '@wordpress/compose';
import {InspectorControls} from '@wordpress/block-editor';
import {PanelBody, SelectControl, Spinner} from '@wordpress/components';
import {BlockEditProps} from '@wordpress/blocks';
import {ComponentType, useEffect, useState} from '@wordpress/element';
import {useSelect, useDispatch} from '@wordpress/data';
import {store as blockEditorStore} from '@wordpress/block-editor';
import RestApi from "../modules/RestApi";
import {GetAccountHashResponse, GetVariantNamesResponse} from "../types/types";
import {store as coreStore} from '@wordpress/core-data';
import React from "react";
import UploadManager from "../modules/UploadManager";
import {appendSwitcherToMediaModal} from "../functions/media-modal";
import {detectAndMarkCfImages} from "../functions/cf-image-detector";
import {addSwitcherToImageBlock} from "../functions/image-block";

interface ImageBlockAttributes {
    cloudflareVariant?: string;
    fp_cf_image_id: string;

    [key: string]: any;
}

interface BlockSettings {
    attributes?: Record<string, any>;

    [key: string]: any;
}

interface ExtendedBlockEditProps<T extends Record<string, any> = Record<string, any>> extends BlockEditProps<T> {
    name: string;
}

function addCustomAttribute(settings: BlockSettings, name: string): BlockSettings {
    if (name !== 'core/image' && name !== 'core/media-text') {
        return settings;
    }

    return {
        ...settings,
        attributes: {
            ...settings.attributes,
            cloudflareVariant: {
                type: 'string',
                default: ''
            }
        }
    };
}

addFilter(
    'blocks.registerBlockType',
    'flare-press/add-custom-attribute',
    addCustomAttribute
);

function ImageVariantPanel({
    BlockEdit,
    props
}: {
    BlockEdit: ComponentType<ExtendedBlockEditProps<ImageBlockAttributes>>;
    props: ExtendedBlockEditProps<ImageBlockAttributes>;
}) {
    const {attributes, setAttributes} = props;
    const [variants, setVariants] = useState<string[]>([]);
    const [loading, setLoading] = useState(true);
    const [originalUrl, setOriginalUrl] = useState<string>('');

    const media = useSelect(
        (select) => {
            if (!attributes.id) return null;
            const {getMedia} = select(coreStore) as any;
            return getMedia(attributes.id);
        },
        [attributes.id]
    );

    const cfImageId = media?.fp_cf_image_id;

    useEffect(() => {
        if (attributes.url && !originalUrl) {
            setOriginalUrl(attributes.url);
        }
    }, [attributes.url]);

    useEffect(() => {
        if (cfImageId) {
            getVariantNames().then(result => {
                if (result) setVariants(result);
                setLoading(false);
            });
        } else {
            setLoading(false);
        }
    }, [cfImageId]);

    useEffect(() => {
        if (cfImageId && attributes.cloudflareVariant && originalUrl) {
            getAccountHash().then(result => {
                const variantUrl = `https://imagedelivery.net/${result}/${cfImageId}/${attributes.cloudflareVariant}`;
                setAttributes({url: variantUrl});
            });
        }
    }, [attributes.cloudflareVariant, cfImageId]);

    if (!cfImageId) {
        return <BlockEdit {...props} />;
    }

    const options = variants.map(variant => ({label: variant, value: variant}));

    return (
        <>
            <BlockEdit {...props} />
            <InspectorControls>
                <PanelBody title={__('Cloudflare Variants', 'flare-press')} initialOpen={true}>
                    {loading ? (
                        <Spinner/>
                    ) : (
                        <SelectControl
                            label={__('Choose a variant', 'flare-press')}
                            value={attributes.cloudflareVariant || ''}
                            options={[
                                {label: __('Select a variant...', 'flare-press'), value: ''},
                                ...options
                            ]}
                            onChange={(value: string) => setAttributes({cloudflareVariant: value})}
                        />
                    )}
                </PanelBody>
            </InspectorControls>
        </>
    );
}

function GalleryVariantPanel({
    BlockEdit,
    props
}: {
    BlockEdit: ComponentType<ExtendedBlockEditProps<any>>;
    props: ExtendedBlockEditProps<any>;
}) {
    const {clientId} = props;
    const [variants, setVariants] = useState<string[]>([]);
    const [loading, setLoading] = useState(true);

    const {updateBlockAttributes} = useDispatch(blockEditorStore) as any;

    const innerBlocks = useSelect(
        (select) => (select(blockEditorStore) as any).getBlocks(clientId),
        [clientId]
    );

    const imageIds: number[] = innerBlocks
        .filter((b: any) => b.name === 'core/image' && b.attributes?.id)
        .map((b: any) => b.attributes.id);

    const mediaItems = useSelect(
        (select) => {
            const {getMedia} = select(coreStore) as any;
            return imageIds.map((id: number) => getMedia(id));
        },
        [imageIds.join(',')]
    );

    const hasCfImages = mediaItems.some((m: any) => m?.fp_cf_image_id);

    useEffect(() => {
        if (!hasCfImages) return;
        getVariantNames().then(result => {
            if (result) setVariants(result);
            setLoading(false);
        });
    }, [hasCfImages]);

    if (!hasCfImages) {
        return <BlockEdit {...props} />;
    }

    const applyVariantToAll = (variant: string) => {
        innerBlocks.forEach((block: any) => {
            if (block.name === 'core/image') {
                updateBlockAttributes(block.clientId, {cloudflareVariant: variant});
            }
        });
    };

    const options = variants.map((v: string) => ({label: v, value: v}));

    return (
        <>
            <BlockEdit {...props} />
            <InspectorControls>
                <PanelBody title={__('Cloudflare Variants', 'flare-press')} initialOpen={true}>
                    {loading ? (
                        <Spinner/>
                    ) : (
                        <SelectControl
                            label={__('Apply variant to all images', 'flare-press')}
                            value={''}
                            options={[
                                {label: __('Select a variant...', 'flare-press'), value: ''},
                                ...options
                            ]}
                            onChange={applyVariantToAll}
                        />
                    )}
                </PanelBody>
            </InspectorControls>
        </>
    );
}

function MediaTextVariantPanel({
    BlockEdit,
    props
}: {
    BlockEdit: ComponentType<ExtendedBlockEditProps<any>>;
    props: ExtendedBlockEditProps<any>;
}) {
    const {attributes, setAttributes} = props;
    const [variants, setVariants] = useState<string[]>([]);
    const [loading, setLoading] = useState(true);
    const [originalUrl, setOriginalUrl] = useState<string>('');

    const media = useSelect(
        (select) => {
            if (!attributes.mediaId) return null;
            const {getMedia} = select(coreStore) as any;
            return getMedia(attributes.mediaId);
        },
        [attributes.mediaId]
    );

    const cfImageId = media?.fp_cf_image_id;

    useEffect(() => {
        if (attributes.mediaUrl && !originalUrl) {
            setOriginalUrl(attributes.mediaUrl);
        }
    }, [attributes.mediaUrl]);

    useEffect(() => {
        if (cfImageId) {
            getVariantNames().then(result => {
                if (result) setVariants(result);
                setLoading(false);
            });
        } else {
            setLoading(false);
        }
    }, [cfImageId]);

    useEffect(() => {
        if (cfImageId && attributes.cloudflareVariant && originalUrl) {
            getAccountHash().then(result => {
                const variantUrl = `https://imagedelivery.net/${result}/${cfImageId}/${attributes.cloudflareVariant}`;
                setAttributes({mediaUrl: variantUrl});
            });
        }
    }, [attributes.cloudflareVariant, cfImageId]);

    if (!cfImageId) {
        return <BlockEdit {...props} />;
    }

    const options = variants.map(variant => ({label: variant, value: variant}));

    return (
        <>
            <BlockEdit {...props} />
            <InspectorControls>
                <PanelBody title={__('Cloudflare Variants', 'flare-press')} initialOpen={true}>
                    {loading ? (
                        <Spinner/>
                    ) : (
                        <SelectControl
                            label={__('Choose a variant', 'flare-press')}
                            value={attributes.cloudflareVariant || ''}
                            options={[
                                {label: __('Select a variant...', 'flare-press'), value: ''},
                                ...options
                            ]}
                            onChange={(value: string) => setAttributes({cloudflareVariant: value})}
                        />
                    )}
                </PanelBody>
            </InspectorControls>
        </>
    );
}

const withCustomControl = createHigherOrderComponent(
    (BlockEdit: ComponentType<ExtendedBlockEditProps<ImageBlockAttributes>>) => {
        return (props: ExtendedBlockEditProps<ImageBlockAttributes>) => {
            if (props.name === 'core/image') {
                return <ImageVariantPanel BlockEdit={BlockEdit} props={props}/>;
            }

            if (props.name === 'core/gallery') {
                return <GalleryVariantPanel BlockEdit={BlockEdit as any} props={props}/>;
            }

            if (props.name === 'core/media-text') {
                return <MediaTextVariantPanel BlockEdit={BlockEdit as any} props={props}/>;
            }

            return <BlockEdit {...props} />;
        };
    },
    'withCustomControl'
);

addFilter(
    'editor.BlockEdit',
    'flare-press/with-custom-control',
    withCustomControl
);

async function getVariantNames(): Promise<string[] | false> {
    const wpNonce = window?.wp?.apiFetch?.nonceMiddleware?.nonce ?? await RestApi.getWpNonce();

    if (!wpNonce) {
        return false;
    }

    const url = '/wp-json/flare-press/v1/get-variant-names';

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': wpNonce
            }
        });

        if (response.ok) {
            const result: GetVariantNamesResponse = await response.json();
            return result.data as unknown as string[];
        }

        return false;
    } catch (error) {
        console.error('Error getting variant names:', error);
        return false;
    }
}

async function getAccountHash(): Promise<string | false> {
    const wpNonce = window?.wp?.apiFetch?.nonceMiddleware?.nonce ?? await RestApi.getWpNonce();

    if (!wpNonce) {
        return false;
    }

    const url = '/wp-json/flare-press/v1/get-account-hash';

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': wpNonce
            }
        });

        if (response.ok) {
            const result: GetAccountHashResponse = await response.json();
            return result.data as string;
        }

        return false;
    } catch (error) {
        console.error('Error getting account hash:', error);
        return false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const uploadManager = new UploadManager();

    uploadManager.hookUploader();
    uploadManager.hookRestApiUpload();

    appendSwitcherToMediaModal(uploadManager);
    detectAndMarkCfImages();
    addSwitcherToImageBlock(uploadManager);
})