<?php

namespace FlareLoad\RestApi;

defined('ABSPATH') || exit;

use FlareLoad\Controllers\OptionController;
use WP_REST_Request;
use WP_REST_Response;

class OptionRestApi
{
    public static function syncVariants(WP_REST_Request $request): WP_REST_Response {
        $syncResult = OptionController::syncVariants();

        if(empty($syncResult)) {
            return new WP_REST_Response([
                'error' => true,
                'message' => __('Variants not synced. Please check logs.', 'flare-press'),
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
                'message' => __('Error while getting variant names.', 'flare-press'),
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
                'message' => __('Error while getting account hash.', 'flare-press'),
            ], 500);
        }

        return new WP_REST_Response([

            'success' => true,
            'data' => $accountHash,
        ], 200);
    }
}