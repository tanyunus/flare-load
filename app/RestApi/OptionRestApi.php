<?php

defined('ABSPATH') || exit;

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

    public static function getVariantNames(WP_REST_Request $request): WP_REST_Response {
        $variantNames = OptionController::getVariantNames();

        if(empty($variantNames)) {
            return new WP_REST_Response([
                'error' => true,
                'message' => 'Error while getting variant names.',
            ], 500);
        }

        sort($variantNames);

        return new WP_REST_Response([
            'success' => true,
            'data' => $variantNames,
        ], 200);
    }

    public static function getAccountHash(WP_REST_Request $request): WP_REST_Response {
        $accountHash = OptionController::getAccountHash();

        if(empty($accountHash)) {
            return new WP_REST_Response([
                'error' => true,
                'message' => 'Error while getting account hash.',
            ], 500);
        }

        return new WP_REST_Response([

            'success' => true,
            'data' => $accountHash,
        ], 200);
    }
}