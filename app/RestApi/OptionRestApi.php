<?php

namespace FlarePress\RestApi;

defined('ABSPATH') || exit;

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

        return new WP_REST_Response([
            'success' => true,
            'data' => OptionController::getVariantOptions(),
        ], 200);
    }

    public static function getVariantNames(WP_REST_Request $request): WP_REST_Response {
        $variantOptions = OptionController::getVariantOptions();

        if(empty($variantOptions)) {
            return new WP_REST_Response([
                'error' => true,
                'message' => 'Error while getting variant names.',
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $variantOptions,
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