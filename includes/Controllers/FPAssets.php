<?php

namespace FP\Controllers;

use FP\Utils\AdminPage;

class FPAssets
{
    public function __construct() {
        add_action('admin_print_footer_scripts', function($hook_suffix) {
            $this->enqueueUploadScript();
            $this->enqueueMainScript();
        });

        add_action('admin_enqueue_scripts', function() {
            $this->enqueueMainStyle();
        });
    }

    private function enqueueMainScript(): void {
        wp_enqueue_script('fp_main_script',FLARE_PRESS_PATH. 'scripts/script.js');
    }

    private function enqueueUploadScript(): void {
        if(AdminPage::is('upload.php')) {
            wp_enqueue_script('fp_upload_page_script',FLARE_PRESS_PATH. 'scripts/fp-upload-page.js');
        }
    }

    private function enqueueMainStyle(): void {
        wp_enqueue_style('fp_main_style',FLARE_PRESS_PATH. 'styles/style.css');
    }
}