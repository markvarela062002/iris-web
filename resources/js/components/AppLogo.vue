<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const name = usePage().props.name;
</script>

<template>
    <div class="flex w-full items-center justify-center">
        <img
            src="/images/iris.png"
            alt="IRIS"
            class="h-full w-full object-contain"
        />
    </div>
</template>