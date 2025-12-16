<?php

namespace FP\Controllers;

use DOMDocument;
use FP\Utils\AdminPage;

class FPQueryController
{
    public function __construct() {
        // Modifying ajax response of attachment query
        add_action('wp_ajax_query-attachments', function() {
            add_action('wp_prepare_attachment_for_js', function($response, $attachment, $meta) {
                if($this->isCfImage($attachment->ID)) {
                    $response = $this->updateAjaxQueryResponse($response, $attachment);
                }

                return $response;
            }, 10, 3);
        }, 1);

        // Modifying ajax response right after upload
        add_filter('wp_prepare_attachment_for_js', function ($response, $attachment, $meta) {
            if($this->isCfImage($attachment->ID)) {
                $response = $this->updateAjaxQueryResponse($response, $attachment);
            }

            return $response;
        }, 10, 3);

        // Adding CF image ids to window objecy via custom script
        add_action('wp_print_scripts', function() {
            if(AdminPage::is('upload.php')) {
                $this->addCfImageIdsToWindow();
            }
        });

        // Modifying attachment query
        add_action('wp_get_attachment_image', function ($html, $attachment_id, $size, $icon, $attr) {
            if($this->isCfImage($attachment_id)) {
                $html = $this->updateQueriedAttachmentUrl($attachment_id, $html);
            }

            return $html;
        }, 10, 5);
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

    private function updateQueriedAttachmentUrl(int $attachmentId, string $html): string {
        $cfUrl = get_the_guid($attachmentId);

        $dom  = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $img = $dom->getElementsByTagName('img')->item(0);
        $img->setAttribute('src', $cfUrl);

        return $dom->saveHTML($img);
    }

    private function updateAjaxQueryResponse(array $response, object $attachment): array {
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
    }
}