<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WebIndexDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'canonical_url', 'normalized_domain', 'page_title', 'organization_name', 'description', 'visible_text_excerpt',
        'country', 'region', 'city', 'address_text', 'phone_values', 'email_values', 'structured_data',
        'outbound_business_links', 'document_type', 'source', 'source_reference', 'source_timestamp', 'indexed_at',
        'content_hash', 'normalized_name', 'normalized_city', 'normalized_country', 'normalized_address', 'normalized_phone', 'search_text',
    ];

    protected function casts(): array
    {
        return [
            'phone_values' => 'array', 'email_values' => 'array', 'structured_data' => 'array',
            'outbound_business_links' => 'array', 'source_timestamp' => 'immutable_datetime', 'indexed_at' => 'immutable_datetime',
        ];
    }
}
