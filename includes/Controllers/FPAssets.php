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
       //Enqueue modules
        echo '<script src="'.FLARE_PRESS_PATH.'scripts/fp-media-library-monitor.js"></script>';

        //Enqueue scripts
        echo '<script src="'.FLARE_PRESS_PATH.'scripts/script.js"></script>';

        if(AdminPage::is('upload.php')) {
            echo '<script src="'.FLARE_PRESS_PATH.'scripts/fp-upload-page.js"></script>';
        }
    }

}