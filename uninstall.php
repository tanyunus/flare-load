<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'autoload.php';

use FlareLoad\Controllers\AttachmentController;
use FlareLoad\Data\Constants;

// ── Safety net: restore URLs for attachments still on Cloudflare ──────────────
//
// After uninstall the plugin's URL filters are gone. For any image not yet
// migrated to local, update _wp_attached_file and guid so WordPress returns
// the Cloudflare CDN URL instead of a broken local path.

$flareload_cfIds = get_posts([
    'post_type'      => 'attachment',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find CF attachments during uninstall; no alternative.
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

foreach ($flareload_cfIds as $flareload_attachmentId) {
    // Build CF URL while options are still available (deleted later in this script)
    $flareload_cfId  = get_post_meta($flareload_attachmentId, Constants::UPLOADED_IMAGE_CF_ID_NAME, true);
    $flareload_cfUrl = $flareload_cfId ? AttachmentController::getDefaultVariantUrl($flareload_cfId) : '';

    if ($flareload_cfUrl) {
        // guid — canonical URL used by WordPress internally
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- guid update during uninstall; wp_update_post() fires hooks that depend on plugin being present.
        $wpdb->update($wpdb->posts, ['guid' => $flareload_cfUrl], ['ID' => $flareload_attachmentId], ['%s'], ['%d']);

        // _wp_attached_file — WordPress supports full HTTP URLs here;
        // wp_get_attachment_url() will return it as-is.
        update_post_meta($flareload_attachmentId, '_wp_attached_file', $flareload_cfUrl);
    }

    // Delete the local CF preview thumbnail file from disk
    AttachmentController::deleteCfThumbnail($flareload_attachmentId);

    // Strip CF-specific keys from _wp_attachment_metadata
    $flareload_meta = wp_get_attachment_metadata($flareload_attachmentId);
    if (is_array($flareload_meta)) {
        unset(
            $flareload_meta[Constants::UPLOADED_IMAGE_CF_ID_NAME],
            $flareload_meta[Constants::UPLOADED_IMAGE_CF_FILE_NAME],
            $flareload_meta[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME]
        );
        wp_update_attachment_metadata($flareload_attachmentId, $flareload_meta);
    }

    // Remove CF ID post meta
    delete_post_meta($flareload_attachmentId, Constants::UPLOADED_IMAGE_CF_ID_NAME);
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
] as $flareload_option) {
    delete_option($flareload_option);
}

// ── Delete transients ─────────────────────────────────────────────────────────

delete_transient('flareload_backfill_v1_done');
delete_transient('flareload_migration_state');

// Per-user upload-error transients (pattern: flareload_upload_error_{user_id})
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pattern-based transient deletion; delete_transient() requires exact keys which are unknown at uninstall time.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_flareload_upload_error_%'
        OR option_name LIKE '_transient_timeout_flareload_upload_error_%'"
);
