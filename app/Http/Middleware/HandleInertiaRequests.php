<?php

namespace App\Http\Middleware;

use App\Models\LoginType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props shared with every Inertia response.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /*
         * The school code exists only after a successful login.
         * There is intentionally no default school.
         */
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        $schools = config('schools.schools', []);

        $school = null;

        if (
            $schoolCode !== '' &&
            is_array($schools)
        ) {
            $configuredSchool = $schools[$schoolCode] ?? null;

            if (is_array($configuredSchool)) {
                $school = $configuredSchool;
            }
        }

        /*
         * Guests receive neutral application branding.
         * This does not represent a default school or database.
         */
        $schoolIdentity = $school
            ? [
                'code' => strtoupper(
                    trim(
                        (string) (
                            $school['code'] ??
                            $schoolCode
                        ),
                    ),
                ),

                'name' => (string) (
                    $school['name'] ??
                    config('app.name')
                ),

                'logo' => (string) (
                    $school['logo'] ??
                    '/images/iris.png'
                ),
            ]
            : [
                'code' => '',

                'name' => (string) config(
                    'app.name',
                    'IRIS - SAM',
                ),

                'logo' => '/images/iris.png',
            ];

        /*
         * Retrieve the authenticated administrator or student.
         */
        $authenticatedAccount = $request->user();

        $account = (
            $authenticatedAccount instanceof User ||
            $authenticatedAccount instanceof Student
        ) ? $authenticatedAccount : null;

        /*
         * Load the role assigned through login_type.
         */
        $role = null;

        if ($account !== null) {
            $account->loadMissing('loginType');

            $assignedRole = $account->getRelationValue('loginType');

            if ($assignedRole instanceof LoginType) {
                $role = $assignedRole;
            }
        }

        /*
         * Normalize both User and Student models into the
         * same structure for the Vue application.
         */
        $authenticatedUser = $account !== null
            ? [
                ...$account->toArray(),

                'account_type' => (string) (
                    $account->getAttribute('account_type') ?? 'unknown'
                ),

                'role' => $role !== null
                    ? (string) $role->getAttribute('code_type')
                    : 'CADET',

                'role_id' => $account->getAttribute('login_type_id')
                    ?? $account->getAttribute('login_id'),

                'is_admin' => $role !== null
                    ? $role->isAdministrator()
                    : false,

                'can_delete' => $role !== null
                    ? $role->canDelete()
                    : false,

                'is_internal' => $role !== null
                    ? $role->isInternal()
                    : false,
            ]
            : null;

        return [
            ...parent::share($request),

            'name' => config('app.name'),

            'auth' => [
                'user' => $authenticatedUser,
            ],

            'school' => $schoolIdentity,

            'sidebarOpen' => ! $request->hasCookie('sidebar_state')
                || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
