/**
 * EasyCheckout Gutenberg Block Editor
 *
 * Registers Gutenberg blocks for EasyCheckout.
 */
(function(blocks, element, blockEditor, components, i18n) {
    'use strict';

    var el = element.createElement;
    var Fragment = element.Fragment;
    var InspectorControls = blockEditor.InspectorControls;
    var useBlockProps = blockEditor.useBlockProps;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var TextControl = components.TextControl;
    var ToggleControl = components.ToggleControl;
    var Placeholder = components.Placeholder;
    var __ = i18n.__;

    var data = window.easycheckoutBlockData || {};
    var checkouts = data.checkouts || [];

    // Build checkout options for select
    var checkoutOptions = [{ value: 0, label: __('Select a checkout...', 'easycheckout') }];
    checkouts.forEach(function(checkout) {
        checkoutOptions.push({
            value: checkout.value,
            label: checkout.label
        });
    });

    /**
     * EasyCheckout Checkout Block
     */
    blocks.registerBlockType('easycheckout/checkout', {
        title: data.i18n?.blockTitle || 'EasyCheckout',
        description: data.i18n?.blockDescription || 'Embed an EasyCheckout payment form.',
        icon: 'cart',
        category: 'widgets',
        keywords: ['payment', 'checkout', 'stripe', 'easycheckout'],
        attributes: {
            checkoutId: {
                type: 'number',
                default: 0
            },
            checkoutSlug: {
                type: 'string',
                default: ''
            },
            theme: {
                type: 'string',
                default: 'light'
            },
            showHeader: {
                type: 'boolean',
                default: true
            },
            buttonText: {
                type: 'string',
                default: ''
            }
        },

        edit: function(props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var blockProps = useBlockProps();

            var selectedCheckout = checkouts.find(function(c) {
                return c.value === attributes.checkoutId;
            });

            return el(Fragment, {},
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Checkout Settings', 'easycheckout') },
                        el(SelectControl, {
                            label: data.i18n?.selectCheckout || 'Select Checkout',
                            value: attributes.checkoutId,
                            options: checkoutOptions,
                            onChange: function(value) {
                                var checkout = checkouts.find(function(c) {
                                    return c.value === parseInt(value);
                                });
                                setAttributes({
                                    checkoutId: parseInt(value),
                                    checkoutSlug: checkout ? checkout.slug : ''
                                });
                            }
                        }),
                        el(TextControl, {
                            label: data.i18n?.checkoutSlug || 'Checkout Slug',
                            help: __('Or enter an EasyCheckout slug directly', 'easycheckout'),
                            value: attributes.checkoutSlug,
                            onChange: function(value) {
                                setAttributes({ checkoutSlug: value });
                            }
                        }),
                        el(SelectControl, {
                            label: data.i18n?.theme || 'Theme',
                            value: attributes.theme,
                            options: [
                                { value: 'light', label: data.i18n?.light || 'Light' },
                                { value: 'dark', label: data.i18n?.dark || 'Dark' }
                            ],
                            onChange: function(value) {
                                setAttributes({ theme: value });
                            }
                        }),
                        el(ToggleControl, {
                            label: data.i18n?.showHeader || 'Show Header',
                            checked: attributes.showHeader,
                            onChange: function(value) {
                                setAttributes({ showHeader: value });
                            }
                        }),
                        el(TextControl, {
                            label: data.i18n?.buttonText || 'Button Text',
                            value: attributes.buttonText,
                            onChange: function(value) {
                                setAttributes({ buttonText: value });
                            }
                        })
                    )
                ),
                el('div', blockProps,
                    attributes.checkoutId || attributes.checkoutSlug
                        ? el('div', { className: 'easycheckout-block-preview' },
                            el('div', { className: 'easycheckout-block-preview-header' },
                                el('span', { className: 'dashicons dashicons-cart' }),
                                el('span', {}, selectedCheckout ? selectedCheckout.label : attributes.checkoutSlug)
                            ),
                            el('div', { className: 'easycheckout-block-preview-body' },
                                el('p', {}, __('Checkout form will appear here', 'easycheckout'))
                            )
                        )
                        : el(Placeholder, {
                            icon: 'cart',
                            label: data.i18n?.blockTitle || 'EasyCheckout',
                            instructions: checkouts.length
                                ? __('Select a checkout from the sidebar settings.', 'easycheckout')
                                : (data.i18n?.noCheckouts || 'No checkouts found. Create one first.')
                        })
                )
            );
        },

        save: function() {
            // Render via PHP
            return null;
        }
    });

    /**
     * EasyCheckout Button Block
     */
    blocks.registerBlockType('easycheckout/button', {
        title: data.i18n?.buttonBlockTitle || 'EasyCheckout Button',
        description: data.i18n?.buttonBlockDescription || 'Add a payment button.',
        icon: 'button',
        category: 'widgets',
        keywords: ['payment', 'button', 'checkout', 'buy', 'easycheckout'],
        attributes: {
            checkoutId: {
                type: 'number',
                default: 0
            },
            checkoutSlug: {
                type: 'string',
                default: ''
            },
            buttonText: {
                type: 'string',
                default: 'Buy Now'
            },
            productId: {
                type: 'string',
                default: ''
            },
            className: {
                type: 'string',
                default: ''
            }
        },

        edit: function(props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var blockProps = useBlockProps();

            return el(Fragment, {},
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Button Settings', 'easycheckout') },
                        el(SelectControl, {
                            label: data.i18n?.selectCheckout || 'Select Checkout',
                            value: attributes.checkoutId,
                            options: checkoutOptions,
                            onChange: function(value) {
                                var checkout = checkouts.find(function(c) {
                                    return c.value === parseInt(value);
                                });
                                setAttributes({
                                    checkoutId: parseInt(value),
                                    checkoutSlug: checkout ? checkout.slug : ''
                                });
                            }
                        }),
                        el(TextControl, {
                            label: data.i18n?.checkoutSlug || 'Checkout Slug',
                            value: attributes.checkoutSlug,
                            onChange: function(value) {
                                setAttributes({ checkoutSlug: value });
                            }
                        }),
                        el(TextControl, {
                            label: data.i18n?.buttonText || 'Button Text',
                            value: attributes.buttonText,
                            onChange: function(value) {
                                setAttributes({ buttonText: value });
                            }
                        }),
                        el(TextControl, {
                            label: data.i18n?.productId || 'Product ID',
                            help: __('Optional: Specific product to purchase', 'easycheckout'),
                            value: attributes.productId,
                            onChange: function(value) {
                                setAttributes({ productId: value });
                            }
                        })
                    )
                ),
                el('div', blockProps,
                    el('button', {
                        className: 'easycheckout-button wp-block-button__link ' + (attributes.className || ''),
                        type: 'button',
                        disabled: true
                    }, attributes.buttonText || 'Buy Now')
                )
            );
        },

        save: function() {
            // Render via PHP
            return null;
        }
    });

})(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.i18n
);
