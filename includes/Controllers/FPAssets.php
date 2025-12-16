<?php

namespace FP\Controllers;

use FP\Utils\AdminPage;

class FPAssets
{
    public function __construct() {
        add_action('admin_print_footer_scripts', function() {
            $this->enqueueScripts();
        });

        add_action('admin_enqueue_scripts', function() {
            $this->enqueueStyles();
        });
    }

    private function enqueueStyles(): void {
        wp_enqueue_style('fp_main_style',FLARE_PRESS_PATH. 'styles/style.css');
    }

    private function enqueueScripts(): void {
        wp_enqueue_script('fp-main', FLARE_PRESS_PATH. 'scripts/fp-main.js');
    }

}