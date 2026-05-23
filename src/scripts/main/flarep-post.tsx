import {addFilter} from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import {createHigherOrderComponent} from '@wordpress/compose';
import {InspectorControls} from '@wordpress/block-editor';
import {PanelBody, SelectControl} from '@wordpress/components';
import {BlockEditProps} from '@wordpress/blocks';
import {ComponentType, useEffect, useRef, useState} from '@wordpress/element';
import {useSelect, useDispatch} from '@wordpress/data';
import {store as blockEditorStore} from '@wordpress/block-editor';
import RestApi from "../modules/RestApi";
import {GetVariantNamesResponse, VariantOption} from "../types/types";
import React from "react";

function getCfImageIdFromUrl(url: string): string | null {
    const accountHash = (window as any).flarepConfig?.accountHash;
    if (!accountHash || !url) return null;
    const prefix = `https://imagedelivery.net/${accountHash}/`;
    if (!url.startsWith(prefix)) return null;
    const parts = url.slice(prefix.length).split('/');
    return parts[0] || null;
}
import UploadManager from "../modules/UploadManager";
import {appendSwitcherToMediaModal} from "../functions/media-modal";
import {detectAndMarkCfImages} from "../functions/cf-image-detector";
import {addSwitcherToImageBlock} from "../functions/image-block";

interface ImageBlockAttributes {
    cloudflareVariant?: string;
    flareload_cf_image_id: string;

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
    'flare-load/add-custom-attribute',
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
    const configVariants = (window as any).flarepConfig?.variantOptions;
    const initialVariants: VariantOption[] = Array.isArray(configVariants) ? configVariants : [];
    const defaultVariant: string = (window as any).flarepConfig?.defaultVariant ?? '';
    const accountHash: string = (window as any).flarepConfig?.accountHash ?? '';
    const [variants, setVariants] = useState<VariantOption[]>(initialVariants);
    const autoApplied = useRef(false);

    const cfImageId = getCfImageIdFromUrl(attributes.url ?? '');

    useEffect(() => {
        if (cfImageId && variants.length === 0) {
            getVariantNames().then(result => {
                if (result) setVariants(result);
            });
        }
    }, [cfImageId]);

    useEffect(() => {
        if (cfImageId && !attributes.cloudflareVariant && defaultVariant && !autoApplied.current) {
            autoApplied.current = true;
            setAttributes({cloudflareVariant: defaultVariant});
        }
    }, [cfImageId]);

    useEffect(() => {
        if (cfImageId && attributes.cloudflareVariant && accountHash) {
            setAttributes({url: `https://imagedelivery.net/${accountHash}/${cfImageId}/${attributes.cloudflareVariant}`});
        }
    }, [attributes.cloudflareVariant]);

    if (!cfImageId) {
        return <BlockEdit {...props} />;
    }

    const options = variants.map(v => ({label: v.label, value: v.name}));

    return (
        <>
            <BlockEdit {...props} />
            <InspectorControls>
                <PanelBody title={__('Cloudflare Variants', 'flare-load')} initialOpen={true}>
                    <SelectControl
                        label={__('Choose a variant', 'flare-load')}
                        value={attributes.cloudflareVariant || ''}
                        options={[
                            {label: __('Select a variant...', 'flare-load'), value: ''},
                            ...options
                        ]}
                        onChange={(value: string) => {
                            autoApplied.current = true;
                            setAttributes({cloudflareVariant: value});
                        }}
                    />
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
    const configVariants = (window as any).flarepConfig?.variantOptions;
    const initialVariants: VariantOption[] = Array.isArray(configVariants) ? configVariants : [];
    const defaultVariant = (window as any).flarepConfig?.defaultVariant ?? '';
    const [variants, setVariants] = useState<VariantOption[]>(initialVariants);
    const autoApplied = useRef(false);

    const {updateBlockAttributes} = useDispatch(blockEditorStore) as any;

    const innerBlocks = useSelect(
        (select) => (select(blockEditorStore) as any).getBlocks(clientId),
        [clientId]
    );

    const hasCfImages = innerBlocks.some(
        (b: any) => b.name === 'core/image' && !!getCfImageIdFromUrl(b.attributes?.url ?? '')
    );

    useEffect(() => {
        if (!hasCfImages || variants.length > 0) return;
        getVariantNames().then(result => {
            if (result) setVariants(result);
        });
    }, [hasCfImages]);

    useEffect(() => {
        if (!hasCfImages || !defaultVariant || autoApplied.current) return;
        innerBlocks.forEach((block: any) => {
            if (block.name === 'core/image' && !block.attributes?.cloudflareVariant) {
                if (getCfImageIdFromUrl(block.attributes?.url ?? '')) {
                    autoApplied.current = true;
                    updateBlockAttributes(block.clientId, {cloudflareVariant: defaultVariant});
                }
            }
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

    const options = variants.map((v: VariantOption) => ({label: v.label, value: v.name}));

    return (
        <>
            <BlockEdit {...props} />
            <InspectorControls>
                <PanelBody title={__('Cloudflare Variants', 'flare-load')} initialOpen={true}>
                    <SelectControl
                        label={__('Apply variant to all images', 'flare-load')}
                        value={''}
                        options={[
                            {label: __('Select a variant...', 'flare-load'), value: ''},
                            ...options
                        ]}
                        onChange={applyVariantToAll}
                    />
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
    const configVariants = (window as any).flarepConfig?.variantOptions;
    const initialVariants: VariantOption[] = Array.isArray(configVariants) ? configVariants : [];
    const defaultVariant: string = (window as any).flarepConfig?.defaultVariant ?? '';
    const accountHash: string = (window as any).flarepConfig?.accountHash ?? '';
    const [variants, setVariants] = useState<VariantOption[]>(initialVariants);
    const autoApplied = useRef(false);

    const cfImageId = getCfImageIdFromUrl(attributes.mediaUrl ?? '');

    useEffect(() => {
        if (cfImageId && variants.length === 0) {
            getVariantNames().then(result => {
                if (result) setVariants(result);
            });
        }
    }, [cfImageId]);

    useEffect(() => {
        if (cfImageId && !attributes.cloudflareVariant && defaultVariant && !autoApplied.current) {
            autoApplied.current = true;
            setAttributes({cloudflareVariant: defaultVariant});
        }
    }, [cfImageId]);

    useEffect(() => {
        if (cfImageId && attributes.cloudflareVariant && accountHash) {
            setAttributes({mediaUrl: `https://imagedelivery.net/${accountHash}/${cfImageId}/${attributes.cloudflareVariant}`});
        }
    }, [attributes.cloudflareVariant]);

    if (!cfImageId) {
        return <BlockEdit {...props} />;
    }

    const options = variants.map(v => ({label: v.label, value: v.name}));

    return (
        <>
            <BlockEdit {...props} />
            <InspectorControls>
                <PanelBody title={__('Cloudflare Variants', 'flare-load')} initialOpen={true}>
                    <SelectControl
                        label={__('Choose a variant', 'flare-load')}
                        value={attributes.cloudflareVariant || ''}
                        options={[
                            {label: __('Select a variant...', 'flare-load'), value: ''},
                            ...options
                        ]}
                        onChange={(value: string) => {
                            autoApplied.current = true;
                            setAttributes({cloudflareVariant: value});
                        }}
                    />
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
    'flare-load/with-custom-control',
    withCustomControl
);

async function getVariantNames(): Promise<VariantOption[] | false> {
    const fromConfig = (window as any).flarepConfig?.variantOptions;
    if (Array.isArray(fromConfig) && fromConfig.length > 0) {
        return fromConfig;
    }

    const wpNonce = window?.wp?.apiFetch?.nonceMiddleware?.nonce ?? await RestApi.getWpNonce();

    if (!wpNonce) {
        return false;
    }

    const url = (window.flarepConfig?.restUrl ?? '/wp-json/flare-load/v1/') + 'get-variant-names';

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': wpNonce
            }
        });

        if (response.ok) {
            const result: GetVariantNamesResponse = await response.json();
            return result.data;
        }

        return false;
    } catch (error) {
        console.error('Error getting variant names:', error);
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