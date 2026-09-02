<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your credentials below to continue',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <!-- STATUS MESSAGE -->
    <div
        v-if="status"
        class="mb-5 rounded-lg border border-white/20 bg-white/10 px-4 py-3 text-center text-sm font-medium text-white"
    >
        {{ status }}
    </div>

    <!-- LOGIN FORM -->
    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-7"
    >
        <div class="grid gap-6">
            <!-- USERNAME -->
            <div class="grid gap-2.5">
                <Label
                    for="login_name"
                    class="text-sm font-semibold text-white"
                >
                    Username
                </Label>

                <Input
                    id="login_name"
                    type="text"
                    name="login_name"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="username"
                    placeholder="Enter username"
                    class="
                        h-12
                        border-white/40
                        bg-white/5
                        px-4
                        text-base
                        text-white
                        shadow-none
                        placeholder:text-[#D1E2F0]
                        transition-all
                        hover:border-white/60
                        hover:bg-white/[0.07]
                        focus-visible:border-white
                        focus-visible:bg-white/10
                        focus-visible:ring-2
                        focus-visible:ring-white/15
                    "
                />

                <InputError :message="errors.login_name" />
            </div>

            <!-- PASSWORD -->
            <div class="grid gap-2.5">
                <Label
                    for="password"
                    class="text-sm font-semibold text-white"
                >
                    Password
                </Label>

                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Enter password"
                    class="
                        h-12
                        border-white/40
                        bg-white/5
                        text-base
                        text-white
                        shadow-none
                        placeholder:text-[#D1E2F0]
                        transition-all
                        hover:border-white/60
                        hover:bg-white/[0.07]
                        focus-visible:border-white
                        focus-visible:bg-white/10
                        focus-visible:ring-2
                        focus-visible:ring-white/15
                    "
                />

                <InputError :message="errors.password" />
            </div>

            <!-- DATABASE CODE -->
            <div class="grid gap-2.5">
                <Label
                    for="code"
                    class="text-sm font-semibold text-white"
                >
                    Code
                </Label>

                <Input
                    id="code"
                    type="text"
                    name="code"
                    required
                    :tabindex="3"
                    autocomplete="off"
                    autocapitalize="characters"
                    :spellcheck="false"
                    placeholder="Enter code"
                    class="
                        h-12
                        border-white/40
                        bg-white/5
                        px-4
                        text-base
                        uppercase
                        text-white
                        shadow-none
                        placeholder:normal-case
                        placeholder:text-[#D1E2F0]
                        transition-all
                        hover:border-white/60
                        hover:bg-white/[0.07]
                        focus-visible:border-white
                        focus-visible:bg-white/10
                        focus-visible:ring-2
                        focus-visible:ring-white/15
                    "
                />

                <InputError :message="errors.code" />
            </div>

            <!-- LOGIN BUTTON -->
            <Button
                type="submit"
                class="
                    mt-2
                    h-12
                    w-full
                    bg-white
                    text-base
                    font-semibold
                    text-[#0B1F3A]
                    transition-all
                    hover:bg-[#EAF1F8]
                    focus-visible:ring-2
                    focus-visible:ring-white/30
                    disabled:opacity-60
                "
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />

                <span>
                    {{ processing ? 'Logging in...' : 'Log in' }}
                </span>
            </Button>
        </div>
    </Form>
</template>