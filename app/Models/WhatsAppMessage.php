<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'phone',
        'message',
        'media_path',
        'media_type',
        'provider_message_id',
        'api_response',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * Get status badge HTML
     */
    public function getStatusBadgeAttribute()
    {
        $badges = [
            'pending' => '<span class="badge bg-warning">Pending</span>',
            'sent' => '<span class="badge bg-info">Sent</span>',
            'delivered' => '<span class="badge bg-primary">Delivered</span>',
            'read' => '<span class="badge bg-success">Read</span>',
            'failed' => '<span class="badge bg-danger">Failed</span>',
        ];

        return $badges[$this->status] ?? '<span class="badge bg-secondary">Unknown</span>';
    }

    /**
     * Check if message has media
     */
    public function hasMedia()
    {
        return !empty($this->media_path);
    }
}
