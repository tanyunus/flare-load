import {SyncVariantsResponse, VariantOption} from "../types/types";
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
    const syncButton = document.querySelector<HTMLButtonElement>('#flareload_variant_sync_button');
    const spinner = document.querySelector<HTMLElement>('#flareload_sync_variant_spinner');
    const defaultVariantSelect = document.querySelector<HTMLSelectElement>('#flareload_cf_default_variant');
    const editApiTokenField = document.querySelector<HTMLButtonElement>('#flareload_change_api_token_button');
    const apiTokenField = document.querySelector<HTMLInputElement>('[data-field-name="flareload_cf_api_token"]');

    if (syncButton) {
        syncButton.addEventListener('click', async () => {
            await handleSyncVariants(syncButton, spinner, defaultVariantSelect);
        });
    }

    const testButton = document.querySelector<HTMLButtonElement>('#flareload_test_connection_button');
    const testResult = document.querySelector<HTMLElement>('#flareload_test_connection_result');

    if (apiTokenField) {
        apiTokenField.addEventListener('input', function () {
            if (testButton) testButton.disabled = apiTokenField.value.trim() === '';
        });
    }

    if (editApiTokenField && apiTokenField) {
        editApiTokenField.addEventListener('click', function () {
            editApiTokenField.remove();
            apiTokenField.disabled = false;
            apiTokenField.name = apiTokenField.dataset?.fieldName as string;
            apiTokenField.value = '';
            apiTokenField.required = true;
            if (testButton) testButton.disabled = true;
        });
    }

    if (testButton && testResult) {
        testButton.addEventListener('click', async () => {
            testButton.disabled = true;
            testResult.textContent = '';

            const body = new FormData();
            body.append('action', 'flareload_test_connection');
            body.append('nonce', (window as any).flareloadConfig?.testConnectionNonce ?? '');

            const apiTokenInput = document.querySelector<HTMLInputElement>('[data-field-name="flareload_cf_api_token"]');
            if (apiTokenInput && !apiTokenInput.disabled && apiTokenInput.value) {
                body.append('flareload_test_token', apiTokenInput.value);
            }

            try {
                const response = await fetch((window as any).ajaxurl, { method: 'POST', body });
                const data = await response.json();

                if (data.success) {
                    testResult.style.color = '#46b450';
                    testResult.textContent = __('Connection successful.', 'flare-load');
                } else {
                    testResult.style.color = '#dc3232';
                    testResult.textContent = __('Connection failed. Please check your API token.', 'flare-load');
                }
            } catch {
                testResult.style.color = '#dc3232';
                testResult.textContent = __('Connection failed. Please check your API token.', 'flare-load');
            } finally {
                testButton.disabled = false;
            }
        });
    }
});

async function syncVariants(): Promise<VariantOption[] | false> {
    const wpNonce = await RestApi.getWpNonce();

    if (!wpNonce) {
        return false;
    }

    const url = (window.flareloadConfig?.restUrl ?? '/wp-json/flare-load/v1/') + 'sync-variants';

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': wpNonce
            }
        });

        if (response.ok) {
            const result: SyncVariantsResponse = await response.json();
            return result.data;
        }

        return false;
    } catch (error) {
        console.error('Error syncing variants:', error);
        return false;
    }
}

function updateDefaultVariantOptions(
    defaultVariantField: HTMLSelectElement,
    variants: VariantOption[]
): string {
    const currentlySelected = defaultVariantField.selectedOptions[0]?.value || '';
    return variants.map(v => {
        const isSelected = currentlySelected === v.name;
        return `<option value="${v.name}"${isSelected ? ` selected="${v.name}"` : ''}>${v.label}</option>`;
    }).join('');
}

async function handleSyncVariants(
    syncButton: HTMLButtonElement,
    spinner: HTMLElement | null,
    defaultVariantSelect: HTMLSelectElement | null
): Promise<void> {
    syncButton.disabled = true;
    if (defaultVariantSelect) defaultVariantSelect.disabled = true;
    if (spinner) spinner.classList.add('is-active');

    const syncedVariants = await syncVariants();

    if (Array.isArray(syncedVariants) && defaultVariantSelect) {
        defaultVariantSelect.innerHTML = updateDefaultVariantOptions(defaultVariantSelect, syncedVariants);
    } else if (!syncedVariants) {
        console.error('Failed to sync variants');
    }

    syncButton.disabled = false;
    if (defaultVariantSelect) defaultVariantSelect.disabled = false;
    if (spinner) spinner.classList.remove('is-active');
}