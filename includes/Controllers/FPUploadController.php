<?php

namespace FP\Controllers;

use Exception;

class FPUploadController
{
    public static function handleAddAttachment($attachmentId): void {
        if (!self::isAttachmentToBeUploadedToCf()) {
            return;
        }

        try {
            $imageFile = get_attached_file($attachmentId);
            $fileName = basename($imageFile);
            $cfUploadResult = FPCFImagesApi::uploadSingleImage($imageFile, $fileName);
            $publicVariantUrl = FPCFImagesApi::getVariantUrl('public', $cfUploadResult['result']['id']);

            Utils::updateAttachmentGuid($attachmentId, $publicVariantUrl);
            Utils::updateAttachedFile($attachmentId, $publicVariantUrl);

            // Actions to be taken right after attachment meta added
            add_filter('wp_generate_attachment_metadata', function ($metadata, $attachmentId, $context) use ($cfUploadResult, $publicVariantUrl, $fileName) {
                $cfVariants = FPCFImagesApi::getVariants();

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

    private static function isAttachmentToBeUploadedToCf(): bool
    {
        return $_POST[FPConstants::UPLOAD_TO_CF_INDICATOR] ?? false;
    }

    private static function updateAttachmentMeta(
        int $attachmentId,
        array $metaData,
        string $fileName,
        string $cfImageUrl,
        string $cfImageId,
        array $cfVariants
    ): array {
        $sizes = [];
        $mimeType = $metaData['sizes']['medium']['mime-type'] ?? '';
        $fileSize = $metaData['sizes']['medium']['file-size'] ?? ''; // TODO: Implement real size calculation of cdn file

        $metaData['file'] = $cfImageUrl;
        $metaData[FPConstants::UPLOADED_IMAGE_CF_ID_NAME] = $cfImageId;
        $metaData[FPConstants::UPLOADED_IMAGE_CF_FILE_NAME] = $fileName;

        foreach ($cfVariants as $variant) {
            $variantUrl = FPCFImagesApi::getVariantUrl($variant['id'], $attachmentId);

            if(!$variantUrl) {
                continue;
            }

            $sizes['fp_cf_' . $variant['id']] = [
                'file' => FPCFImagesApi::getVariantUrl($variant['id'], $attachmentId),
                'width' => $variant['options']['width'],
                'height' => $variant['options']['height'],
                'mime-type' => $mimeType,
                'file-size' => $fileSize,
            ];
        }

        if(empty($sizes)) {
            throw new Exception("Attachment size update error: No size data found.");
        }

        $metaData['sizes'] = $sizes;

        return $metaData;
    }

    public static function deleteImageFromDisk(string $imagePath): bool {
        $fileRealPath = realpath($imagePath);

        if(!is_writable($fileRealPath)) {
            return false;
        }

        return unlink($fileRealPath);
    }
}