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
            error_log($e->getMessage());
        }
    }

    public static function handleDeleteAttachment(WP_Post|false|null $delete, WP_Post $post): WP_Post|false|null
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

    private static function shouldUploadToCloudflare(): bool
    {
        return $_POST[Constants::UPLOAD_TO_CF_INDICATOR] ?? false;
    }

    /**
     * @throws Exception
     */
    private static function updateAttachmentMeta(int   $attachmentId, array $metaData, array $newMetaData): array
    {
        $metaData['file'] = $newMetaData['publicVariantUrl'];
        $metaData[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $newMetaData['cloudFlareId'];
        $metaData[Constants::UPLOADED_IMAGE_CF_FILE_NAME] = $newMetaData['fileName'];
        $metaData[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME] = $newMetaData['thumbnail'];
        $metaData['filesize'] = $newMetaData['fileSize'];
        $metaData['sizes'] = [];

        clean_attachment_cache($attachmentId);

        return $metaData;
    }

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
        $img->setAttribute('src', $cfUrl);
        $img->setAttribute('srcset', '');
        $img->setAttribute('width', '60');
        $img->setAttribute('height', '60');

        return $dom->saveHTML($img);
    }

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

    private static function updateSizes(array $sizeArray, string $imgUrl, int $attachmentId): array
    {
        if (isset($sizeArray['full'])) {
            $sizeArray['full']['url'] = $imgUrl;
        }

        $thumbnail = self::getCfThumbnail($attachmentId)['path'] ?? $imgUrl;
        $sizeArray['medium']['url'] = $thumbnail;

        return $sizeArray;
    }

    private static function shouldDeleteLocalFile(): bool
    {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);

        return empty($options[Constants::DASHBOARD_KEEP_AFTER_UPLOAD_FIELD_NAME]);
    }

    private static function shouldDeleteCloudflareFile(): bool
    {
        $options = get_option(Constants::DASHBOARD_UPLOAD_SETTINGS_NAME, []);

        return empty($options[Constants::DASHBOARD_KEEP_ON_CF_AFTER_DELETE_FIELD_NAME]);
    }

    private static function createThumbnailSizeOfImage($image): array|false
    {
        $editor = wp_get_image_editor($image);

        if (is_wp_error($editor)) {
            error_log('Thumbnail creation error: ' . $editor->get_error_message());

            return false;
        }

        $editor->resize(300, 300, true);

        if (is_wp_error($editor)) {
            error_log('Thumbnail resize error: ' . $editor->get_error_message());

            return false;
        }

        $saveResult = $editor->save($editor->generate_filename(Constants::UPLOADED_IMAGE_CF_THUMBNAIL_SUFFIX));

        if (is_wp_error($editor)) {
            error_log('Thumbnail save error: ' . $editor->get_error_message());

            return false;
        }

        return $saveResult;
    }

    private static function deleteCfThumbnail(int $attachmentId): void
    {
        $thumbnail = self::getCfThumbnail($attachmentId)['path'] ?? '';

        if (empty($thumbnail)) {
            return;
        }

        Utils::deleteFileFromDisk($thumbnail);
    }

    private static function getCfThumbnail(int $attachmentId): array
    {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        if (!$attachmentMeta) {
            return [];
        }

        return $attachmentMeta[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME] ?? [];
    }

    public static function getLargestPublicVariant(): string
    {
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

    public static function getDefaultVariantUrl($cloudflareImageId): string {
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