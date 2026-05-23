<?php
/*
Plugin Name: FlareLoad
Description: WordPress plugin for uploading media directly to Cloudflare Images alongside the default uploader.
Version:     1.0.4
Requires at least: 5.9
Requires PHP:      8.0
Author:      Yunus Tan
Author URI:  https://github.com/tanyunus/
License:     GPL-3.0+
Text Domain: flare-load
*/

use FlareLoad\Api\CloudflareImagesApi;
use FlareLoad\Controllers\AttachmentController;
use FlareLoad\Controllers\MigrationController;
use FlareLoad\Controllers\OptionController;
use FlareLoad\Data\Constants;
use FlareLoad\Util\Logger;
use FlareLoad\Util\Utils;
use FlareLoad\RestApi\OptionRestApi;

if (!defined('WPINC')) {
    die;
}

define('FLARELOAD_VERSION', '1.0.4');
define('FLARELOAD_PATH', plugin_dir_path(__FILE__));
define('FLARELOAD_URL', plugin_dir_url(__FILE__));

require_once FLARELOAD_PATH . 'autoload.php';

register_activation_hook(__FILE__, 'FLARELOAD_activate');

function FLARELOAD_activate(): void {
    if (!get_option('flareload_upload_settings')) {
        update_option('flareload_upload_settings', [
            Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME       => false,
            Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME => false,
        ]);
    }

    delete_user_meta(get_current_user_id(), 'flareload_setup_notice_dismissed');
    FLARELOAD_backfill_cf_post_meta();
}

function FLARELOAD_maybe_run_backfill(): void {
    if (get_transient('flareload_backfill_v1_done')) {
        return;
    }
    FLARELOAD_backfill_cf_post_meta();
    set_transient('flareload_backfill_v1_done', true, WEEK_IN_SECONDS);
}

function FLARELOAD_backfill_cf_post_meta(): void {
    $attachments = get_posts([
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find attachments not yet having the CF meta key.
        'meta_query'     => [[
            'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
            'compare' => 'NOT EXISTS',
        ]],
    ]);

    foreach ($attachments as $id) {
        $meta = wp_get_attachment_metadata($id);
        if (!empty($meta[Constants::UPLOADED_IMAGE_CF_ID_NAME])) {
            update_post_meta($id, Constants::UPLOADED_IMAGE_CF_ID_NAME, $meta[Constants::UPLOADED_IMAGE_CF_ID_NAME]);
        }
    }
}

add_action('plugins_loaded', 'FlareLoadInit');

function FLARELOAD_has_complete_credentials(): bool
{
    return !empty(get_option(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME))
        && !empty(get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME))
        && !empty(get_option(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME));
}

function FLARELOAD_incomplete_setup_notice(): void
{
    $url     = admin_url('admin.php?page=' . Constants::DASHBOARD_MENU_SLUG);
    $message = sprintf(
        /* translators: %s: link to FlareLoad settings page */
        __('FlareLoad is not fully configured. Please complete your <a href="%s">Cloudflare settings</a> to enable all features.', 'flare-load'),
        esc_url($url)
    );

    if (Utils::isFpOptionsPage() || Utils::isFpMigratePage()) {
        echo '<div class="notice notice-warning"><p>' . wp_kses($message, ['a' => ['href' => []]]) . '</p></div>';
        return;
    }

    if (get_user_meta(get_current_user_id(), 'flareload_setup_notice_dismissed', true)) {
        return;
    }

    echo '<div class="notice notice-warning is-dismissible" id="flareload-setup-notice"><p>' . wp_kses($message, ['a' => ['href' => []]]) . '</p></div>';
}

function FLARELOAD_enqueue_dismiss_notice_script(): void
{
    if (Utils::isFpOptionsPage() || Utils::isFpMigratePage()) {
        return;
    }

    if (get_user_meta(get_current_user_id(), 'flareload_setup_notice_dismissed', true)) {
        return;
    }

    $nonce    = wp_create_nonce('flareload_dismiss_setup_notice');
    $ajax_url = wp_json_encode(admin_url('admin-ajax.php'));
    $nonce_js = esc_js($nonce);

    wp_register_script('flareload-dismiss-notice', false, [], FLARELOAD_VERSION, true);
    wp_enqueue_script('flareload-dismiss-notice');
    wp_add_inline_script('flareload-dismiss-notice',
        'document.addEventListener("DOMContentLoaded", function() {' .
        '  var notice = document.getElementById("flareload-setup-notice");' .
        '  if (!notice) return;' .
        '  notice.addEventListener("click", function(e) {' .
        '    if (e.target.classList.contains("notice-dismiss")) {' .
        '      fetch(' . $ajax_url . ', {' .
        '        method: "POST",' .
        '        headers: {"Content-Type": "application/x-www-form-urlencoded"},' .
        '        body: "action=flareload_dismiss_setup_notice&nonce=' . $nonce_js . '"' .
        '      });' .
        '    }' .
        '  });' .
        '});'
    );
}

function FlareLoadInit(): void
{
    $credentialsComplete = FLARELOAD_has_complete_credentials();

    if (is_user_logged_in()) {
        add_action('admin_menu', fn() => new OptionController($credentialsComplete));
        add_action('admin_enqueue_scripts', 'FLARELOAD_admin_enqueue_scripts');
        add_action('wp_ajax_flareload_test_connection', 'FLARELOAD_ajax_test_connection');
        add_filter('pre_update_option_' . Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, 'FLARELOAD_pre_update_option_save_api_token', 10, 2);
        add_action('add_option_'    . Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, 'FLARELOAD_on_api_token_added',   10, 2);
        add_action('update_option_' . Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, 'FLARELOAD_on_api_token_updated', 10, 2);

        if (!$credentialsComplete) {
            add_action('admin_notices', 'FLARELOAD_incomplete_setup_notice');
            add_action('admin_enqueue_scripts', 'FLARELOAD_enqueue_dismiss_notice_script');
            add_action('wp_ajax_flareload_dismiss_setup_notice', 'FLARELOAD_ajax_dismiss_setup_notice');
            return;
        }

        add_action('wp_ajax_flareload_migrate_analyze',      'FLARELOAD_ajax_migrate_analyze');
        add_action('wp_ajax_flareload_migrate_list',         'FLARELOAD_ajax_migrate_list');
        add_action('wp_ajax_flareload_migrate_start',        'FLARELOAD_ajax_migrate_start');
        add_action('wp_ajax_flareload_migrate_process',      'FLARELOAD_ajax_migrate_process');
        add_action('wp_ajax_flareload_migrate_check_locks',  'FLARELOAD_ajax_migrate_check_locks');
        add_action('wp_ajax_flareload_migrate_get_state', 'FLARELOAD_ajax_migrate_get_state');
        add_action('wp_ajax_flareload_migrate_cancel',    'FLARELOAD_ajax_migrate_cancel');
        add_action('rest_api_init', 'FLARELOAD_rest_api_init');
        add_filter('render_block', 'FLARELOAD_render_block', 10, 2);
        add_filter('manage_media_columns', 'FLARELOAD_manage_media_columns');
        add_filter('manage_media_custom_column', 'FLARELOAD_manage_media_custom_column', 10, 2);
        add_action('restrict_manage_posts', 'FLARELOAD_restrict_manage_media_location');
        add_action('pre_get_posts', 'FLARELOAD_pre_get_posts_location_filter');
        add_filter('pre_delete_attachment', 'FLARELOAD_pre_delete_attachment', 10, 3);
        add_action('add_attachment', 'FLARELOAD_add_attachment', 1, 3);
        add_action('admin_notices', 'FLARELOAD_admin_upload_error_notice');
        add_filter('heartbeat_received', 'FLARELOAD_heartbeat_upload_error', 10, 2);
        add_action('wp_ajax_flareload_check_upload_error', 'FLARELOAD_ajax_check_upload_error');
        add_action('admin_init', 'FLARELOAD_maybe_run_backfill');
    }

    if (!$credentialsComplete) {
        return;
    }

    add_filter('ajax_query_attachments_args', 'FLARELOAD_ajax_query_attachments_args');

    add_filter('wp_prepare_attachment_for_js', 'FLARELOAD_wp_prepare_attachment_for_js', 5, 3);
    add_filter('wp_get_attachment_image', 'FLARELOAD_wp_get_attachment_image', 15, 5);
    add_filter('wp_get_attachment_image_src', 'FLARELOAD_wp_get_attachment_image_src', 10, 4);
    add_filter('wp_get_attachment_url', 'FLARELOAD_wp_get_attachment_url', 5, 2);
    add_filter('get_attached_file', 'FLARELOAD_get_attached_file', 10, 2);
    add_filter('get_sample_permalink_html', 'FLARELOAD_get_sample_permalink_html', 10, 5);
}

function FLARELOAD_get_sample_permalink_html(string $return, int $postId, string|null $newTitle, string|null $newSlug, WP_Post $post): string
{
    if (Utils::isAdminPage('post.php')) {
        $cfId = AttachmentController::getCloudflareIdOfAttachment($postId);

        if ($cfId) {
            $cfUrl = AttachmentController::getDefaultVariantUrl($cfId);

            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($return, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $a = $dom->getElementsByTagName('a')->item(0);
            $a->setAttribute('href', $cfUrl);
            $a->nodeValue = $cfUrl;

            $dom->appendChild($a);

            return $dom->saveHTML();
        }
    }

    return $return;
}

function FLARELOAD_get_attached_file(string $file, int $attachmentId): string
{
    if (Utils::isAdminPage('post.php')) {
        $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);
        if ($cfId) {
            $file = str_replace(wp_basename($file), get_the_title($attachmentId), $file);
        }
    }

    return $file;
}

function FLARELOAD_pre_update_option_save_api_token(mixed $newValue, mixed $oldValue): mixed
{
    if (empty(trim($newValue))) {
        return $oldValue;
    }

    return $newValue;
}

// add_option_{option} fires when the option is created for the very first time.
function FLARELOAD_on_api_token_added(string $option, string $value): void
{
    FLARELOAD_maybe_auto_sync_on_first_save($value);
}

// update_option_{option} fires when the option already exists and its value changes.
function FLARELOAD_on_api_token_updated(string $oldValue, string $newValue): void
{
    FLARELOAD_maybe_auto_sync_on_first_save($newValue);
}

function FLARELOAD_maybe_auto_sync_on_first_save(string $newToken): void
{
    if (empty($newToken)) {
        return;
    }

    // Skip if variants were already synced — this is not a first-time save.
    if (!empty(get_option(Constants::DASHBOARD_VARIANT_LIST_FIELD_NAME))) {
        return;
    }

    // All three credentials must be present.
    if (empty(get_option(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME))
        || empty(get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME))) {
        return;
    }

    $variants = OptionController::syncVariants();

    if (!empty($variants) && empty(get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME))) {
        $first = array_key_first($variants);
        if ($first) {
            update_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME, $first);
        }
    }
}

function FLARELOAD_render_block($blockContent, $block)
{
    if ($block['blockName'] !== 'core/image') {
        return $blockContent;
    }

    if (empty($block['attrs']['cloudflareVariant'])) {
        return $blockContent;
    }

    $imageId = $block['attrs']['id'] ?? null;

    if (!$imageId) {
        return $blockContent;
    }

    $cfImageId = AttachmentController::getCloudflareIdOfAttachment($imageId);

    if (!$cfImageId) {
        return $blockContent;
    }

    $variant = $block['attrs']['cloudflareVariant'];
    $variantUrl = AttachmentController::getVariantUrl($variant, $cfImageId);

    $blockContent = preg_replace(
        '/src="[^"]*"/',
        'src="' . esc_url($variantUrl) . '"',
        $blockContent,
        1
    );

    $variantClass = 'cf-variant-' . esc_attr($variant);
    $blockContent = preg_replace(
        '/class="([^"]*)"/',
        'class="$1 ' . $variantClass . '"',
        $blockContent,
        1
    );

    return $blockContent;
}

function FLARELOAD_rest_api_init(): void
{
    // Restricted to manage_options; triggered by the "Sync Variants" button in settings.
    if(current_user_can('manage_options')) {
        register_rest_route('flare-load/v1', '/sync-variants', array(
            'methods' => 'POST',
            'callback' => [OptionRestApi::class, 'syncVariants'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    // Used by post editor blocks that insert CF images.
    register_rest_route('flare-load/v1', '/get-variant-names', array(
        'methods' => 'GET',
        'callback' => [OptionRestApi::class, 'getVariantNames'],
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));

    // Used by the post editor to build CF image URLs client-side.
    register_rest_route('flare-load/v1', '/get-account-hash', array(
        'methods' => 'GET',
        'callback' => [OptionRestApi::class, 'getAccountHash'],
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));

    register_rest_field('attachment', 'flareload_cf_image_id', array(
        'get_callback' => function ($object) {
            $imageId = $object['id'];
            return AttachmentController::getCloudflareIdOfAttachment($imageId);
        },
        'update_callback' => null,
        'schema' => array(
            'description' => 'Cloudflare Image ID',
            'type' => 'string',
            'context' => array('view', 'edit')
        )
    ));

    register_rest_field('attachment', 'flareload_upload_error', array(
        'get_callback' => function ($object) {
            $error = get_post_meta($object['id'], '_flareload_upload_error', true);
            if ($error) {
                delete_post_meta($object['id'], '_flareload_upload_error');
                return $error;
            }
            return null;
        },
        'update_callback' => null,
        'schema' => array(
            'description' => 'FlareLoad CF upload error',
            'type' => 'string',
            'context' => array('view', 'edit')
        )
    ));
}

function FLARELOAD_wp_prepare_attachment_for_js(array $response, WP_Post $attachment): array
{
    if (AttachmentController::getCloudflareIdOfAttachment($attachment->ID)) {
        $response = AttachmentController::updateAjaxQueryResponse($response, $attachment);
    }

    $error = get_post_meta($attachment->ID, '_flareload_upload_error', true);
    if ($error) {
        delete_post_meta($attachment->ID, '_flareload_upload_error');
        $response['flareload_upload_error'] = $error;
    }

    return $response;
}

function FLARELOAD_wp_get_attachment_image(string $html, int $attachmentId): string
{
    $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

    if ($cfId) {
        $html = AttachmentController::updateQueriedAttachmentHtml($attachmentId, $cfId, $html);
    }

    return $html;
}

function FLARELOAD_wp_get_attachment_image_src(array|false $image, int $attachmentId): array|false
{
    $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

    if (!$cfId) {
        return $image;
    }

    if (Utils::isMediaEditPage()) {
        $image[0] = AttachmentController::getDefaultVariantUrl($cfId);
    } else {
        $path = AttachmentController::getCfThumbnail($attachmentId)['path'] ?? '';
        if ($path) {
            $uploads  = wp_get_upload_dir();
            $image[0] = str_replace(wp_normalize_path($uploads['basedir']), $uploads['baseurl'], wp_normalize_path($path));
        }
    }

    return $image;
}

function FLARELOAD_wp_get_attachment_url(string $attachmentUrl, int $attachmentId): string
{
    $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

    if (AttachmentController::getCloudflareIdOfAttachment($attachmentId)) {
        $attachmentUrl = AttachmentController::getDefaultVariantUrl($cfId);
    }

    return $attachmentUrl;
}

function FLARELOAD_add_attachment(int $attachmentId): void
{
    AttachmentController::handleAddAttachment($attachmentId);
}

function FLARELOAD_admin_upload_error_notice(): void
{
    $key = 'flareload_upload_error_' . get_current_user_id();
    if (get_transient($key)) {
        delete_transient($key);
        $message = __('Upload to Cloudflare failed. The image was saved locally. Check FlareLoad logs for details.', 'flare-load');
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}

function FLARELOAD_heartbeat_upload_error(array $response, array $data): array
{
    $key = 'flareload_upload_error_' . get_current_user_id();
    if (get_transient($key)) {
        delete_transient($key);
        $response['flareload_upload_error'] = true;
    }
    return $response;
}

function FLARELOAD_ajax_migrate_analyze(): void
{
    check_ajax_referer('flareload_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    $scope       = sanitize_key(wp_unslash($_POST['scope'] ?? 'posts'));
    $selectedIds = array_map('intval', (array) wp_unslash($_POST['ids'] ?? []));

    wp_send_json_success(MigrationController::analyzeImages($scope, $selectedIds));
}

function FLARELOAD_ajax_migrate_list(): void
{
    check_ajax_referer('flareload_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    $scope   = sanitize_key(wp_unslash($_POST['scope'] ?? 'all'));
    $page    = max(1, absint(wp_unslash($_POST['page']     ?? 1)));
    $perPage = max(1, min(100, absint(wp_unslash($_POST['per_page'] ?? 20))));

    wp_send_json_success(MigrationController::listImages($scope, $page, $perPage));
}

function FLARELOAD_ajax_migrate_start(): void
{
    check_ajax_referer('flareload_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    $scope        = sanitize_key(wp_unslash($_POST['scope'] ?? 'posts'));
    $selectedIds  = array_map('intval', (array) wp_unslash($_POST['ids'] ?? []));
    $variant      = sanitize_text_field(wp_unslash($_POST['variant'] ?? ''));
    $deleteFromCF = !empty($_POST['delete_from_cf']);

    $analysis = MigrationController::analyzeImages($scope, $selectedIds);

    $queue = array_column(
        array_filter($analysis['images'], fn($img) => $img['status'] !== 'no_variant'),
        'id'
    );

    $state = [
        'remaining'     => array_values($queue),
        'processed'     => [],
        'failed'        => [],
        'options'       => ['variant' => $variant, 'delete_from_cf' => $deleteFromCF],
    ];

    set_transient('flareload_migration_state', $state, DAY_IN_SECONDS);

    wp_send_json_success($state);
}

function FLARELOAD_ajax_migrate_process(): void
{
    ob_start();

    check_ajax_referer('flareload_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        ob_end_clean();
        wp_send_json_error(null, 403);
        return;
    }

    $id           = absint(wp_unslash($_POST['id'] ?? 0));
    $variant      = sanitize_text_field(wp_unslash($_POST['variant'] ?? ''));
    $deleteFromCF = !empty($_POST['delete_from_cf']);

    if (!$id || empty($variant)) {
        ob_end_clean();
        wp_send_json_error(['message' => __('Missing parameters.', 'flare-load')]);
        return;
    }

    $result = MigrationController::processImage($id, $variant, $deleteFromCF);

    $state = get_transient('flareload_migration_state');
    if ($state) {
        $state['remaining'] = array_values(array_filter($state['remaining'], fn($i) => $i !== $id));
        if ($result['status'] === 'error') {
            $state['failed'][]    = ['id' => $id, 'reason' => $result['reason'] ?? ''];
        } else {
            $state['processed'][] = $id;
        }
        set_transient('flareload_migration_state', $state, DAY_IN_SECONDS);
    }

    ob_end_clean();
    wp_send_json_success($result);
}

function FLARELOAD_ajax_migrate_get_state(): void
{
    check_ajax_referer('flareload_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    wp_send_json_success(get_transient('flareload_migration_state') ?: null);
}

function FLARELOAD_ajax_migrate_cancel(): void
{
    check_ajax_referer('flareload_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    delete_transient('flareload_migration_state');
    wp_send_json_success();
}

function FLARELOAD_ajax_migrate_check_locks(): void
{
    check_ajax_referer('flareload_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    global $wpdb;

    $threshold = time() - 60;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time lock check; result must not be cached.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_edit_lock'
               AND CAST(SUBSTRING_INDEX(pm.meta_value, ':', 1) AS UNSIGNED) > %d
               AND p.post_status NOT IN ('auto-draft', 'trash')",
            $threshold
        )
    );

    $locked = array_map(fn($r) => [
        'id'    => (int) $r->ID,
        'title' => $r->post_title !== '' ? $r->post_title : __('(no title)', 'flare-load'),
    ], $rows);

    wp_send_json_success($locked);
}

function FLARELOAD_ajax_test_connection(): void
{
    check_ajax_referer('flareload_test_connection', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
        return;
    }
    $accountId = get_option(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);
    if (empty($accountId)) {
        wp_send_json_error(['message' => __('Account ID is required to test the connection.', 'flare-load')]);
        return;
    }
    $testToken = isset($_POST['flareload_test_token']) ? sanitize_text_field(wp_unslash($_POST['flareload_test_token'])) : null;
    try {
        CloudflareImagesApi::getVariants($testToken);
        wp_send_json_success();
    } catch (Exception $e) {
        Logger::log(0, '[TEST_CONNECTION] ' . $e->getMessage());
        wp_send_json_error();
    }
}

function FLARELOAD_ajax_check_upload_error(): void
{
    if (!current_user_can('upload_files')) {
        wp_send_json_error();
        return;
    }
    $key = 'flareload_upload_error_' . get_current_user_id();
    if (get_transient($key)) {
        delete_transient($key);
        wp_send_json_success(true);
    }
    wp_send_json_success(false);
}

function FLARELOAD_ajax_dismiss_setup_notice(): void
{
    check_ajax_referer('flareload_dismiss_setup_notice', 'nonce');
    update_user_meta(get_current_user_id(), 'flareload_setup_notice_dismissed', '1');
    wp_send_json_success();
}

function FLARELOAD_restrict_manage_media_location(string $postType): void
{
    if ($postType !== 'attachment') {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading $_GET for filter dropdown state; no data is modified.
    $selected = sanitize_key(wp_unslash($_GET['flareload_location'] ?? ''));
    ?>
    <select name="flareload_location">
        <option value=""><?php echo esc_html(__('All locations', 'flare-load')); ?></option>
        <option value="cloudflare" <?php selected($selected, 'cloudflare'); ?>><?php echo esc_html(__('Uploaded to Cloudflare', 'flare-load')); ?></option>
        <option value="server" <?php selected($selected, 'server'); ?>><?php echo esc_html(__('This server', 'flare-load')); ?></option>
    </select>
    <?php
}

function FLARELOAD_pre_get_posts_location_filter(WP_Query $query): void
{
    global $pagenow;

    if (!is_admin() || !$query->is_main_query() || $pagenow !== 'upload.php') {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading $_GET for media library filter; no data is modified.
    $location = sanitize_key(wp_unslash($_GET['flareload_location'] ?? ''));

    if ($location === 'cloudflare') {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter media library by CF meta key; no alternative without direct DB.
        $query->set('meta_query', [
            'relation' => 'AND',
            [
                'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
                'compare' => 'EXISTS',
            ],
            [
                'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
                'value'   => '',
                'compare' => '!=',
            ],
        ]);
    } elseif ($location === 'server') {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $query->set('meta_query', [[
            'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
            'compare' => 'NOT EXISTS',
        ]]);
    }
}

function FLARELOAD_ajax_query_attachments_args(array $query): array
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading AJAX query parameter for media filter; actual media queries are handled by WordPress core.
    $location = sanitize_key($_REQUEST['query']['flareload_location'] ?? '');

    if ($location === 'cloudflare') {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter AJAX media query by CF meta key.
        $query['meta_query'] = [
            'relation' => 'AND',
            [
                'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
                'compare' => 'EXISTS',
            ],
            [
                'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
                'value'   => '',
                'compare' => '!=',
            ],
        ];
    } elseif ($location === 'server') {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $query['meta_query'] = [[
            'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
            'compare' => 'NOT EXISTS',
        ]];
    }

    return $query;
}

function FLARELOAD_manage_media_columns(array $columns): array
{
    $columns[Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID] = __('Location', 'flare-load');

    return $columns;
}

function FLARELOAD_manage_media_custom_column(string $columnName, int $attachmentId): void
{
    OptionController::addLocationInfoToListViewRow($columnName, $attachmentId);
}


function FLARELOAD_admin_enqueue_scripts(): void
{
    wp_enqueue_style('flareload-main-style', FLARELOAD_URL . 'dist/css/flareload-main.css', [], FLARELOAD_VERSION);

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading $_GET['mode'] for script routing only; no data is modified.
    if (Utils::isAdminPage('upload.php') && (empty($_GET) || sanitize_key(wp_unslash($_GET['mode'] ?? '')) === 'grid')) {
        wp_enqueue_script('flareload-media-library-grid-script', FLARELOAD_URL . 'dist/main/flareload-media-library-grid.js', ['wp-i18n'], FLARELOAD_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        wp_localize_script('flareload-media-library-grid-script', 'flareloadConfig', ['pluginUrl' => FLARELOAD_URL, 'logsUrl' => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG), 'locationFilterLabels' => ['all' => __('All locations', 'flare-load'), 'cloudflare' => __('Uploaded to Cloudflare', 'flare-load'), 'server' => __('This server', 'flare-load')]]);
        wp_set_script_translations('flareload-media-library-grid-script', 'flare-load', FLARELOAD_PATH . 'languages');
    }

    if (Utils::isAdminPage('media-new.php')) {
        wp_enqueue_script('flareload-media-new-script', FLARELOAD_URL . 'dist/main/flareload-media-new.js', ['wp-i18n'], FLARELOAD_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        wp_localize_script('flareload-media-new-script', 'flareloadConfig', ['pluginUrl' => FLARELOAD_URL, 'logsUrl' => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG)]);
        wp_set_script_translations('flareload-media-new-script', 'flare-load', FLARELOAD_PATH . 'languages');
    }

    if (Utils::isFpOptionsPage()) {
        wp_enqueue_script('flareload-options-script', FLARELOAD_URL . 'dist/main/flareload-options.js', ['wp-i18n'], FLARELOAD_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        wp_localize_script('flareload-options-script', 'flareloadConfig', ['pluginUrl' => FLARELOAD_URL, 'logsUrl' => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG), 'testConnectionNonce' => wp_create_nonce('flareload_test_connection'), 'restNonce' => wp_create_nonce('wp_rest'), 'restUrl' => rest_url('flare-load/v1/')]);
        wp_set_script_translations('flareload-options-script', 'flare-load', FLARELOAD_PATH . 'languages');
    }

    if (Utils::isFpMigratePage()) {
        wp_enqueue_script('flareload-migrate-script', FLARELOAD_URL . 'dist/main/flareload-migrate.js', ['wp-i18n'], FLARELOAD_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        wp_localize_script('flareload-migrate-script', 'flareloadMigrateConfig', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('flareload_migrate'),
            'defaultVariant' => get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME, ''),
            'variantOptions' => OptionController::getVariantOptions(),
            'migrateUrl'     => admin_url('admin.php?page=' . Constants::DASHBOARD_MIGRATE_PAGE_SLUG),
            'logsUrl'        => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG),
        ]);
        wp_set_script_translations('flareload-migrate-script', 'flare-load', FLARELOAD_PATH . 'languages');
    }

    if ((Utils::isPostEditPage() || Utils::isAdminPage('post-new.php') || Utils::isAdminPage('site-editor.php')) && !Utils::isMediaEditPage()) {
        wp_enqueue_script('flareload-post-script', FLARELOAD_URL . 'dist/main/flareload-post.js', ['wp-i18n'], FLARELOAD_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        wp_localize_script('flareload-post-script', 'flareloadConfig', ['pluginUrl' => FLARELOAD_URL, 'logsUrl' => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG), 'defaultVariant' => get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME, ''), 'variantOptions' => OptionController::getVariantOptions(), 'accountHash' => OptionController::getAccountHash(), 'restUrl' => rest_url('flare-load/v1/')]);
        wp_set_script_translations('flareload-post-script', 'flare-load', FLARELOAD_PATH . 'languages');
    }
}

function FLARELOAD_pre_delete_attachment(WP_Post|false|null $delete, WP_Post $post, bool $forceDelete): WP_Post|false|null
{
    return AttachmentController::handleDeleteAttachment($delete, $post, $forceDelete);
}