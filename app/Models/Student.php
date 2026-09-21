<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $id
 * @property string|null $code_person
 * @property string|null $email
 * @property string|null $login_name
 * @property string|null $login_pass
 * @property string|null $login_id
 * @property string|null $school_id
 * @property string|null $school_id_no
 * @property string|null $gender
 * @property string|null $active
 * @property string $lname
 * @property string|null $fname
 * @property string|null $mname
 * @property string|null $dept
 * @property string|null $batch_no
 * @property string|null $access_exp
 * @property-read string $full_name
 * @property-read string $profile_image
 * @property-read string $avatar
 * @property-read string $account_type
 * @property-read LoginType|null $loginType
 */
#[Fillable([
    'code_person',
    'email',
    'login_name',
    'login_pass',
    'login_id',
    'school_id',
    'school_id_no',
    'gender',
    'active',
    'lname',
    'fname',
    'mname',
    'dept',
    'batch_no',
    'access_exp',
])]
#[Hidden([
    'login_pass',
])]
class Student extends Authenticatable
{
    use Notifiable;

    /**
     * Existing ADMAPro student table.
     */
    protected $table = 'person';

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
     * The person table has no Laravel timestamps.
     */
    public $timestamps = false;

    /**
     * Include computed attributes when the authenticated
     * student is serialized for Inertia.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'full_name',
        'profile_image',
        'avatar',
        'account_type',
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
     * person table has no remember_token column.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * Return the student's complete name.
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
     * Select the profile image using the gender value.
     */
    public function getProfileImageAttribute(): string
    {
        $gender = strtoupper(
            trim((string) $this->gender),
        );

        if ($gender === 'F') {
            return '/images/female.png';
        }

        if ($gender === 'M') {
            return '/images/male.png';
        }

        return '/images/default-user.png';
    }

    /**
     * Provide avatar compatibility for Vue starter-kit
     * components that read student.avatar.
     */
    public function getAvatarAttribute(): string
    {
        return $this->profile_image;
    }

    /**
     * Identify this authenticated account as a student.
     */
    public function getAccountTypeAttribute(): string
    {
        return 'student';
    }

    /**
     * Get the student's role.
     *
     * person.login_id references login_type.id.
     */
    public function loginType(): BelongsTo
    {
        return $this->belongsTo(
            LoginType::class,
            'login_id',
            'id',
        );
    }

    /**
     * Determine whether the student account is active.
     */
    public function isActive(): bool
    {
        return strtoupper(
            trim((string) $this->active),
        ) === 'Y';
    }
}