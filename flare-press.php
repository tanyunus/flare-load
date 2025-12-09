<?php
/*
Plugin Name: FlarePress
Description: WordPress plugin for uploading media directly to Cloudflare Images alongside the default uploader.
Version:     0.1.0
Author:      Yunus Tan
Author URI:  https://github.com/tanyunus/
License:     GPL-2.0+
Text Domain: flare-press
*/

if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'FLARE_PRESS_VERSION', '0.1.0' );
define( 'FLARE_PRESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLARE_PRESS_URL', plugin_dir_url( __FILE__ ) );


require_once FLARE_PRESS_PATH . 'vendor/autoload.php';


use FP\Controllers\FPDashboard;

function flarePressInit(): void
{
    new FPDashboard();
}

add_action( 'plugins_loaded', 'flarePressInit' );