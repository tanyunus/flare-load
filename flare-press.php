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

use FlarePress\Controllers\Attachment;
use FlarePress\Controllers\Dashboard;
use FlarePress\Data\Constants;
use FlarePress\Util\Utils;

if (!defined('WPINC')) {
    die;
}

define('FLARE_PRESS_VERSION', '0.1.0');
define('FLARE_PRESS_PATH', plugin_dir_path(__FILE__));
define('FLARE_PRESS_URL', plugin_dir_url(__FILE__));

require_once FLARE_PRESS_PATH . 'autoload.php';

add_action('plugins_loaded', 'flarePressInit');

function flarePressInit(): void
{
    if(is_user_logged_in()) {
        add_filter('wp_prepare_attachment_for_js', 'fp_wp_prepare_attachment_for_js', 5, 3);
        add_filter('wp_get_attachment_image', 'fp_wp_get_attachment_image', 15, 5);
        add_filter('wp_get_attachment_image_src', 'fp_wp_get_attachment_image_src', 10, 4);
        add_filter('wp_get_attachment_url', 'fp_wp_get_attachment_url', 5, 2);
    }

    if (is_admin()) {
        add_filter('manage_media_columns', 'fp_manage_media_columns');
        add_filter('manage_media_custom_column', 'fp_manage_media_custom_column', 10, 2);
        add_filter('pre_delete_attachment', 'fp_pre_delete_attachment', 10, 3);
        add_action('add_attachment', 'fp_add_attachment', 1, 3);
        add_action('admin_menu', 'fp_admin_menu');
        add_action('admin_print_footer_scripts', 'fp_admin_print_footer_scripts');
        add_action('admin_enqueue_scripts', 'fp_admin_enqueue_scripts');
    }
}

/**
 * Modifying ajax response right after upload and before sending it
 * */
function fp_wp_prepare_attachment_for_js(array $response, WP_Post $attachment): array
{
    if (Utils::getCloudflareIdOfAttachment($attachment->ID)) {
        $response = Attachment::updateAjaxQueryResponse($response, $attachment);
    }

    return $response;
}

/**
 * Modify HTML img element before rendering image
 */
function fp_wp_get_attachment_image(string $html, int $attachmentId): string
{
    if (Utils::getCloudflareIdOfAttachment($attachmentId)) {
        $html = Attachment::updateQueriedAttachmentUrl($attachmentId, $html);
    }

    return $html;
}

/**
 * Modify attachment source before rendering image
 */
function fp_wp_get_attachment_image_src(array|false $image, int $attachmentId): array|false
{
    if (Utils::getCloudflareIdOfAttachment($attachmentId)) {
        $image[0] = get_the_guid($attachmentId);
    }

    return $image;
}

/**
 * Modify attachment url
 */
function fp_wp_get_attachment_url(string $attachmentUrl, int $attachmentId): string
{
    if (Utils::getCloudflareIdOfAttachment($attachmentId)) {
        $attachmentUrl = get_the_guid($attachmentId);
    }

    return $attachmentUrl;
}

/**
 * Control attachment upload process
 */
function fp_add_attachment(int $attachmentId): void
{
    Attachment::handleAddAttachment($attachmentId);
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
    Dashboard::addLocationInfoToListViewRow($columnName, $attachmentId);
}

/**
 * Initialize dashboard
 */
function fp_admin_menu(): void
{
    new Dashboard();
}

/**
 * Add scripts for dashboard
 */
function fp_admin_print_footer_scripts(): void
{
    wp_enqueue_script('fp-main-script', FLARE_PRESS_PATH . 'includes/assets/scripts/fp-main.js');
}

/**
 * Add styles for dashboard
 */
function fp_admin_enqueue_scripts(): void
{
    wp_enqueue_style('fp-main-style', FLARE_PRESS_PATH . 'includes/assets/styles/fp-main.css');
}

/**
 * Delete attachment image from Cloudflare storage before WordPress' actual deletion takes place
 */
function fp_pre_delete_attachment(WP_Post|false|null $delete, WP_Post $post, bool $forceDelete): WP_Post|false|null
{
    return Attachment::handleDeleteAttachment($delete, $post, $forceDelete);
}