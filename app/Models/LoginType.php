<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginType extends Model
{
    protected $table = 'login_type';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'code_type',
        'is_admin',
        'allow_delete',
        'type_level',
        'internal',
    ];

    public function isAdministrator(): bool
    {
        return strtoupper(
            trim((string) $this->is_admin),
        ) === 'Y';
    }

    public function canDelete(): bool
    {
        return strtoupper(
            trim((string) $this->allow_delete),
        ) === 'Y';
    }

    public function isInternal(): bool
    {
        return strtoupper(
            trim((string) $this->internal),
        ) === 'Y';
    }
}