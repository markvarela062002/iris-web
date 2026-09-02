<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

import type { SharedData } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage<SharedData>();

const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">
        Profile settings
    </h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your personal information and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            v-slot="{ errors, processing }"
            class="space-y-6"
        >
            <!-- FIRST NAME -->
            <div class="grid gap-2">
                <Label for="fname">
                    First name
                </Label>

                <Input
                    id="fname"
                    name="fname"
                    type="text"
                    class="mt-1 block w-full"
                    :default-value="user?.fname ?? ''"
                    required
                    autocomplete="given-name"
                    placeholder="First name"
                />

                <InputError
                    class="mt-2"
                    :message="errors.fname"
                />
            </div>

            <!-- MIDDLE NAME -->
            <div class="grid gap-2">
                <Label for="mname">
                    Middle name
                </Label>

                <Input
                    id="mname"
                    name="mname"
                    type="text"
                    class="mt-1 block w-full"
                    :default-value="user?.mname ?? ''"
                    autocomplete="additional-name"
                    placeholder="Middle name"
                />

                <InputError
                    class="mt-2"
                    :message="errors.mname"
                />
            </div>

            <!-- LAST NAME -->
            <div class="grid gap-2">
                <Label for="lname">
                    Last name
                </Label>

                <Input
                    id="lname"
                    name="lname"
                    type="text"
                    class="mt-1 block w-full"
                    :default-value="user?.lname ?? ''"
                    required
                    autocomplete="family-name"
                    placeholder="Last name"
                />

                <InputError
                    class="mt-2"
                    :message="errors.lname"
                />
            </div>

            <!-- EMAIL -->
            <div class="grid gap-2">
                <Label for="email">
                    Email address
                </Label>

                <Input
                    id="email"
                    name="email"
                    type="email"
                    class="mt-1 block w-full"
                    :default-value="user?.email ?? ''"
                    autocomplete="email"
                    placeholder="Email address"
                />

                <InputError
                    class="mt-2"
                    :message="errors.email"
                />
            </div>

            <!-- SAVE BUTTON -->
            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="processing"
                    data-test="update-profile-button"
                >
                    {{
                        processing
                            ? 'Saving...'
                            : 'Save'
                    }}
                </Button>
            </div>
        </Form>
    </div>
</template>