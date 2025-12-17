<?php

namespace FlarePress\Controllers;

class DeleteController
{
    public function __construct() {
        add_action('delete_attachment', function($attachmentId, $attachment) {
            
        });
    }
}