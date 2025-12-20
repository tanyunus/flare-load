<?php

namespace FlarePress\Controllers;

use DOMDocument;
use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Utils;
use WP_Post;

class AttachmentController
{
    public static function handleAddAttachment2($attachmentId): void
    {
        if (!self::isAttachmentToBeUploadedToCf()) {
            return;
        }

        try {
            $imageFile = get_attached_file($attachmentId);
            $thumbnail = self::createThumbnailSizeOfImage($imageFile);
            $fileName = basename($imageFile);
            $cfUploadResult = CloudflareImagesApi::uploadImage($imageFile, $fileName);

            $fileSize = wp_filesize($imageFile);
            $publicVariantUrl = CloudflareImagesApi::getVariantUrl('public', $cfUploadResult['result']['id']);

            $newMetaData = [
                'fileSize' => $fileSize,
                'fileName' => $fileName,
                'cloudFlareId' => $cfUploadResult['result']['id'],
                'publicVariantUrl' => CloudflareImagesApi::getVariantUrl('public', $cfUploadResult['result']['id']),
                'cfThumbnail' => $thumbnail,
            ];

            self::updateAttachmentGuid($attachmentId, $publicVariantUrl);
            self::updateAttachedFile($attachmentId, $publicVariantUrl);


            // Actions to be taken right after attachment meta added
            add_filter('wp_generate_attachment_metadata', function ($metadata, $attachmentId, $context) use ($newMetaData) {
                $cfVariants = CloudflareImagesApi::getVariants();

                $newMetaData['cfVariants'] = $cfVariants;

                $updatedMetadata = self::updateAttachmentMeta(
                    $attachmentId,
                    $metadata,
                    $newMetaData
                );

                clean_attachment_cache($attachmentId);
                return $updatedMetadata;
            }, 1, 3);

            if(self::shouldDeleteLocalFile()) {
                Utils::deleteFileFromDisk($imageFile);
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
        }
    }

    public static function handleAddAttachment($attachmentId): void {
        try {
            // 1. Store already uploaded file path, name and size
            $imageFile = get_attached_file($attachmentId);
            $imageFileName = basename($imageFile);
            $imageFileSize = wp_filesize($imageFile);

            // 2. Generate thumbnail from disk version of image for preview purposes
            $thumbnail = self::createThumbnailSizeOfImage($imageFile);

            // 3. Upload image to Cloudflare and get the result
            $cloudFlareUploadResult = CloudflareImagesApi::uploadImage($imageFile, $imageFileName);
        } catch (Exception $e) {
            error_log($e->getMessage());
        }



        self::updateAttachmentGuid($attachmentId, $publicVariantUrl);
    }

    public static function handleDeleteAttachment(WP_Post|false|null $delete, WP_Post $post, bool $forceDelete): WP_Post|false|null
    {
        $cfImageId = self::getCloudflareIdOfAttachment($post->ID);

        if ($cfImageId && self::shouldDeleteCloudflareFile()) {
            try {
                CloudflareImagesApi::deleteImage($cfImageId);
            } catch (Exception $e) {
                error_log('[FlarePress] Attachment deletion error: ' . $e->getMessage());
            }
        }

        self::deleteCfThumbnail($post->ID);

        return $delete;
    }

    private static function isAttachmentToBeUploadedToCf(): bool
    {
        return $_POST[Constants::UPLOAD_TO_CF_INDICATOR] ?? false;
    }

    /**
     * @throws Exception
     */
    private static function updateAttachmentMeta(
        int    $attachmentId,
        array  $metaData,
        array  $newMetaData,
    ): array
    {
        $sizes = [];
        $mimeType = $metaData['sizes']['medium']['mime-type'] ?? '';
        $metaData['file'] = $newMetaData['publicVariantUrl'];
        $metaData[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $newMetaData['cloudFlareId'];
        $metaData[Constants::UPLOADED_IMAGE_CF_FILE_NAME] = $newMetaData['fileName'];
        $metaData[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME] = $newMetaData['cfThumbnail'];

        foreach ($newMetaData['cfVariants'] as $variant) {
            $variantUrl = CloudflareImagesApi::getVariantUrl($variant['id'], $attachmentId);

            if (!$variantUrl) {
                continue;
            }

            $sizes['fp_cf_' . $variant['id']] = [
                'file' => CloudflareImagesApi::getVariantUrl($variant['id'], $attachmentId),
                'width' => $variant['options']['width'],
                'height' => $variant['options']['height'],
                'mime-type' => $mimeType,
                'filesize' => $newMetaData['fileSize'],
            ];
        }

        $metaData['filesize'] = $newMetaData['fileSize'];
        $metaData['width'] = $newMetaData['cfVariants']['public']['options']['width'];
        $metaData['height'] = $newMetaData['cfVariants']['public']['options']['height'];

        if (empty($sizes)) {
            throw new Exception("Attachment size update error: No size data found.");
        }

        $metaData['sizes'] = $sizes;

        return $metaData;
    }

    public static function updateQueriedAttachmentUrl(int $attachmentId, string $html): string
    {
        $cfUrl = get_the_guid($attachmentId);

        if(Utils::isAdminPage('upload.php')) {
            $cfUrl = self::getCfThumbnail($attachmentId)['path'] ?? $cfUrl;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $img = $dom->getElementsByTagName('img')->item(0);
        $img->setAttribute('src', $cfUrl);
        $img->setAttribute('srcset', '');

        return $dom->saveHTML($img);
    }

    public static function updateAjaxQueryResponse(array $response, object $attachment): array
    {
        $cfImageId = self::getCloudflareIdOfAttachment($attachment->ID);

        if ($cfImageId) {
            $imgUrl = $attachment->guid;

            $response['url'] = $imgUrl;
            $response['sizes'] = self::updateSizes($response['sizes'], $imgUrl, $attachment->ID);
            $response[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $cfImageId;
            $response['filename'] = self::getAttachmentFileName($attachment->ID);
        }

        return $response;
    }

    private static function updateSizes(array $sizeArray, string $imgUrl, int $attachmentId): array
    {
        if (isset($sizeArray['full'])) {
            $sizeArray['full']['url'] = $imgUrl;
        }

        $thumbnail = self::getCfThumbnail($attachmentId)['path'] ?? $imgUrl;
        $sizeArray['medium']['url'] = $thumbnail;

        return $sizeArray;
    }

    private static function shouldDeleteLocalFile(): bool {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);

        return empty($options[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME]);
    }

    private static function shouldDeleteCloudflareFile(): bool {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);

        return empty($options[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME]);
    }

    private static function createThumbnailSizeOfImage($image): array|false
    {
        $editor = wp_get_image_editor($image);

        if(is_wp_error($editor)) {
            error_log('Thumbnail creation error: ' . $editor->get_error_message());

            return false;
        }

        $editor->resize(300, 300, true);

        if(is_wp_error($editor)) {
            error_log('Thumbnail resize error: ' . $editor->get_error_message());

            return false;
        }

        $saveResult = $editor->save($editor->generate_filename(Constants::UPLOADED_IMAGE_CF_THUMBNAIL_SUFFIX));

        if(is_wp_error($editor)) {
            error_log('Thumbnail save error: ' . $editor->get_error_message());

            return false;
        }

        return $saveResult;
    }

    private static function deleteCfThumbnail(int $attachmentId): void
    {
        $thumbnail = self::getCfThumbnail($attachmentId)['path'] ?? '';

        if(empty($thumbnail)) {
            return;
        }

        Utils::deleteFileFromDisk($thumbnail);
    }

    private static function getCfThumbnail(int $attachmentId): array {
        $attachmentMeta =  wp_get_attachment_metadata($attachmentId);

        if(!$attachmentMeta) {
            return [];
        }

        return $attachmentMeta[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME] ?? [];
    }

    public static function getLargestPublicVariant(): string {
        $variants = OptionController::getVariantsAsArray();

        error_log(print_r($variants, true));

        return '';
    }

    public static function getAttachmentFileSizeHumanReadableFormat(int $attachmentId): string
    {
        $fileSize = wp_get_attachment_metadata($attachmentId)['filesize'] ?? '';

        return size_format($fileSize) ?? '';
    }

    /**
     * Updates attachment's guid field in wp_posts table with given value.
     * This is where final URL of image is stored.
     * @param int $attachmentId
     * @param string $newGuid
     * @return void
     * @throws Exception
     */
    public static function updateAttachmentGuid(int $attachmentId, string $newGuid): void
    {
        global $wpdb;

        if (!$wpdb->update($wpdb->posts, ['guid' => $newGuid], ['ID' => $attachmentId])) {
            throw new Exception("Unable to update attachment guid");
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
     * @param int $attachmentId
     * @param string $newValue
     * @return void
     * @throws Exception
     */
    public static function updateAttachedFile(int $attachmentId, string $newValue): void
    {
        if (!update_attached_file($attachmentId, $newValue)) {
            throw new Exception("Unable to update attachment file value");
        }
    }
}