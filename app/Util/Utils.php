<?php

namespace FlarePress\Util;

use Exception;
use FlarePress\Data\Constants;

class Utils
{
    /**
     * Translates given string in this plugin's own translation domain
     *
     * @param string $string
     * @return string
     */
    public static function localize(string $string): string
    {
        return __($string, Constants::FP_TRANSLATION_DOMAIN);
    }

    /**
     * Checks current admin page
     *
     * @param string $pageSlug Page slug with extension of the page
     * @return bool
     */
    public static function isAdminPage(string $pageSlug): bool {
        global $pagenow;

        $currentAdminPage = basename(admin_url($pagenow));

        return $currentAdminPage === $pageSlug;
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
     * Returns all images uploaded to Cloudflare as array
     *
     * @return array Images uploaded to Cloudflare
     */
    public static function getCloudflareImages(): array
    {
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_status' => 'inherit',
            'meta_query' => array(
                array(
                    'key' => '_wp_attachment_metadata',
                    'value' => Constants::UPLOADED_IMAGE_CF_ID_NAME,
                    'compare' => 'LIKE'
                )
            )
        ));

        $filteredAttachments = [];

        foreach ($attachments as $attachment) {
            $metaData = wp_get_attachment_metadata($attachment->ID);

            if (isset($metaData[Constants::UPLOADED_IMAGE_CF_ID_NAME])) {
                $filteredAttachments[] = $attachment;
            }
        }

        return $filteredAttachments;
    }

    /**
     * Updates attachment's guid field in wp_posts table with given value.
     * This is where final URL of image is stored.
     * @param int $attachmentId
     * @param string $newGuid
     * @return void
     * @throws Exception
     */
    public static function updateAttachmentGuid(int $attachmentId, string $newGuid): void {
        global $wpdb;

        if(!$wpdb->update($wpdb->posts, ['guid' => $newGuid], ['ID' => $attachmentId])) {
            throw new Exception("Unable to update attachment guid");
        }
    }

    /**
     * Updates attachment's _wp_attached_file field in wp_postmeta table with given value.
     * This is the place relative file path stored.
     * @param int $attachmentId
     * @param string $newValue
     * @return void
     * @throws Exception
     */
    public static function updateAttachedFile(int $attachmentId, string $newValue): void {
        if(!update_attached_file($attachmentId, $newValue)) {
            throw new Exception("Unable to update attachment file value");
        }
    }

    /**
     * Deletes file from disk.
     *
     * @param string $filePath
     * @return bool
     */
    public static function deleteFileFromDisk(string $filePath): bool {
        $fileRealPath = realpath($filePath);

        if(!is_writable($fileRealPath)) {
            return false;
        }

        return unlink($fileRealPath);
    }

    /**
     * Renders given HTML template file with provided data.
     *
     * @param string $template HTML template file name under views folder.
     * @param array $data
     * @return void
     */
    public static function renderTemplate(string $template, array $data = []): void {
        extract( $data );
        $file = FLARE_PRESS_PATH . 'app/Views/' . $template . '.php';

        if ( file_exists( $file ) ) {
            include $file;
        }
    }
}