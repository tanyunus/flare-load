document.addEventListener('DOMContentLoaded', function () {
    const syncButton = document.querySelector('#fp_variant_sync_button');
    const variantListField = document.querySelector('#fp_variant_list_field');

    if (syncButton && variantListField) {
        syncButton.addEventListener('click', async () => {
            const syncedVariants = await syncVariants();

            if(Array.isArray(syncedVariants)) {
                variantListField.innerHTML = renderNewVariants(syncedVariants);
            }
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

    if(response.ok) {
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
        finalHtml +=  `<code>${variant}</code> `;
    });

    return finalHtml;
}