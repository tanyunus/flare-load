<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'autoload.php';

use FlarePress\Controllers\AttachmentController;
use FlarePress\Data\Constants;

// ── Safety net: restore URLs for attachments still on Cloudflare ──────────────
//
// After uninstall the plugin's URL filters are gone. For any image not yet
// migrated to local, update _wp_attached_file and guid so WordPress returns
// the Cloudflare CDN URL instead of a broken local path.

$flarep_cfIds = get_posts([
    'post_type'      => 'attachment',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_query'     => [
        [
            'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
            'compare' => 'EXISTS',
        ],
        [
            'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
            'value'   => '',
            'compare' => '!=',
        ],
    ],
]);

global $wpdb;

foreach ($flarep_cfIds as $flarep_attachmentId) {
    // Build CF URL while options are still available (deleted later in this script)
    $flarep_cfId  = get_post_meta($flarep_attachmentId, Constants::UPLOADED_IMAGE_CF_ID_NAME, true);
    $flarep_cfUrl = $flarep_cfId ? AttachmentController::getDefaultVariantUrl($flarep_cfId) : '';

    if ($flarep_cfUrl) {
        // guid — canonical URL used by WordPress internally
        $wpdb->update($wpdb->posts, ['guid' => $flarep_cfUrl], ['ID' => $flarep_attachmentId], ['%s'], ['%d']);

        // _wp_attached_file — WordPress supports full HTTP URLs here;
        // wp_get_attachment_url() will return it as-is.
        update_post_meta($flarep_attachmentId, '_wp_attached_file', $flarep_cfUrl);
    }

    // Delete the local CF preview thumbnail file from disk
    AttachmentController::deleteCfThumbnail($flarep_attachmentId);

    // Strip CF-specific keys from _wp_attachment_metadata
    $flarep_meta = wp_get_attachment_metadata($flarep_attachmentId);
    if (is_array($flarep_meta)) {
        unset(
            $flarep_meta[Constants::UPLOADED_IMAGE_CF_ID_NAME],
            $flarep_meta[Constants::UPLOADED_IMAGE_CF_FILE_NAME],
            $flarep_meta[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME]
        );
        wp_update_attachment_metadata($flarep_attachmentId, $flarep_meta);
    }

    // Remove CF ID post meta
    delete_post_meta($flarep_attachmentId, Constants::UPLOADED_IMAGE_CF_ID_NAME);
}

// ── Delete plugin options ─────────────────────────────────────────────────────

foreach ([
    Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME,
    Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME,
    Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME,
    Constants::DASHBOARD_CF_SIGNING_KEY_FIELD_NAME,
    Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME,
    Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME,
    Constants::DASHBOARD_UPLOAD_SETTINGS_NAME,
] as $flarep_option) {
    delete_option($flarep_option);
}

// ── Delete transients ─────────────────────────────────────────────────────────

delete_transient('flarep_backfill_v1_done');
delete_transient('flarep_migration_state');

// Per-user upload-error transients (pattern: flarep_upload_error_{user_id})
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_flarep_upload_error_%'
        OR option_name LIKE '_transient_timeout_flarep_upload_error_%'"
);
