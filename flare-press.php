<?php
/*
Plugin Name: FlarePress
Description: WordPress plugin for uploading media directly to Cloudflare Images alongside the default uploader.
Version:     1.0.0
Requires at least: 5.9
Requires PHP:      8.0
Author:      Yunus Tan
Author URI:  https://github.com/tanyunus/
License:     GPL-3.0+
Text Domain: flare-press
*/

use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Controllers\AttachmentController;
use FlarePress\Controllers\MigrationController;
use FlarePress\Controllers\OptionController;
use FlarePress\Data\Constants;
use FlarePress\Util\Logger;
use FlarePress\Util\Utils;
use FlarePress\RestApi\OptionRestApi;

if (!defined('WPINC')) {
    die;
}

define('FLARE_PRESS_VERSION', '1.0.0');
define('FLARE_PRESS_PATH', plugin_dir_path(__FILE__));
define('FLARE_PRESS_URL', plugin_dir_url(__FILE__));

require_once FLARE_PRESS_PATH . 'autoload.php';

register_activation_hook(__FILE__, 'fp_activate');

function fp_activate(): void {
    if (!get_option('fp_upload_settings')) {
        update_option('fp_upload_settings', [
            Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME       => false,
            Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME => false,
        ]);
    }

    fp_backfill_cf_post_meta();
}

function fp_maybe_run_backfill(): void {
    if (get_transient('fp_backfill_v1_done')) {
        return;
    }
    fp_backfill_cf_post_meta();
    set_transient('fp_backfill_v1_done', true, WEEK_IN_SECONDS);
}

function fp_backfill_cf_post_meta(): void {
    $attachments = get_posts([
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'fields'         => 'ids',
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

add_action('plugins_loaded', 'flarePressInit');

function fp_has_complete_credentials(): bool
{
    return !empty(get_option(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME))
        && !empty(get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME))
        && !empty(get_option(Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME));
}

function fp_incomplete_setup_notice(): void
{
    if (Utils::isFpOptionsPage()) {
        return;
    }

    $url     = admin_url('admin.php?page=' . Constants::DASHBOARD_MENU_SLUG);
    $message = sprintf(
        /* translators: %s: link to FlarePress settings page */
        __('FlarePress is not fully configured. Please complete your <a href="%s">Cloudflare settings</a> to enable all features.', 'flare-press'),
        esc_url($url)
    );
    echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses($message, ['a' => ['href' => []]]) . '</p></div>';
}

function flarePressInit(): void
{
    $credentialsComplete = fp_has_complete_credentials();

    if (is_user_logged_in()) {
        add_action('admin_menu', fn() => new OptionController($credentialsComplete));
        add_action('admin_enqueue_scripts', 'fp_admin_enqueue_scripts');
        add_action('wp_ajax_fp_test_connection', 'fp_ajax_test_connection');
        add_filter('pre_update_option_' . Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, 'fp_pre_update_option_save_api_token', 10, 2);

        if (!$credentialsComplete) {
            add_action('admin_notices', 'fp_incomplete_setup_notice');
            return;
        }

        add_action('wp_ajax_fp_migrate_analyze',   'fp_ajax_migrate_analyze');
        add_action('wp_ajax_fp_migrate_list',      'fp_ajax_migrate_list');
        add_action('wp_ajax_fp_migrate_start',     'fp_ajax_migrate_start');
        add_action('wp_ajax_fp_migrate_process',   'fp_ajax_migrate_process');
        add_action('wp_ajax_fp_migrate_get_state', 'fp_ajax_migrate_get_state');
        add_action('wp_ajax_fp_migrate_cancel',    'fp_ajax_migrate_cancel');
        add_action('rest_api_init', 'fp_rest_api_init');
        add_filter('render_block', 'fp_render_block', 10, 2);
        add_filter('manage_media_columns', 'fp_manage_media_columns');
        add_filter('manage_media_custom_column', 'fp_manage_media_custom_column', 10, 2);
        add_action('restrict_manage_posts', 'fp_restrict_manage_media_location');
        add_action('pre_get_posts', 'fp_pre_get_posts_location_filter');
        add_filter('pre_delete_attachment', 'fp_pre_delete_attachment', 10, 3);
        add_action('add_attachment', 'fp_add_attachment', 1, 3);
        add_action('admin_notices', 'fp_admin_upload_error_notice');
        add_filter('heartbeat_received', 'fp_heartbeat_upload_error', 10, 2);
        add_action('wp_ajax_fp_check_upload_error', 'fp_ajax_check_upload_error');
        add_action('admin_print_footer_scripts', 'fp_admin_print_footer_scripts');
        add_action('admin_init', 'fp_maybe_run_backfill');
    }

    if (!$credentialsComplete) {
        return;
    }

    add_filter('ajax_query_attachments_args', 'fp_ajax_query_attachments_args');

    add_filter('wp_prepare_attachment_for_js', 'fp_wp_prepare_attachment_for_js', 5, 3);
    add_filter('wp_get_attachment_image', 'fp_wp_get_attachment_image', 15, 5);
    add_filter('wp_get_attachment_image_src', 'fp_wp_get_attachment_image_src', 10, 4);
    add_filter('wp_get_attachment_url', 'fp_wp_get_attachment_url', 5, 2);
    add_filter('get_attached_file', 'fp_get_attached_file', 10, 2);
    add_filter('get_sample_permalink_html', 'fp_get_sample_permalink_html', 10, 5);
}

function fp_get_sample_permalink_html(string $return, int $postId, string|null $newTitle, string|null $newSlug, WP_Post $post): string
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

function fp_get_attached_file(string $file, int $attachmentId): string
{
    if (Utils::isAdminPage('post.php')) {
        $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);
        if ($cfId) {
            $file = str_replace(wp_basename($file), get_the_title($attachmentId), $file);
        }
    }

    return $file;
}

function fp_pre_update_option_save_api_token(mixed $newValue, mixed $oldValue): mixed
{
    if (empty(trim($newValue))) {
        return $oldValue;
    }

    return $newValue;
}

function fp_render_block($blockContent, $block)
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

function fp_rest_api_init(): void
{
    // Restricted to manage_options; triggered by the "Sync Variants" button in settings.
    if(current_user_can('manage_options')) {
        register_rest_route('flare-press/v1', '/sync-variants', array(
            'methods' => 'POST',
            'callback' => [OptionRestApi::class, 'syncVariants'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    // Used by post editor blocks that insert CF images.
    register_rest_route('flare-press/v1', '/get-variant-names', array(
        'methods' => 'GET',
        'callback' => [OptionRestApi::class, 'getVariantNames'],
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));

    // Used by the post editor to build CF image URLs client-side.
    register_rest_route('flare-press/v1', '/get-account-hash', array(
        'methods' => 'GET',
        'callback' => [OptionRestApi::class, 'getAccountHash'],
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));

    register_rest_field('attachment', 'fp_cf_image_id', array(
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

    register_rest_field('attachment', 'fp_upload_error', array(
        'get_callback' => function ($object) {
            $error = get_post_meta($object['id'], '_fp_upload_error', true);
            if ($error) {
                delete_post_meta($object['id'], '_fp_upload_error');
                return $error;
            }
            return null;
        },
        'update_callback' => null,
        'schema' => array(
            'description' => 'FlarePress CF upload error',
            'type' => 'string',
            'context' => array('view', 'edit')
        )
    ));
}

function fp_wp_prepare_attachment_for_js(array $response, WP_Post $attachment): array
{
    if (AttachmentController::getCloudflareIdOfAttachment($attachment->ID)) {
        $response = AttachmentController::updateAjaxQueryResponse($response, $attachment);
    }

    $error = get_post_meta($attachment->ID, '_fp_upload_error', true);
    if ($error) {
        delete_post_meta($attachment->ID, '_fp_upload_error');
        $response['fp_upload_error'] = $error;
    }

    return $response;
}

function fp_wp_get_attachment_image(string $html, int $attachmentId): string
{
    $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

    if ($cfId) {
        $html = AttachmentController::updateQueriedAttachmentHtml($attachmentId, $cfId, $html);
    }

    return $html;
}

function fp_wp_get_attachment_image_src(array|false $image, int $attachmentId): array|false
{
    $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

    if (!$cfId) {
        return $image;
    }

    if (Utils::isMediaEditPage()) {
        $image[0] = AttachmentController::getDefaultVariantUrl($cfId);
    } else {
        $image[0] = AttachmentController::getCfThumbnail($attachmentId)['path'];
    }

    return $image;
}

function fp_wp_get_attachment_url(string $attachmentUrl, int $attachmentId): string
{
    $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

    if (AttachmentController::getCloudflareIdOfAttachment($attachmentId)) {
        $attachmentUrl = AttachmentController::getDefaultVariantUrl($cfId);
    }

    return $attachmentUrl;
}

function fp_add_attachment(int $attachmentId): void
{
    AttachmentController::handleAddAttachment($attachmentId);
}

function fp_admin_upload_error_notice(): void
{
    $key = 'fp_upload_error_' . get_current_user_id();
    if (get_transient($key)) {
        delete_transient($key);
        $message = __('Upload to Cloudflare failed. The image was saved locally. Check FlarePress logs for details.', 'flare-press');
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}

function fp_heartbeat_upload_error(array $response, array $data): array
{
    $key = 'fp_upload_error_' . get_current_user_id();
    if (get_transient($key)) {
        delete_transient($key);
        $response['fp_upload_error'] = true;
    }
    return $response;
}

function fp_ajax_migrate_analyze(): void
{
    check_ajax_referer('fp_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    $scope       = sanitize_key(wp_unslash($_POST['scope'] ?? 'posts'));
    $selectedIds = array_map('intval', (array) wp_unslash($_POST['ids'] ?? []));

    wp_send_json_success(MigrationController::analyzeImages($scope, $selectedIds));
}

function fp_ajax_migrate_list(): void
{
    check_ajax_referer('fp_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    $scope   = sanitize_key(wp_unslash($_POST['scope'] ?? 'all'));
    $page    = max(1, absint(wp_unslash($_POST['page']     ?? 1)));
    $perPage = max(1, min(100, absint(wp_unslash($_POST['per_page'] ?? 20))));

    wp_send_json_success(MigrationController::listImages($scope, $page, $perPage));
}

function fp_ajax_migrate_start(): void
{
    check_ajax_referer('fp_migrate', 'nonce');
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

    set_transient('fp_migration_state', $state, DAY_IN_SECONDS);

    wp_send_json_success($state);
}

function fp_ajax_migrate_process(): void
{
    ob_start();

    check_ajax_referer('fp_migrate', 'nonce');
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
        wp_send_json_error(['message' => __('Missing parameters.', 'flare-press')]);
        return;
    }

    $result = MigrationController::processImage($id, $variant, $deleteFromCF);

    $state = get_transient('fp_migration_state');
    if ($state) {
        $state['remaining'] = array_values(array_filter($state['remaining'], fn($i) => $i !== $id));
        if ($result['status'] === 'error') {
            $state['failed'][]    = ['id' => $id, 'reason' => $result['reason'] ?? ''];
        } else {
            $state['processed'][] = $id;
        }
        set_transient('fp_migration_state', $state, DAY_IN_SECONDS);
    }

    ob_end_clean();
    wp_send_json_success($result);
}

function fp_ajax_migrate_get_state(): void
{
    check_ajax_referer('fp_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    wp_send_json_success(get_transient('fp_migration_state') ?: null);
}

function fp_ajax_migrate_cancel(): void
{
    check_ajax_referer('fp_migrate', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
        return;
    }

    delete_transient('fp_migration_state');
    wp_send_json_success();
}

function fp_ajax_test_connection(): void
{
    check_ajax_referer('fp_test_connection', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
        return;
    }
    $accountId = get_option(Constants::DASHBOARD_CF_ACCOUNT_ID_FIELD_NAME);
    if (empty($accountId)) {
        wp_send_json_error(['message' => __('Account ID is required to test the connection.', 'flare-press')]);
        return;
    }
    $testToken = isset($_POST['fp_test_token']) ? sanitize_text_field(wp_unslash($_POST['fp_test_token'])) : null;
    try {
        CloudflareImagesApi::getVariants($testToken);
        wp_send_json_success();
    } catch (Exception $e) {
        Logger::log(0, '[TEST_CONNECTION] ' . $e->getMessage());
        wp_send_json_error();
    }
}

function fp_ajax_check_upload_error(): void
{
    if (!current_user_can('upload_files')) {
        wp_send_json_error();
        return;
    }
    $key = 'fp_upload_error_' . get_current_user_id();
    if (get_transient($key)) {
        delete_transient($key);
        wp_send_json_success(true);
    }
    wp_send_json_success(false);
}

function fp_restrict_manage_media_location(string $postType): void
{
    if ($postType !== 'attachment') {
        return;
    }

    $selected = sanitize_key(wp_unslash($_GET['fp_location'] ?? ''));
    ?>
    <select name="fp_location">
        <option value=""><?php echo esc_html(Utils::localize(Constants::UI_LOCATION_FILTER_ALL)); ?></option>
        <option value="cloudflare" <?php selected($selected, 'cloudflare'); ?>><?php echo esc_html(Utils::localize(Constants::UI_CF_BADGE_TITLE)); ?></option>
        <option value="server" <?php selected($selected, 'server'); ?>><?php echo esc_html(Utils::localize(Constants::UI_CF_LOCATION_THIS_SERVER)); ?></option>
    </select>
    <?php
}

function fp_pre_get_posts_location_filter(WP_Query $query): void
{
    global $pagenow;

    if (!is_admin() || !$query->is_main_query() || $pagenow !== 'upload.php') {
        return;
    }

    $location = sanitize_key(wp_unslash($_GET['fp_location'] ?? ''));

    if ($location === 'cloudflare') {
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
        $query->set('meta_query', [[
            'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
            'compare' => 'NOT EXISTS',
        ]]);
    }
}

function fp_ajax_query_attachments_args(array $query): array
{
    $location = sanitize_key($_REQUEST['query']['fp_location'] ?? '');

    if ($location === 'cloudflare') {
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
        $query['meta_query'] = [[
            'key'     => Constants::UPLOADED_IMAGE_CF_ID_NAME,
            'compare' => 'NOT EXISTS',
        ]];
    }

    return $query;
}

function fp_manage_media_columns(array $columns): array
{
    $columns[Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID] = Utils::localize(Constants::UI_CF_LOCATION_COLUMN_NAME);

    return $columns;
}

function fp_manage_media_custom_column(string $columnName, int $attachmentId): void
{
    OptionController::addLocationInfoToListViewRow($columnName, $attachmentId);
}


function fp_admin_print_footer_scripts(): void
{
    if (Utils::isAdminPage('upload.php') && (empty($_GET) || sanitize_key(wp_unslash($_GET['mode'] ?? '')) === 'grid')) {
        wp_enqueue_script('fp-media-library-grid-script', FLARE_PRESS_URL . 'dist/main/fp-media-library-grid.js', ['wp-i18n'], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-media-library-grid-script', 'fpConfig', ['pluginUrl' => FLARE_PRESS_URL, 'logsUrl' => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG), 'locationFilterLabels' => ['all' => Utils::localize(Constants::UI_LOCATION_FILTER_ALL), 'cloudflare' => Utils::localize(Constants::UI_CF_BADGE_TITLE), 'server' => Utils::localize(Constants::UI_CF_LOCATION_THIS_SERVER)]]);
        wp_set_script_translations('fp-media-library-grid-script', 'flare-press', FLARE_PRESS_PATH . 'languages');
    }

    if (Utils::isAdminPage('media-new.php')) {
        wp_enqueue_script('fp-media-new-script', FLARE_PRESS_URL . 'dist/main/fp-media-new.js', ['wp-i18n'], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-media-new-script', 'fpConfig', ['pluginUrl' => FLARE_PRESS_URL, 'logsUrl' => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG)]);
        wp_set_script_translations('fp-media-new-script', 'flare-press', FLARE_PRESS_PATH . 'languages');
    }

    if (Utils::isFpOptionsPage()) {
        wp_enqueue_script('fp-options-script', FLARE_PRESS_URL . 'dist/main/fp-options.js', ['wp-i18n'], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-options-script', 'fpConfig', ['pluginUrl' => FLARE_PRESS_URL, 'logsUrl' => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG), 'testConnectionNonce' => wp_create_nonce('fp_test_connection'), 'restNonce' => wp_create_nonce('wp_rest'), 'restUrl' => rest_url('flare-press/v1/')]);
        wp_set_script_translations('fp-options-script', 'flare-press', FLARE_PRESS_PATH . 'languages');
    }

    if (Utils::isFpMigratePage()) {
        wp_enqueue_script('fp-migrate-script', FLARE_PRESS_URL . 'dist/main/fp-migrate.js', ['wp-i18n'], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-migrate-script', 'fpMigrateConfig', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('fp_migrate'),
            'defaultVariant' => get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME, ''),
            'variantOptions' => OptionController::getVariantOptions(),
            'migrateUrl'     => admin_url('admin.php?page=' . Constants::DASHBOARD_MIGRATE_PAGE_SLUG),
            'logsUrl'        => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG),
        ]);
        wp_set_script_translations('fp-migrate-script', 'flare-press', FLARE_PRESS_PATH . 'languages');
    }

    if ((Utils::isPostEditPage() || Utils::isAdminPage('post-new.php') || Utils::isAdminPage('site-editor.php')) && !Utils::isMediaEditPage()) {
        wp_enqueue_script('fp-post-script', FLARE_PRESS_URL . 'dist/main/fp-post.js', ['wp-i18n'], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-post-script', 'fpConfig', ['pluginUrl' => FLARE_PRESS_URL, 'logsUrl' => admin_url('admin.php?page=' . Constants::DASHBOARD_LOG_PAGE_SLUG), 'defaultVariant' => get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME, ''), 'variantOptions' => OptionController::getVariantOptions(), 'accountHash' => OptionController::getAccountHash(), 'restUrl' => rest_url('flare-press/v1/')]);
        wp_set_script_translations('fp-post-script', 'flare-press', FLARE_PRESS_PATH . 'languages');
    }
}

function fp_admin_enqueue_scripts(): void
{
    wp_enqueue_style('fp-main-style', FLARE_PRESS_URL . 'dist/css/fp-main.css', [], FLARE_PRESS_VERSION);
}

function fp_pre_delete_attachment(WP_Post|false|null $delete, WP_Post $post, bool $forceDelete): WP_Post|false|null
{
    return AttachmentController::handleDeleteAttachment($delete, $post, $forceDelete);
}