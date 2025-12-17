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

use FlarePress\Controllers\Dashboard;
use FlarePress\Controllers\QueryController;
use FlarePress\Controllers\UploadController;
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
    if (!is_admin()) {
        return;
    }

    add_filter('wp_prepare_attachment_for_js', 'fp_wp_prepare_attachment_for_js', 5, 3);
    add_filter('wp_get_attachment_image', 'fp_wp_get_attachment_image', 15, 5);
    add_filter('wp_get_attachment_image_src', 'fp_wp_get_attachment_image_src', 10, 4);
    add_filter('wp_get_attachment_url', 'fp_wp_get_attachment_url', 5, 2);
    add_filter('manage_media_columns', 'fp_manage_media_columns');
    add_filter('manage_media_custom_column', 'fp_manage_media_custom_column', 10, 2);
    add_action('add_attachment', 'fp_add_attachment', 1, 3);
    add_action('admin_menu', 'fp_admin_menu');
    add_action('admin_print_footer_scripts', 'fp_admin_print_footer_scripts');
    add_action('admin_enqueue_scripts', 'fp_admin_enqueue_scripts');
}

/**
 * Modifying ajax response right after upload and before sending it
 * */
function fp_wp_prepare_attachment_for_js(array $response, WP_Post $attachment): array
{
    if (Utils::getCloudflareIdOfAttachment($attachment->ID)) {
        $response = QueryController::updateAjaxQueryResponse($response, $attachment);
    }

    return $response;
}

/**
 * Modify HTML img element before rendering image
 */
function fp_wp_get_attachment_image(string $html, int $attachmentId): string
{
    if (Utils::getCloudflareIdOfAttachment($attachmentId)) {
        $html = QueryController::updateQueriedAttachmentUrl($attachmentId, $html);
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
function fp_wp_get_attachment_url(string $attachmentUrl, int $attachmentId): string {
    if(Utils::getCloudflareIdOfAttachment($attachmentId)) {
        $attachmentUrl = get_the_guid($attachmentId);
    }

    return $attachmentUrl;
}

/**
 * Control attachment upload process
 */
function fp_add_attachment(int $attachmentId): void {
    UploadController::handleAddAttachment($attachmentId);
}

/**
 * Add Location column to media library list view
 */
function fp_manage_media_columns(array $columns): array {
    $columns[Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID] = 'Location';

    return $columns;
}

/**
 * Add location info under Location column in media library list view
 */
function fp_manage_media_custom_column(string $columnName, int $attachmentId): void {
    if ($columnName === Constants::DASHBOARD_CF_LIST_VIEW_COLUMN_ID && Utils::getCloudflareIdOfAttachment($attachmentId)) {
        echo '<span 
                data-fp-file-name="' . Utils::getAttachmentFileName($attachmentId) . '"
                data-fp-url="'. get_the_guid($attachmentId)  .'"
                >
                <img title="Uploaded to Cloudflare" alt="Cloudflare logo" height="18" src="/wordpress/wp-content/plugins/flare-press/images/cf_logo.png"></span>';
    } else {
        echo 'This server';
    }
}

/**
 * Initialize dashboard
 */
function fp_admin_menu(): void {
    new Dashboard();
}

/**
 * Add scripts for dashboard
 */
function fp_admin_print_footer_scripts(): void {
    wp_enqueue_script('fp-main', FLARE_PRESS_PATH. 'includes/assets/scripts/fp-main.js');
}

/**
 * Add styles for dashboard
 */
function fp_admin_enqueue_scripts(): void {
    wp_enqueue_style('fp_main_style',FLARE_PRESS_PATH. 'includes/assets/styles/style.css');
}