<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class ScraperRequest extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'base_url',
        'endpoint',
        'query',
        'bearer_token',
        'request_url',
        'curl_command',
        'response_status',
        'response_body',
    ];

    /**
     * Encrypt the bearer token when storing and decrypt when retrieving.
     */
    protected function bearerToken(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?string => $value ? Crypt::decryptString($value) : null,
            set: static fn (?string $value): ?string => $value ? Crypt::encryptString($value) : null,
        );
    }

    /**
     * The user who owns the request entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
