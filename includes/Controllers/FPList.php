<?php

namespace FP\Controllers;

class FPList
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
    }

    private function isCfImage(int $attachmentId): bool {
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return isset($attachmentMeta['fp_cf_image_id']);
    }
}