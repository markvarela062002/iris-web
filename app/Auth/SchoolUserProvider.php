<?php

namespace App\Auth;

use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

class SchoolUserProvider implements UserProvider
{
    /**
     * Restore the authenticated administrator or student
     * using the account type stored in the session.
     */
    public function retrieveById(
        $identifier,
    ): ?Authenticatable {
        $accountType = (string) session(
            'account_type',
            '',
        );

        $model = match ($accountType) {
            'administrator' => new User(),
            'student' => new Student(),
            default => null,
        };

        if (! $model) {
            return null;
        }

        $connection = session(
            'database_connection',
        );

        if (
            is_string($connection) &&
            $connection !== ''
        ) {
            $model->setConnection($connection);
        }

        return $model
            ->newQuery()
            ->with('loginType')
            ->find($identifier);
    }

    /**
     * Remember-token login is disabled.
     */
    public function retrieveByToken(
        $identifier,
        $token,
    ): ?Authenticatable {
        return null;
    }

    /**
     * Remember-token persistence is disabled.
     */
    public function updateRememberToken(
        Authenticatable $user,
        $token,
    ): void {
        //
    }

    /**
     * Fortify uses authenticateUsing(), so credential
     * retrieval is handled in FortifyServiceProvider.
     *
     * @param array<string, mixed> $credentials
     */
    public function retrieveByCredentials(
        array $credentials,
    ): ?Authenticatable {
        return null;
    }

    /**
     * Validate the legacy plaintext password.
     *
     * @param array<string, mixed> $credentials
     */
    public function validateCredentials(
        Authenticatable $user,
        array $credentials,
    ): bool {
        $storedPassword = (string) $user
            ->getAuthPassword();

        $submittedPassword = (string) (
            $credentials['password'] ?? ''
        );

        if (
            $storedPassword === '' ||
            $submittedPassword === ''
        ) {
            return false;
        }

        return hash_equals(
            $storedPassword,
            $submittedPassword,
        );
    }

    /**
     * Legacy plaintext passwords are not automatically
     * rehashed at this time.
     *
     * @param array<string, mixed> $credentials
     */
    public function rehashPasswordIfRequired(
        Authenticatable $user,
        array $credentials,
        bool $force = false,
    ): void {
        //
    }
}