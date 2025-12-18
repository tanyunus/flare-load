<?php

namespace FlarePress\Controllers;

use DOMDocument;
use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Utils;
use ParagonIE\Sodium\Core\Util;
use WP_Error;
use WP_Post;

class Attachment
{
    public static function handleAddAttachment($attachmentId): void
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

            Utils::updateAttachmentGuid($attachmentId, $publicVariantUrl);
            Utils::updateAttachedFile($attachmentId, $publicVariantUrl);


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

    public static function handleDeleteAttachment(WP_Post|false|null $delete, WP_Post $post, bool $forceDelete): WP_Post|false|null
    {
        $cfImageId = Utils::getCloudflareIdOfAttachment($post->ID);

        if ($cfImageId && self::shouldDeleteCloudflareFile()) {
            try {
                CloudflareImagesApi::deleteImage($cfImageId);
            } catch (Exception $e) {
                error_log('[FlarePress] Attachment deletion error: ' . $e->getMessage());
            }
        }

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
        $cfImageId = Utils::getCloudflareIdOfAttachment($attachment->ID);

        if ($cfImageId) {
            $imgUrl = $attachment->guid;

            $response['url'] = $imgUrl;
            $response['sizes'] = self::updateSizes($response['sizes'], $imgUrl, $attachment->ID);
            $response[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $cfImageId;
            $response['filename'] = Utils::getAttachmentFileName($attachment->ID);
        }

        return $response;
    }

    private static function updateSizes(array $sizeArray, string $imgUrl, int $attachmentId): array
    {
        if (isset($sizeArray['full'])) {
            $sizeArray['full']['url'] = $imgUrl;
        }

        $thumbnail = wp_get_attachment_metadata($attachmentId)[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME]['path'] ?? $imgUrl;

        error_log(print_r(wp_get_attachment_metadata($attachmentId)[Constants::UPLOADED_IMAGE_CF_THUMBNAIL_NAME], true));

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
}