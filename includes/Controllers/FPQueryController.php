<?php

namespace FP\Controllers;

use FP\Utils\AdminPage;

class FPQueryController
{
    public function __construct() {
        add_action('wp_ajax_query-attachments', function() {
            add_action('wp_prepare_attachment_for_js', function($response, $attachment, $meta) {
                if($this->isCfImage($attachment->ID)) {
                    $imgUrl = $attachment->guid;

                    $response['url'] = $imgUrl;

                    if(isset($response['sizes']['full'])) {
                        $response['sizes']['full']['url'] = $imgUrl;
                    }

                    if(isset($response['sizes']['medium'])) {
                        $response['sizes']['medium']['url'] = $imgUrl;
                    }

                    if(isset($response['sizes']['thumbnail'])) {
                        $response['sizes']['thumbnail']['url'] = $imgUrl;
                    }
                }

                return $response;
            }, 10, 3);
        }, 1);

        add_action('wp_print_scripts', function() {
            if(AdminPage::is('upload.php')) {
                $this->addCfImageIdsToWindow();
            }
        });
    }

    private function isCfImage(int $attachmentId): bool {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return isset($attachmentMeta[FPConstants::UPLOADED_IMAGE_CF_ID_NAME]);
    }

    private function getCfImages(): array {
        $attachments = get_posts(array(
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
            'meta_query'     => array(
                array(
                    'key'     => '_wp_attachment_metadata',
                    'value'   => FPConstants::UPLOADED_IMAGE_CF_ID_NAME,
                    'compare' => 'LIKE'
                )
            )
        ));

        $filteredAttachments = [];

        foreach($attachments as $attachment) {
            $metaData = wp_get_attachment_metadata($attachment->ID);

            if(isset($metaData[FPConstants::UPLOADED_IMAGE_CF_ID_NAME])) {
                $filteredAttachments[] = $attachment;
            }
        }

        return $filteredAttachments;
    }

    private function getCfImageIds(): array {
        $imageIds = [];

        foreach ($this->getCfImages() as $image) {
            $imageIds[] = $image->ID;
        }

        return $imageIds;
    }

    private function addCfImageIdsToWindow(): void {
        $jsonEncodedIds = implode(",", $this->getCfImageIds());

        ?>
            <script>
                window.fp = window.fp || {};
                window.fp.cfImageIds = [<?php echo $jsonEncodedIds; ?>];
            </script>
        <?php
    }
}