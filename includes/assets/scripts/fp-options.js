document.addEventListener('DOMContentLoaded', function () {
    const syncButton = document.querySelector('#fp_variant_sync_button');
    const variantListField = document.querySelector('#fp_variant_list_field');
    const spinner = document.querySelector('#fp_sync_variant_spinner');
    const defaultVariantSelect = document.querySelector('#fp_cf_default_variant');

    if (syncButton && variantListField) {
        syncButton.addEventListener('click', async () => {
            await handleSyncVariants(syncButton, variantListField, spinner, defaultVariantSelect);
        });
    }
});

async function syncVariants() {
    const wpNonce = await getWpNonce();

    if (!wpNonce) {
        return;
    }

    const url = '/wp-json/flare-press/v1/sync-variants';

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': wpNonce
        }
    });

    if (response.ok) {
        return response.json().then(result => JSON.parse(result.data))
    }

    return false;
}

async function getWpNonce() {
    return await fetch('/wp-admin/admin-ajax.php?action=rest-nonce').then((response) => {
        if (response.ok) {
            return response.text().then(result => result)
        }
    });
}

function renderNewVariants(variantArray) {
    let finalHtml = '';

    variantArray.forEach(variant => {
        finalHtml += `<code>${variant}</code> `;
    });

    return finalHtml;
}

function updateDefaultVariantOptions(defaultVariantField, variantArray) {
    const currentlySelected = defaultVariantField.selectedOptions[0].value;
    let finalHtml = '';

    variantArray.forEach(variant => {
        finalHtml += `<option value="${variant}" ` + (currentlySelected === variant ? `selected="${variant}"` : ``) + `>${variant}</option>`
    });

    return finalHtml;
}

async function handleSyncVariants(syncButton, variantListField, spinner, defaultVariantSelect) {
    syncButton.toggleAttribute('disabled', true);
    defaultVariantSelect.toggleAttribute('disabled', true);
    spinner && spinner.classList.toggle('is-active', true);

    const syncedVariants = await syncVariants();

    if (Array.isArray(syncedVariants)) {
        variantListField.innerHTML = renderNewVariants(syncedVariants);
        defaultVariantSelect.innerHTML = updateDefaultVariantOptions(defaultVariantSelect, syncedVariants);
        syncButton.toggleAttribute('disabled', false);
        defaultVariantSelect.toggleAttribute('disabled', false);
        spinner && spinner.classList.toggle('is-active', false);
    }
}