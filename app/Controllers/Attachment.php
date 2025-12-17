<?php

namespace FlarePress\Controllers;

use DOMDocument;
use Exception;
use FlarePress\Api\CloudflareImagesApi;
use FlarePress\Data\Constants;
use FlarePress\Util\Utils;
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
            $fileName = basename($imageFile);
            $cfUploadResult = CloudflareImagesApi::uploadImage($imageFile, $fileName);
            $publicVariantUrl = CloudflareImagesApi::getVariantUrl('public', $cfUploadResult['result']['id']);

            Utils::updateAttachmentGuid($attachmentId, $publicVariantUrl);
            Utils::updateAttachedFile($attachmentId, $publicVariantUrl);

            // Actions to be taken right after attachment meta added
            add_filter('wp_generate_attachment_metadata', function ($metadata, $attachmentId, $context) use ($cfUploadResult, $publicVariantUrl, $fileName) {
                $cfVariants = CloudflareImagesApi::getVariants();

                $updatedMetadata = self::updateAttachmentMeta(
                    $attachmentId,
                    $metadata,
                    $fileName,
                    $publicVariantUrl,
                    $cfUploadResult['result']['id'],
                    $cfVariants
                );

                clean_attachment_cache($attachmentId);

                return $updatedMetadata;
            }, 1, 3);

            Utils::deleteFileFromDisk($imageFile);
        } catch (Exception $e) {
            error_log($e->getMessage());
        }
    }

    public static function handleDeleteAttachment(WP_Post|false|null $delete, WP_Post $post, bool $forceDelete): WP_Post|false|null
    {
        $cfImageId = Utils::getCloudflareIdOfAttachment($post->ID);

        if ($cfImageId) {
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
        string $fileName,
        string $cfImageUrl,
        string $cfImageId,
        array  $cfVariants
    ): array
    {
        $sizes = [];
        $mimeType = $metaData['sizes']['medium']['mime-type'] ?? '';
        $fileSize = $metaData['sizes']['medium']['file-size'] ?? ''; // TODO: Implement real size calculation of cdn file

        $metaData['file'] = $cfImageUrl;
        $metaData[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $cfImageId;
        $metaData[Constants::UPLOADED_IMAGE_CF_FILE_NAME] = $fileName;

        foreach ($cfVariants as $variant) {
            $variantUrl = CloudflareImagesApi::getVariantUrl($variant['id'], $attachmentId);

            if (!$variantUrl) {
                continue;
            }

            $sizes['fp_cf_' . $variant['id']] = [
                'file' => CloudflareImagesApi::getVariantUrl($variant['id'], $attachmentId),
                'width' => $variant['options']['width'],
                'height' => $variant['options']['height'],
                'mime-type' => $mimeType,
                'file-size' => $fileSize,
            ];
        }

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
            $response['sizes'] = self::updateSizes($response['sizes'], $imgUrl);
            $response[Constants::UPLOADED_IMAGE_CF_ID_NAME] = $cfImageId;
            $response['filename'] = Utils::getAttachmentFileName($attachment->ID);
        }

        return $response;
    }

    private static function updateSizes(array $sizeArray, string $imgUrl): array
    {
        if (isset($sizeArray['full'])) {
            $sizeArray['full']['url'] = $imgUrl;
        }

        if (isset($sizeArray['medium'])) {
            $sizeArray['medium']['url'] = $imgUrl;
        }

        if (isset($sizeArray['thumbnail'])) {
            $sizeArray['thumbnail']['url'] = $imgUrl;
        }

        return $sizeArray;
    }
}