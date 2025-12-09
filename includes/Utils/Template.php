<?php

namespace FP\Utils;

class Template {
    public static function render(string $template, array $data = [] ): void {
        extract( $data );
        $file = FLARE_PRESS_PATH . 'views/' . $template . '.php';

        if ( file_exists( $file ) ) {
            include $file;
        }
    }
}