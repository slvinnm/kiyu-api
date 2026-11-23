<?php

namespace App\Enums;

enum WhatsAppMessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';

    /**
     * Whether this message type carries a media attachment.
     */
    public function hasMedia(): bool
    {
        return $this !== self::Text;
    }
}
