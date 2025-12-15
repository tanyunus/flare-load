<?php

namespace FP\Controllers;

use Exception;

class FPUploadModifier
{
    /**
     * @throws Exception
     */
    public static function updateAttachmentGuid(int $attachmentId, string $newGuid): void {
        global $wpdb;

        if(!$wpdb->update($wpdb->posts, ['guid' => $newGuid], ['ID' => $attachmentId])) {
            throw new Exception("Unable to update attachment guid");
        }
    }

    public static function updateAttachmentFileValue(int $attachmentId, string $newValue): void {
        if(!update_attached_file($attachmentId, $newValue)) {
            throw new Exception("Unable to update attachment file value");
        }
    }

    /**
     * @throws Exception
     */
    public static function updateAttachmentMeta(
        int $attachmentId,
        array $metaData,
        string $cfImageUrl,
        string $cfImageId,
        array $cfVariants
    ): array {
        $sizes = [];
        $mimeType = $metaData['sizes']['medium']['mime-type'] ?? '';
        $fileSize = $metaData['sizes']['medium']['file-size'] ?? ''; // TODO: Implement real size calculation of cdn file

        $metaData['file'] = $cfImageUrl;
        $metaData[FPConstants::UPLOADED_IMAGE_CF_ID_NAME] = $cfImageId;

        foreach ($cfVariants as $variant) {
            $variantUrl = FPCFImagesApi::getVariantUrl($variant['id'], $attachmentId);

            if(!$variantUrl) {
                continue;
            }

            $sizes['fp_cf' . $variant['id']] = [
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
}