<?php
// Only run when WordPress triggers uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'fp_cf_account_id',
    'fp_cf_account_hash',
    'fp_cf_api_token',
    'fp_cf_variant_list',
    'fp_cf_default_variant',
    'fp_upload_settings',
];

foreach ($options as $option) {
    delete_option($option);
}
