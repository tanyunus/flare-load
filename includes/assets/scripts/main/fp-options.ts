import {SyncVariantsResponse} from "../types/types";
import RestApi from "../modules/RestApi";
import { __ } from '@wordpress/i18n';

function assertElement<T extends Element>(
    element: Element | null,
    type: string
): asserts element is T {
    if (!element) {
        throw new Error(`Required element of type ${type} not found`);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const syncButton = document.querySelector<HTMLButtonElement>('#fp_variant_sync_button');
    const variantListField = document.querySelector<HTMLElement>('#fp_variant_list_field');
    const spinner = document.querySelector<HTMLElement>('#fp_sync_variant_spinner');
    const defaultVariantSelect = document.querySelector<HTMLSelectElement>('#fp_cf_default_variant');
    const editApiTokenField = document.querySelector<HTMLButtonElement>('#fp_change_api_token_button');
    const apiTokenField = document.querySelector<HTMLInputElement>('[data-field-name="fp_cf_api_token"]');

    if (syncButton && variantListField) {
        syncButton.addEventListener('click', async () => {
            await handleSyncVariants(syncButton, variantListField, spinner, defaultVariantSelect);
        });
    }

    const testButton = document.querySelector<HTMLButtonElement>('#fp_test_connection_button');
    const testResult = document.querySelector<HTMLElement>('#fp_test_connection_result');

    if(editApiTokenField && apiTokenField) {
        editApiTokenField.addEventListener('click', function () {
            editApiTokenField.remove();
            apiTokenField.disabled = false;
            apiTokenField.name = apiTokenField.dataset?.fieldName as string;
            apiTokenField.value = '';
            apiTokenField.required = true;
            if (testButton) testButton.disabled = true;
        });

        apiTokenField.addEventListener('input', function () {
            if (testButton) testButton.disabled = apiTokenField.value.trim() === '';
        });
    }

    if (testButton && testResult) {
        testButton.addEventListener('click', async () => {
            testButton.disabled = true;
            testResult.textContent = '';

            const body = new FormData();
            body.append('action', 'fp_test_connection');

            const apiTokenInput = document.querySelector<HTMLInputElement>('[data-field-name="fp_cf_api_token"]');
            if (apiTokenInput && !apiTokenInput.disabled && apiTokenInput.value) {
                body.append('fp_test_token', apiTokenInput.value);
            }

            try {
                const response = await fetch((window as any).ajaxurl, { method: 'POST', body });
                const data = await response.json();

                if (data.success) {
                    testResult.style.color = '#46b450';
                    testResult.textContent = __('Connection successful.', 'flare-press');
                } else {
                    testResult.style.color = '#dc3232';
                    testResult.textContent = __('Connection failed. Please check your API token.', 'flare-press');
                }
            } catch {
                testResult.style.color = '#dc3232';
                testResult.textContent = __('Connection failed. Please check your API token.', 'flare-press');
            } finally {
                testButton.disabled = false;
            }
        });
    }
});

async function syncVariants(): Promise<string[] | false> {
    const wpNonce = await RestApi.getWpNonce();

    if (!wpNonce) {
        return false;
    }

    const url = '/wp-json/flare-press/v1/sync-variants';

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': wpNonce
            }
        });

        if (response.ok) {
            const result: SyncVariantsResponse = await response.json();
            return JSON.parse(result.data) as string[];
        }

        return false;
    } catch (error) {
        console.error('Error syncing variants:', error);
        return false;
    }
}

function renderNewVariants(variantArray: string[]): string {
    let finalHtml = '';

    variantArray.forEach(variant => {
        finalHtml += `<code>${variant}</code> `;
    });

    return finalHtml;
}

function updateDefaultVariantOptions(
    defaultVariantField: HTMLSelectElement,
    variantArray: string[]
): string {
    const currentlySelected = defaultVariantField.selectedOptions[0]?.value || '';
    let finalHtml = '';

    variantArray.forEach(variant => {
        const isSelected = currentlySelected === variant;
        finalHtml += `<option value="${variant}"${isSelected ? ` selected="${variant}"` : ''}>${variant}</option>`;
    });

    return finalHtml;
}

async function handleSyncVariants(
    syncButton: HTMLButtonElement,
    variantListField: HTMLElement,
    spinner: HTMLElement | null,
    defaultVariantSelect: HTMLSelectElement | null
): Promise<void> {
    // Disable UI elements
    syncButton.toggleAttribute('disabled', true);
    if (defaultVariantSelect) {
        defaultVariantSelect.toggleAttribute('disabled', true);
    }
    if (spinner) {
        spinner.classList.toggle('is-active', true);
    }

    const syncedVariants = await syncVariants();

    if (Array.isArray(syncedVariants)) {
        variantListField.innerHTML = renderNewVariants(syncedVariants);

        if (defaultVariantSelect) {
            defaultVariantSelect.innerHTML = updateDefaultVariantOptions(
                defaultVariantSelect,
                syncedVariants
            );
        }

        // Re-enable UI elements
        syncButton.toggleAttribute('disabled', false);
        if (defaultVariantSelect) {
            defaultVariantSelect.toggleAttribute('disabled', false);
        }
        if (spinner) {
            spinner.classList.toggle('is-active', false);
        }
    } else {
        // Handle error case - re-enable buttons even if sync failed
        syncButton.toggleAttribute('disabled', false);
        if (defaultVariantSelect) {
            defaultVariantSelect.toggleAttribute('disabled', false);
        }
        if (spinner) {
            spinner.classList.toggle('is-active', false);
        }

        console.error('Failed to sync variants');
    }
}