<script setup lang="ts">
const currentYear = new Date().getFullYear();
</script>

<template>
    <footer
        class="mt-auto flex min-h-12 w-full shrink-0 items-center justify-center border-t border-[#377EC0]/15 bg-white px-6 py-3 text-center text-xs text-slate-500"
    >
        <p>
            © {{ currentYear }}
            <span class="font-bold text-[#B82C32]">E</span><span
                class="font-bold text-[#21365A]"
            >LO</span><span
                class="font-bold text-[#38A0DE]"
            >SOFT</span>
            Inc.
        </p>
    </footer>
</template>