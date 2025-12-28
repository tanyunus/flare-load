import {addFilter} from '@wordpress/hooks';
import {createHigherOrderComponent} from '@wordpress/compose';
import {InspectorControls} from '@wordpress/block-editor';
import {PanelBody, SelectControl, Spinner} from '@wordpress/components';
import {BlockEditProps} from '@wordpress/blocks';
import {ComponentType, useEffect, useState} from '@wordpress/element';
import {useSelect} from '@wordpress/data';
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
    if (name !== 'core/image') {
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

const withCustomControl = createHigherOrderComponent(
    (BlockEdit: ComponentType<ExtendedBlockEditProps<ImageBlockAttributes>>) => {
        return (props: ExtendedBlockEditProps<ImageBlockAttributes>) => {
            if (props.name !== 'core/image') {
                return <BlockEdit {...props} />;
            }

            const {attributes, setAttributes} = props;
            const [variants, setVariants] = useState<string[]>([]);
            const [loading, setLoading] = useState(true);
            const [originalUrl, setOriginalUrl] = useState<string>('');

            const media = useSelect(
                (select) => {
                    if (!attributes.id) {
                        return null;
                    }
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
                        if (result) {
                            setVariants(result);
                        }
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
                    })
                }
            }, [attributes.cloudflareVariant, cfImageId]);

            if (!cfImageId) {
                return <BlockEdit {...props} />;
            }

            const options = variants.map(variant => ({
                label: variant,
                value: variant
            }));

            return (
                <>
                    <BlockEdit {...props} />
                    <InspectorControls>
                        <PanelBody title="Cloudflare Variants" initialOpen={true}>
                            {loading ? (
                                <Spinner/>
                            ) : (
                                <SelectControl
                                    label="Choose a variant"
                                    value={attributes.cloudflareVariant || ''}
                                    options={[
                                        {label: 'Select a variant...', value: ''},
                                        ...options
                                    ]}
                                    onChange={(value: string) => setAttributes({cloudflareVariant: value})}
                                />
                            )}
                        </PanelBody>
                    </InspectorControls>
                </>
            );
        };
    },
    'withCustomControl'
);

addFilter(
    'editor.BlockEdit',
    'flare-press/with-custom-control',
    withCustomControl
);
``

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