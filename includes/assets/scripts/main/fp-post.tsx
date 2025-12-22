import {addFilter} from '@wordpress/hooks';
import {createHigherOrderComponent} from '@wordpress/compose';
import {InspectorControls} from '@wordpress/block-editor';
import {PanelBody, SelectControl} from '@wordpress/components';
import {BlockEditProps} from '@wordpress/blocks';
import {ComponentType} from 'react';
import React from "react";

interface ImageBlockAttributes {
    customOption?: 'option1' | 'option2' | 'option3';

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
            customOption: {
                type: 'string',
                default: 'option1'
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

            return (
                <>
                    <BlockEdit {...props} />
                    <InspectorControls>
                        <PanelBody title="Cloudflare Variants" initialOpen={true}>
                            <SelectControl
                                label="Choose a variant"
                                value={attributes.customOption || 'option1'}
                                options={[
                                    {label: 'Option 1', value: 'option1'},
                                    {label: 'Option 2', value: 'option2'},
                                    {label: 'Option 3', value: 'option3'}
                                ]}
                                onChange={(value: ImageBlockAttributes["customOption"]) => setAttributes({customOption: value})}
                            />
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