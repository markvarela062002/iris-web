<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $id
 * @property string|null $email
 * @property string $login_name
 * @property string $login_pass
 * @property string|null $login_type_id
 * @property string|null $school_id
 * @property string|null $sex
 * @property string|null $active
 * @property string|null $lname
 * @property string|null $fname
 * @property string|null $mname
 * @property \Illuminate\Support\Carbon|null $eula_signed
 * @property \Illuminate\Support\Carbon|null $expiration_date
 * @property-read string $full_name
 * @property-read string $profile_image
 * @property-read string $avatar
 */
#[Fillable([
    'email',
    'login_name',
    'login_pass',
    'login_type_id',
    'school_id',
    'eula_signed',
    'active',
    'expiration_date',
    'lname',
    'fname',
    'mname',
    'sex',
])]
#[Hidden([
    'login_pass',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Existing ADMAPro login table.
     */
    protected $table = 'login';

    /**
     * Existing UUID/string primary key.
     */
    protected $primaryKey = 'id';

    /**
     * The primary key is not auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The primary key uses a string value.
     */
    protected $keyType = 'string';

    /**
     * The existing login table has no Laravel timestamps.
     */
    public $timestamps = false;

    /**
     * Include these computed attributes when the
     * authenticated user is serialized for Inertia.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'full_name',
        'profile_image',
        'avatar',
    ];

    /**
     * Tell Laravel which column contains the password.
     */
    public function getAuthPasswordName(): string
    {
        return 'login_pass';
    }

    /**
     * Return the legacy plaintext password.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->login_pass;
    }

    /**
     * Disable remember-token persistence because the
     * legacy login table has no remember_token column.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * Return the user's complete name.
     */
    public function getFullNameAttribute(): string
    {
        return collect([
            $this->fname,
            $this->mname,
            $this->lname,
        ])
            ->filter(
                fn (mixed $name): bool =>
                    is_string($name) &&
                    trim($name) !== '',
            )
            ->map(
                fn (string $name): string =>
                    trim($name),
            )
            ->implode(' ');
    }

    /**
     * Select the profile image using the sex value.
     */
    public function getProfileImageAttribute(): string
    {
        $sex = strtoupper(
            trim((string) $this->sex),
        );

        if ($sex === 'F') {
            return '/images/female.png';
        }

        if ($sex === 'M') {
            return '/images/male.png';
        }

        return '/images/default-user.png';
    }

    /**
     * Provide avatar compatibility for Vue starter-kit
     * components that read user.avatar.
     */
    public function getAvatarAttribute(): string
    {
        return $this->profile_image;
    }

    /**
     * Determine whether the account is active.
     */
    public function isActive(): bool
    {
        return strtoupper(
            trim((string) $this->active),
        ) === 'Y';
    }

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'eula_signed' => 'date',
            'expiration_date' => 'date',
        ];
    }
}