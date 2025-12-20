<?php

namespace FlarePress\Util;

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

        if(empty($currentAdminPage)) {
            $currentAdminPage = basename($_SERVER['REQUEST_URI']);
        }

        return $currentAdminPage === $pageSlug;
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