<?php

namespace FP\Controllers;

use Exception;
use FP\Utils\UpdateImage;

class FPUploadController
{
    public function __construct()
    {
        // Actions to be taken right after attachment added
        add_action('add_attachment', function ($attachmentId) {
            if (!$this->isAttachmentToBeUploadedToCf()) {
                return;
            }

            try {
                $imageFile = get_attached_file($attachmentId);
                $fileName = basename($imageFile);
                $cfUploadResult = FPCFImagesApi::uploadSingleImage($imageFile, $fileName);
                $publicVariantUrl = FPCFImagesApi::getVariantUrl('public', $cfUploadResult['result']['id']);

                UpdateImage::updateAttachmentGuid($attachmentId, $publicVariantUrl);
                UpdateImage::updateAttachmentFileValue($attachmentId, $publicVariantUrl);

                // Actions to be taken right after attachment meta added
                add_filter('wp_generate_attachment_metadata', function ($metadata, $attachmentId, $context) use ($cfUploadResult, $publicVariantUrl, $fileName) {
                    $cfVariants = FPCFImagesApi::getVariants();
                    $updatedMetadata = UpdateImage::updateAttachmentMeta(
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

                UpdateImage::deleteImageFromDisk($imageFile);
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }, 1, 3);
    }

    private function isAttachmentToBeUploadedToCf(): bool
    {
        return $_POST[FPConstants::UPLOAD_TO_CF_INDICATOR] ?? false;
    }
}