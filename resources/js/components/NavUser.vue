<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';

import UserMenuContent from '@/components/UserMenuContent.vue';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';

import type { SharedData } from '@/types';

type AuthenticatedAccount = SharedData['auth']['user'] & {
    fname?: string | null;
    mname?: string | null;
    lname?: string | null;
    login_name?: string | null;
    email?: string | null;
    sex?: string | null;
    gender?: string | null;
    avatar?: string | null;
    account_type?: 'administrator' | 'student';
    role?: string | null;
    role_id?: string | null;
    is_admin?: boolean;
    can_delete?: boolean;
    is_internal?: boolean;
};

const page = usePage<SharedData>();

const user = computed(() => {
    return page.props.auth.user as AuthenticatedAccount;
});

const displayName = computed(() => {
    const fullName = [
        user.value.fname,
        user.value.mname,
        user.value.lname,
    ]
        .filter(
            (name): name is string =>
                typeof name === 'string' &&
                name.trim() !== '',
        )
        .map((name) => name.trim())
        .join(' ');

    return (
        fullName ||
        user.value.login_name ||
        'User'
    );
});

const roleName = computed(() => {
    return user.value.role || 'CADET';
});

const accountType = computed(() => {
    return user.value.account_type === 'student'
        ? 'Student Account'
        : 'Staff Account';
});

const profileImage = computed(() => {
    if (user.value.avatar) {
        return user.value.avatar;
    }

    const gender = String(
        user.value.sex ??
            user.value.gender ??
            '',
    )
        .trim()
        .toUpperCase();

    if (gender === 'F') {
        return '/images/female.png';
    }

    if (gender === 'M') {
        return '/images/male.png';
    }

    return '/images/default-user.png';
});

const displayUser = computed(() => ({
    ...user.value,
    name: displayName.value,
    avatar: profileImage.value,
    email: roleName.value,
}));

const { isMobile, state } = useSidebar();
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="!h-auto min-h-[76px] w-full gap-3 px-3 py-2 !text-white hover:!bg-white/10 hover:!text-white data-[state=open]:!bg-white/10 data-[state=open]:!text-white"
                        data-test="sidebar-menu-button"
                    >
                        <!-- Profile Image -->
                        <img
                            :src="profileImage"
                            :alt="displayName"
                            class="size-10 shrink-0 rounded-lg object-cover"
                        />

                        <!-- Role and Full Name -->
                        <div
                            v-if="state !== 'collapsed'"
                            class="min-w-0 flex-1 text-left"
                        >
                            <!-- Role Identifier -->
                            <div
                                class="mb-1 text-[10px] leading-none font-bold tracking-wide !text-white/80 uppercase"
                            >
                                {{ roleName }}
                            </div>

                            <!-- Complete User Name -->
                            <div
                                class="break-words whitespace-normal text-sm leading-5 font-semibold !text-white"
                            >
                                {{ displayName }}
                            </div>
                        </div>

                        <!-- Dropdown Icon -->
                        <ChevronsUpDown
                            v-if="state !== 'collapsed'"
                            class="ml-auto size-4 shrink-0 !text-white/80"
                        />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>

                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="end"
                    :side-offset="4"
                >
                    <!-- Account Information -->
                    <div class="border-b px-3 py-3">


                        <p class="mt-1 text-sm font-semibold">
                            {{ roleName }}
                        </p>
                    </div>

                    <UserMenuContent
                        :user="displayUser"
                    />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>