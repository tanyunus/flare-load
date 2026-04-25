<?php

namespace FlarePress\Controllers;

defined('ABSPATH') || exit;

use DOMDocument;
use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Logger;
use FlarePress\Util\Utils;
use WP_Post;

class AttachmentController
{
    /**
     * Handles processes that will take place while inserting an attachment to db.
     *
     * Basically these are happening:
     *
     * 1 - Path, name and size of the uploaded image file are stored in variables.
     *
     * 2 - A thumbnail version of the uploaded image is generated from the disk version.
     *
     * 3 - Image is uploaded to Cloudflare Images.
     *
     * 4 - 'guid' and 'attached_file' values are replaced with Cloudflare Image ID in db.
     *
     * 5 - New and existing meta-data is created.
     *
     * 6 - Attachment meta-data is modified with new values before it's written on db via filtering: wp_generate_attachment_metadata.
     *
     * 7 - Local file of uploaded image deletion (is it's set to be deleted in options page).
     *
     * @param $attachmentId
     * @return void
     */
    public static function handleAddAttachment($attachmentId): void
    {
        if (!self::shouldUploadToCloudflare()) {
            return;
        }

        try {
            // 1. Store already uploaded file path, name and size
            $imageFile = get_attached_file($attachmentId);
            $imageFileName = basename($imageFile);
            $imageFileSize = wp_filesize($imageFile);

            // 2. Generate thumbnail from disk version of image
            $thumbnail = self::createThumbnailSizeOfImage($imageFile);

            // 3. Upload image to Cloudflare and get the result
            $cloudFlareUploadResult = CloudflareImagesApi::uploadImage($imageFile, $imageFileName);

            // 4. Update guid and attached file with Cloudflare ID
            self::updateAttachmentGuid($attachmentId, $cloudFlareUploadResult['result']['id']);
            self::updateAttachedFile($attachmentId, $cloudFlareUploadResult['result']['id']);

            // 5. Setup new metadata values
            $newMetaData = [
                'fileName' => $imageFileName,
                'fileSize' => $imageFileSize,
                'cloudFlareId' => $cloudFlareUploadResult['result']['id'],
                'thumbnail' => $thumbnail,
            ];

            // 6. Modify attachment meta before it's created
            add_filter('wp_generate_attachment_metadata', function ($metadata, $attachmentId, $context) use ($newMetaData) {
                return self::updateAttachmentMeta(
                    $attachmentId,
                    $metadata,
                    $newMetaData
                );
            }, 1, 3);

            // 7. Delete local file from disk if set so
            if (self::shouldDeleteLocalFile()) {
                Utils::deleteFileFromDisk($imageFile);
            }
        } catch (Exception $e) {
            Logger::log(0, $e->getMessage());
            update_post_meta($attachmentId, '_fp_upload_error', $e->getMessage());
            set_transient('fp_upload_error_' . get_current_user_id(), true, 120);
        }
    }

    /**
     * Handles the processes that will take place while deleting a Cloudflare Image from WordPress media library.
     *
     * @param WP_Post|false|null $delete
     * @param WP_Post $post
     * @return WP_Post|false|null
     */
    public static function handleDeleteAttachment(WP_Post|false|null $delete, WP_Post $post): WP_Post|false|null
    {
        $cfImageId = self::getCloudflareIdOfAttachment($post->ID);

        if ($cfImageId && self::shouldDeleteCloudflareFile()) {
            try {
                CloudflareImagesApi::deleteImage($cfImageId);
            } catch (Exception $e) {
                Logger::log(0, '[ATTACHMENT][DELETE_FROM_DISK] ' . $e->getMessage());
            }
        }

        self::deleteCfThumbnail($post->ID);

        return $delete;
    }


    /**
     * Check if image should be uploaded to Cloudflare.
     *
     * Checks 'fp_upload_to_cf' param from $_POST object to determine the result.
     * The param and it's value is added to request body in frontend
     * before media upload request sent; Intercepted by JS code.
     *
     * @return bool
     */
    private static function shouldUploadToCloudflare(): bool
    {
        return !empty(sanitize_key(wp_unslash($_POST[Constants::UPLOAD_TO_CF_INDICATOR] ?? '')));
    }

    /**
     * A wrapper for meta-data value replacements & additions.
     *
     * Params & Values:
     *
     * 'file' => ''
     *
     * 'fp_cf_image_id' => cloudflare ID of image
     *
     * 'fp_cf_file_name' => file name from $newMetaData
     *
     * 'fp_cf_thumbnail' => thumbnail data from $newMetaData
     *
     * 'filesize' => known file size from uploaded image
     *
     * 'sizes' => empty array because it has no use in this case
     *
     * Also cleans up attachment cache for immediate reflection on db.
     *
     * @param int $attachmentId
     * @param array $metaData
     * @param array $newMetaData
     *
     * @return array
     */
    private static function updateAttachmentMeta(int $attachmentId, array $metaData, array $newMetaData): array
    {
        $metaData['file'] = '';
        $metaData[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $newMetaData['cloudFlareId'];
        $metaData[Constants::UPLOADED_IMAGE_CF_FILE_NAME] = $newMetaData['fileName'];
        $metaData[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME] = $newMetaData['thumbnail'];
        $metaData['filesize'] = $newMetaData['fileSize'];
        $metaData['sizes'] = [];

        update_post_meta($attachmentId, Constants::UPLOADED_IMAGE_CF_ID_NAME, $newMetaData['cloudFlareId']);

        clean_attachment_cache($attachmentId);

        return $metaData;
    }


    /**
     * Update the queried attachment HTML snippet to replace attachment url
     * with corresponding Cloudflare Image URL generated using default variant.
     *
     * If it's admin page the query run, then the URL is set for thumbnail previews
     * in media library page located here: /wp-admin/upload.php
     *
     * 99% suited only for 'wp_get_attachment_image' hook.
     *
     * @param int $attachmentId
     * @param string $cfId
     * @param string $html
     * @return string
     */
    public static function updateQueriedAttachmentHtml(int $attachmentId, string $cfId, string $html): string
    {
        $cfUrl = self::getDefaultVariantUrl($cfId);

        if (Utils::isAdminPage('upload.php')) {
            $cfUrl = self::getCfThumbnail($attachmentId)['path'] ?? $cfUrl;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $img = $dom->getElementsByTagName('img')->item(0);

        if(!$img->setAttribute('src', $cfUrl)) {
            Logger::log(0, '[ATTACHMENT] Cannot update -src- attribute of queried attachment html.');

            return $html;
        }

        if(!$img->setAttribute('srcset', '')) {
            Logger::log(0, '[ATTACHMENT] Cannot update -srcset- attribute of queried attachment html.');

            return $html;
        }

        if(!$img->setAttribute('width', '60')) {
            Logger::log(0, '[ATTACHMENT] Cannot update -width- attribute of queried attachment html.');

            return $html;
        }

        if(!$img->setAttribute('height', '60')) {
            Logger::log(0, '[ATTACHMENT] Cannot update -height- attribute of queried attachment html.');

            return $html;
        }

        $savedHTML = $dom->saveHTML($img);

        if(!$savedHTML) {
            Logger::log(0, '[ATTACHMENT] Cannot save updated queried attachment html.');

            return $html;
        }

        return $dom->saveHTML($img);
    }

    /**
     * Updates query response (json structured data string) with new data
     * before it's being sent back to client.
     *
     * url, sizes and filename are replaced.
     *
     * New prop: 'fp_cf_image_id' added and value is set to Cloudflare image id.
     *
     * Works with 'wp_prepare_attachment_for_js' filter.
     *
     * @param array $response Response data from filtering wp_prepare_attachment_for_js
     * @param object $attachment Attachment itself from filtering wp_prepare_attachment_for_js
     *
     * @return array Modified dataset.
     */
    public static function updateAjaxQueryResponse(array $response, object $attachment): array
    {
        $cloudflareImageId = self::getCloudflareIdOfAttachment($attachment->ID);

        if ($cloudflareImageId) {
            $defaultVariantUrl = self::getDefaultVariantUrl($cloudflareImageId);

            $response['url'] = $defaultVariantUrl;
            $response['sizes'] = self::updateSizes($response['sizes'], $defaultVariantUrl, $attachment->ID);
            $response[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $cloudflareImageId;
            $response['filename'] = self::getAttachmentFileName($attachment->ID);
        }

        return $response;
    }


    /**
     * Updates size data.
     *
     * Replaces 'full' size url with given $imgUrl (which should be default variant of CF image)
     *
     * Replaces 'medium' size url with thumbnail url, the one generated before upload
     * and resides on disk and used for previews on dashboard.
     *
     * @param array $sizeArray Default size array from meta-data.
     * @param string $imgUrl The url which will be used for 'full' size.
     * @param int $attachmentId The id of the attachment in regard.
     * @return array The updated size array.
     */
    private static function updateSizes(array $sizeArray, string $imgUrl, int $attachmentId): array
    {
        if (isset($sizeArray['full'])) {
            $sizeArray['full']['url'] = $imgUrl;
        }

        $thumbnail = self::getCfThumbnail($attachmentId)['path'] ?? $imgUrl;
        $sizeArray['medium']['url'] = $thumbnail;

        return $sizeArray;
    }

    /**
     * Check whether the file uploaded to /uploads folder should be deleted
     * by the option in plugin settings page.
     *
     * @return bool
     */
    private static function shouldDeleteLocalFile(): bool
    {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);

        return empty($options[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME]);
    }

    /**
     * Check whether the file in Cloudflare Images should be deleted
     * by the option in plugin settings page.
     *
     * @return bool
     */
    private static function shouldDeleteCloudflareFile(): bool
    {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);

        return empty($options[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME]);
    }

    /**
     * Create thumbnail version of an image and save it to
     * the same directory it was uploaded, with the suffix '_fp_cf_thumbnail'.
     *
     * The idea of creating alternative thumbnail is to prevent consuming
     * view count from Cloudflare Images (CDN) and reduce load times
     * in dashboard while previewing images in thumbnails.
     *
     * @param string $image The full image path.
     *
     * @return array|false Array of the saved thumbnail image details or false on failure.
     */
    private static function createThumbnailSizeOfImage(string $image): array|false
    {
        $editor = wp_get_image_editor($image);

        if (is_wp_error($editor)) {
            Logger::log(0, '[ATTACHMENT] Thumbnail creation error: ' . $editor->get_error_message());

            return false;
        }

        $editor->resize(300, 300, true);

        if (is_wp_error($editor)) {
            Logger::log(0, '[ATTACHMENT] Thumbnail resize error: ' . $editor->get_error_message());

            return false;
        }

        $saveResult = $editor->save($editor->generate_filename(Constants::UPLOADED_IMAGE_CF_THUMBNAIL_SUFFIX));

        if (is_wp_error($editor)) {
            Logger::log(0, '[ATTACHMENT] Thumbnail save error: ' . $editor->get_error_message());

            return false;
        }

        return $saveResult;
    }

    /**
     * Delete the thumbnail image file that is created
     * for previewing Cloudflare images.
     *
     * @param int $attachmentId
     *
     * @return void
     */
    private static function deleteCfThumbnail(int $attachmentId): void
    {
        $thumbnail = self::getCfThumbnail($attachmentId)['path'] ?? '';

        if (empty($thumbnail)) {
            return;
        }

        Utils::deleteFileFromDisk($thumbnail);
    }

    /**
     * Get the thumbnail image of the image that is uploaded
     * to Cloudflare.
     *
     * @param int $attachmentId
     *
     * @return array
     */
    public static function getCfThumbnail(int $attachmentId): array
    {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        if (!$attachmentMeta) {
            return [];
        }

        return $attachmentMeta[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME] ?? [];
    }

    /**
     * Updates attachment's guid field in wp_posts table with given value.
     * This is where final URL of image is stored.
     *
     * @param int $attachmentId
     * @param string $newGuid
     * @return void
     * @throws Exception
     */
    public static function updateAttachmentGuid(int $attachmentId, string $newGuid): void
    {
        global $wpdb;

        if (!$wpdb->update($wpdb->posts, ['guid' => $newGuid], ['ID' => $attachmentId])) {
            throw new Exception("[ATTACHMENT] Unable to update attachment guid");
        }
    }

    /**
     * Returns the file name of an image that is uploaded to Cloudflare from attachment meta
     *
     * @param int $attachmentId
     * @return string|false Image's file name or false if not found
     */
    public static function getAttachmentFileName(int $attachmentId): string|false
    {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return $attachmentMeta[Constants::UPLOADED_IMAGE_CF_FILE_NAME] ?? false;
    }

    /**
     * Returns the ID of and image that is uploaded to Cloudflare from attachment meta
     *
     * @param int $attachmentId
     * @return string|false Image's Cloudflare ID or false if not found
     */
    public static function getCloudflareIdOfAttachment(int $attachmentId): string|false
    {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return $attachmentMeta[Constants::UPLOADED_IMAGE_CF_ID_NAME] ?? false;
    }

    /**
     * Updates attachment's _wp_attached_file field in wp_postmeta table with given value.
     * This is the place relative file path stored.
     *
     * @param int $attachmentId
     * @param string $newValue
     * @return void
     * @throws Exception
     */
    public static function updateAttachedFile(int $attachmentId, string $newValue): void
    {
        if (!update_attached_file($attachmentId, $newValue)) {
            throw new Exception("[ATTACHMENT] Unable to update attachment file value");
        }
    }

    /**
     * Retrieve default variant url constructed using default variant
     * set in plugin settings page.
     *
     * @param string $cloudflareImageId
     *
     * @return string
     */
    public static function getDefaultVariantUrl(string $cloudflareImageId): string {
        $defaultVariant = get_option(Constants::DASHBOARD_DEFAULT_VARIANT_FIELD_NAME);

        return self::getVariantUrl($defaultVariant, $cloudflareImageId);
    }

    /**
     * Constructs variant url by given variant name in format:
     *   https://imagedelivery.net/<account-hash>/<image-id>/<variant>
     *
     * @param string $variant Variant slug/id as string
     * @param string $imageId Image id of the image uploaded to Cloudflare
     *
     * @return string|false URL constructed to serve desired variant or false.
     */
    public static function getVariantUrl(string $variant, string $imageId): string|false {
        $accountHash = get_option(Constants::DASHBOARD_CF_ACCOUNT_HASH_FIELD_NAME);

        if(!$accountHash){
            return false;
        }

        return Constants::CF_CDN_URL . $accountHash . '/' . $imageId . '/' . $variant;
    }
}