<?php

namespace FP\Controllers;

class FPUploadModifier
{
    public function __construct(int $attachmentId, $attachmentData)
    {
        $this->updateAttachmentGuid($attachmentId, $attachmentData);
        $this->updateAttachedFileValue($attachmentId, $attachmentData);
    }
    private function updateAttachmentGuid(int $attachmentId, string $newGuid): bool {
        global $wpdb;

        return boolval($wpdb->update($wpdb->posts, ['guid' => $newGuid], ['ID' => $attachmentId]));
    }

    private function updateAttachedFileValue(int $attachmentId, string $newValue): bool {
        return boolval(update_attached_file($attachmentId, $newValue));
    }
}