<?php
/*
Plugin Name: FlarePress
Description: WordPress plugin for uploading media directly to Cloudflare Images alongside the default uploader.
Version:     0.1.0
Author:      Yunus Tan
Author URI:  https://github.com/tanyunus/
License:     GPL-2.0+
Text Domain: flare-press
*/

use FlarePress\Controllers\AttachmentController;
use FlarePress\Controllers\OptionController;
use FlarePress\Data\Constants;
use FlarePress\Util\Utils;
use FlarePress\RestApi\OptionRestApi;

if (!defined('WPINC')) {
    die;
}

define('FLARE_PRESS_VERSION', '0.1.0');
define('FLARE_PRESS_PATH', plugin_dir_path(__FILE__));
define('FLARE_PRESS_URL', plugin_dir_url(__FILE__));

require_once FLARE_PRESS_PATH . 'autoload.php';

register_activation_hook(__FILE__, 'fp_activate');

function fp_activate(): void {
    // Set default option values if not already configured
    if (!get_option('fp_upload_settings')) {
        update_option('fp_upload_settings', [
            Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME       => false,
            Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME => false,
        ]);
    }
}

add_action('plugins_loaded', 'flarePressInit');

function flarePressInit(): void
{
    if (is_user_logged_in()) {
        add_action('rest_api_init', 'fp_rest_api_init');
        add_filter('render_block', 'fp_render_block', 10, 2);
        add_filter('manage_media_columns', 'fp_manage_media_columns');
        add_filter('manage_media_custom_column', 'fp_manage_media_custom_column', 10, 2);
        add_filter('pre_delete_attachment', 'fp_pre_delete_attachment', 10, 3);
        add_action('add_attachment', 'fp_add_attachment', 1, 3);
        add_action('admin_menu', 'fp_admin_menu');
        add_action('admin_print_footer_scripts', 'fp_admin_print_footer_scripts');
        add_action('admin_enqueue_scripts', 'fp_admin_enqueue_scripts');
        add_filter('pre_update_option_' . Constants::DASHBOARD_CF_API_TOKEN_FIELD_NAME, 'fp_pre_update_option_save_api_token', 10, 2);
    }

    # Public filters
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

/**
 * Modify basename before it's being returned.
 */
function fp_get_attached_file(string $file, int $attachmentId): string
{
    //  1. Check if it's post editing page
    if (Utils::isAdminPage('post.php')) {
        $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);
        // 2. Check if image is uploaded to Cloudflare
        if ($cfId) {
            // 3. Replace basename with file's actual name stored in db
            $file = str_replace(wp_basename($file), get_the_title($attachmentId), $file);
        }
    }

    return $file;
}

/**
 * Modify api token submission value if empty and save old data.
 */
function fp_pre_update_option_save_api_token(mixed $newValue, mixed $oldValue): mixed
{
    if (empty(trim($newValue))) {
        return $oldValue;
    }

    return $newValue;
}

/**
 * Modify block attributes before they're rendered to contain plugin features.
 */
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

/**
 * Add new rest api endpoints
 */
function fp_rest_api_init(): void
{
    # Provides variant synchronization through rest api if the current user
    # is capable of managing options.
    #
    # In plugin settings page we have a 'Sync Variants' button.
    # This button is tied to this endpoint and triggers variant sync process.
    if(current_user_can('manage_options')) {
        register_rest_route('flare-press/v1', '/sync-variants', array(
            'methods' => 'POST',
            'callback' => [OptionRestApi::class, 'syncVariants'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    # Provides variant names as array.
    # Used in post editor blocks contain image insertion mechanics.
    register_rest_route('flare-press/v1', '/get-variant-names', array(
        'methods' => 'GET',
        'callback' => [OptionRestApi::class, 'getVariantNames'],
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));

    # Provides Cloudflare account hash.
    # Used in post editor for generating Cloudflare image urls on the fly.
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
}

/**
 * Modifying ajax response right after upload and before sending it
 * */
function fp_wp_prepare_attachment_for_js(array $response, WP_Post $attachment): array
{
    if (AttachmentController::getCloudflareIdOfAttachment($attachment->ID)) {
        $response = AttachmentController::updateAjaxQueryResponse($response, $attachment);
    }

    return $response;
}

/**
 * Modify HTML img element before rendering image
 */
function fp_wp_get_attachment_image(string $html, int $attachmentId): string
{
    $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

    if ($cfId) {
        $html = AttachmentController::updateQueriedAttachmentHtml($attachmentId, $cfId, $html);
    }

    return $html;
}

/**
 * Modify attachment source before rendering image
 */
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

/**
 * Modify attachment url
 */
function fp_wp_get_attachment_url(string $attachmentUrl, int $attachmentId): string
{
    $cfId = AttachmentController::getCloudflareIdOfAttachment($attachmentId);

    if (AttachmentController::getCloudflareIdOfAttachment($attachmentId)) {
        $attachmentUrl = AttachmentController::getDefaultVariantUrl($cfId);
    }

    return $attachmentUrl;
}

/**
 * Control attachment upload process
 */
function fp_add_attachment(int $attachmentId): void
{
    AttachmentController::handleAddAttachment($attachmentId);
}

/**
 * Add Location column to media library list view
 */
function fp_manage_media_columns(array $columns): array
{
    $columns[Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID] = Utils::localize(Constants::UI_CF_LOCATION_COLUMN_NAME);

    return $columns;
}

/**
 * Add location info under Location column in media library list view
 */
function fp_manage_media_custom_column(string $columnName, int $attachmentId): void
{
    OptionController::addLocationInfoToListViewRow($columnName, $attachmentId);
}

/**
 * Initialize dashboard
 */
function fp_admin_menu(): void
{
    new OptionController();
}

/**
 * Add scripts for dashboard
 */
function fp_admin_print_footer_scripts(): void
{
    if (Utils::isAdminPage('upload.php') && (empty($_GET) || sanitize_key(wp_unslash($_GET['mode'] ?? '')) === 'grid')) {
        wp_enqueue_script('fp-media-library-grid-script', FLARE_PRESS_URL . 'includes/dist/main/fp-media-library-grid.js', [], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-media-library-grid-script', 'fpConfig', ['pluginUrl' => FLARE_PRESS_URL]);
    }

    if (Utils::isAdminPage('media-new.php')) {
        wp_enqueue_script('fp-media-new-script', FLARE_PRESS_URL . 'includes/dist/main/fp-media-new.js', [], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-media-new-script', 'fpConfig', ['pluginUrl' => FLARE_PRESS_URL]);
    }

    if (Utils::isFpOptionsPage()) {
        wp_enqueue_script('fp-options-script', FLARE_PRESS_URL . 'includes/dist/main/fp-options.js', [], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-options-script', 'fpConfig', ['pluginUrl' => FLARE_PRESS_URL]);
    }

    if ((Utils::isPostEditPage() || Utils::isAdminPage('post-new.php')) && !Utils::isMediaEditPage()) {
        wp_enqueue_script('fp-post-script', FLARE_PRESS_URL . 'includes/dist/main/fp-post.js', [], FLARE_PRESS_VERSION, true);
        wp_localize_script('fp-post-script', 'fpConfig', ['pluginUrl' => FLARE_PRESS_URL]);
    }
}

/**
 * Add styles for dashboard
 */
function fp_admin_enqueue_scripts(): void
{
    wp_enqueue_style('fp-main-style', FLARE_PRESS_URL . 'includes/dist/css/fp-main.css', [], FLARE_PRESS_VERSION);
}

/**
 * Delete attachment image from Cloudflare storage before WordPress' actual deletion takes place
 */
function fp_pre_delete_attachment(WP_Post|false|null $delete, WP_Post $post, bool $forceDelete): WP_Post|false|null
{
    return AttachmentController::handleDeleteAttachment($delete, $post, $forceDelete);
}