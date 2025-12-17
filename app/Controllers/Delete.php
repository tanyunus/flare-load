<?php

namespace FlarePress\Controllers;

class Delete
{
    public function __construct() {
        add_action('delete_attachment', function($attachmentId, $attachment) {
            
        });
    }
}