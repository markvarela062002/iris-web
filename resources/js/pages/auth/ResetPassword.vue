<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Reset password',
        description: 'Contact your administrator for password assistance',
    },
});
</script>

<template>
    <Head title="Reset password" />

    <div class="space-y-6">
        <p class="text-center text-sm text-muted-foreground">
            Password reset is currently unavailable. Please contact your
            administrator for assistance.
        </p>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>Return to</span>
            <TextLink :href="login()">log in</TextLink>
        </div>
    </div>
</template>