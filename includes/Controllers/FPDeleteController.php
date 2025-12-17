<?php

namespace FP\Controllers;

class FPDeleteController
{
    public function __construct() {
        add_action('delete_attachment', function($attachmentId, $attachment) {
            
        });
    }
}