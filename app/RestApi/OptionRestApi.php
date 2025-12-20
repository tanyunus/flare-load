<?php

namespace FlarePress\RestApi;

use FlarePress\Controllers\OptionController;
use WP_REST_Request;
use WP_REST_Response;

class OptionRestApi
{
    public static function syncVariants(WP_REST_Request $request): WP_REST_Response {
        $syncResult = OptionController::syncVariants();

        if(empty($syncResult)) {
            return new WP_REST_Response([
                'error' => true,
                'message' => 'Variants not synced. Please check logs.',
            ], 500);
        }

        $syncResult = array_keys($syncResult);
        sort($syncResult);
        $arrKeys = stripslashes(wp_json_encode($syncResult));

        return new WP_REST_Response([
            'success' => true,
            'data' => $arrKeys,
        ], 200);
    }
}