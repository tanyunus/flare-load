<?php

namespace FP\Controllers;

use Exception;

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
                add_filter('wp_generate_attachment_metadata', function ($metadata, $attachmentId, $context) use ($cfUploadResult, $publicVariantUrl) {
                    $cfVariants = FPCFImagesApi::getVariants();

                    $newMetadata = UpdateImage::updateAttachmentMeta(
                        $attachmentId,
                        $metadata,
                        $publicVariantUrl,
                        $cfUploadResult['result']['id'],
                        $cfVariants
                    );

                    return $newMetadata;
                }, 10, 3);
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        });
    }

    private function isAttachmentToBeUploadedToCf(): bool
    {
        return $_POST[FPConstants::UPLOAD_TO_CF_INDICATOR] ?? false;
    }
}