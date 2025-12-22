import {SyncVariantsResponse} from "../types/types";

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

    if (syncButton && variantListField) {
        syncButton.addEventListener('click', async () => {
            await handleSyncVariants(syncButton, variantListField, spinner, defaultVariantSelect);
        });
    }
});

async function syncVariants(): Promise<string[] | false> {
    const wpNonce = await getWpNonce();

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

async function getWpNonce(): Promise<string | undefined> {
    try {
        const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce');

        if (response.ok) {
            return await response.text();
        }

        return undefined;
    } catch (error) {
        console.error('Error getting WP nonce:', error);
        return undefined;
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