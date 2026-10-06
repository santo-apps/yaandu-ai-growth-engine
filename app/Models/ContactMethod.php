<?php

namespace App\Models;

use App\Contacts\ContactMethodValue;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMethod extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'contact_id', 'type', 'value', 'value_hash', 'source_url', 'observed_at', 'extraction_method', 'confidence', 'verification_status'];
    protected function casts(): array { return ['observed_at' => 'immutable_datetime']; }
    public function getValueAttribute(?string $value): ?string
    {
        return $value === null ? null : app(ContactMethodValue::class)->decrypt($value);
    }
    public function setValueAttribute(?string $value): void
    {
        $this->attributes['value'] = $value === null ? null : app(ContactMethodValue::class)->encrypt($value);
        if ($value !== null && isset($this->attributes['type'])) {
            $this->attributes['value_hash'] = app(ContactMethodValue::class)->fingerprint($this->attributes['type'], $value);
        }
    }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
