<script setup lang="ts">
defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="grid min-h-dvh lg:grid-cols-2">
        <!-- LEFT SIDE -->
        <div
            class="relative hidden min-h-dvh overflow-hidden bg-white lg:flex lg:flex-col"
        >
            <!-- Background -->
            <div
                class="absolute inset-0 bg-gradient-to-br from-white via-[#F8FAFC] to-[#EAF1F8]"
            ></div>

            <!-- Decorative Background -->
            <div
                class="absolute -left-28 -top-28 h-[330px] w-[330px] rounded-full bg-[#387FC0]/[0.05]"
            ></div>

            <div
                class="absolute -bottom-40 -right-32 h-[390px] w-[390px] rounded-full bg-[#387FC0]/[0.06]"
            ></div>

            <div
                class="absolute left-[10%] top-[45%] h-20 w-20 rounded-full border border-[#387FC0]/10"
            ></div>

            <div
                class="absolute bottom-[12%] right-[12%] h-24 w-24 rounded-full border border-[#387FC0]/10"
            ></div>

            <!-- TOP LOGOS -->
            <div
                class="relative z-20 flex w-full items-center justify-center gap-10 px-8 pt-8 xl:gap-14 xl:pt-10"
            >
                <!-- RCL -->
                <img
                    src="/images/rcl.png"
                    alt="RCL Group"
                    class="h-14 w-36 object-contain xl:h-16 xl:w-40"
                />

                <!-- TRMF -->
                <img
                    src="/images/Trmf_logo.png"
                    alt="TRMF Foundation"
                    class="h-14 w-36 object-contain xl:h-16 xl:w-40"
                />

                <!-- Elosoft -->
                <img
                    src="/images/es.png"
                    alt="Elosoft"
                    class="h-14 w-36 object-contain xl:h-16 xl:w-40"
                />
            </div>

            <!-- CENTERED VIDEO -->
            <div
                class="relative z-10 flex min-h-0 flex-1 flex-col items-center justify-center px-10 pb-20 pt-6 text-center xl:px-14 xl:pb-24 2xl:px-16"
            >
                <video
                    autoplay
                    muted
                    loop
                    playsinline
                    preload="auto"
                    class="max-h-[50vh] w-full max-w-[760px] object-contain object-center"
                >
                    <source
                        src="/videos/introduction.mp4"
                        type="video/mp4"
                    />

                    Your browser does not support the video tag.
                </video>
            </div>

            <!-- FOOTER -->
            <div
                class="absolute bottom-6 left-8 z-20 text-xs text-[#64748B] xl:left-12 xl:text-sm"
            >
                © {{ new Date().getFullYear() }} Powered by Elosoft. All rights
                reserved.
            </div>
        </div>

        <!-- RIGHT SIDE -->
        <div
            class="relative flex min-h-dvh items-center justify-center overflow-hidden bg-[#387FC0] px-6 py-10 text-white sm:px-10 lg:px-16 xl:px-20 2xl:px-24"
        >
            <!-- Solid Blue Background -->
            <div class="absolute inset-0 bg-[#387FC0]"></div>

            <!-- Decorative Circles -->
            <div
                class="absolute -right-32 -top-32 h-[400px] w-[400px] rounded-full bg-white/[0.05]"
            ></div>

            <div
                class="absolute right-[14%] top-[38%] h-20 w-20 rounded-full border border-white/[0.08]"
            ></div>

            <div
                class="absolute left-[8%] top-[13%] h-14 w-14 rounded-full bg-white/[0.04]"
            ></div>

            <div
                class="absolute -bottom-40 -left-32 h-[390px] w-[390px] rounded-full bg-white/[0.04]"
            ></div>

            <div
                class="absolute bottom-[12%] right-[18%] h-28 w-28 rounded-full border border-white/[0.06]"
            ></div>

            <!-- Decorative Glows -->
            <div
                class="absolute -right-32 -top-32 h-[440px] w-[440px] rounded-full bg-white/[0.06] blur-3xl"
            ></div>

            <div
                class="absolute -bottom-40 -left-24 h-[460px] w-[460px] rounded-full bg-[#0B4F89]/15 blur-3xl"
            ></div>

            <!-- LOGIN CONTENT -->
            <div
                class="relative z-10 w-full max-w-[520px] xl:max-w-[560px]"
            >
                <!-- LOGIN HEADER -->
                <div class="mb-10">
                    <!-- IRIS LOGO -->
                    <div
                        class="mb-6 flex w-full items-center justify-center"
                    >
                        <img
                            src="/images/iris.png"
                            alt="IRIS Student Activity Monitoring System"
                            class="h-32 w-auto max-w-[320px] object-contain sm:h-36 sm:max-w-[340px] lg:h-32 xl:h-40 xl:max-w-[380px]"
                        />
                    </div>

                    <!-- Badge -->
                    <div
                        class="mb-5 inline-flex items-center rounded-full border border-white/30 bg-white/15 px-5 py-2 text-sm font-semibold tracking-wide text-white backdrop-blur-sm"
                    >
                        IRIS — Student Activity Monitoring System
                    </div>

                    <!-- Title -->
                    <h1
                        v-if="title"
                        class="text-4xl font-semibold tracking-tight text-white xl:text-[42px] xl:leading-[1.1] 2xl:text-[46px]"
                    >
                        {{ title }}
                    </h1>

                    <!-- Description -->
                    <p
                        v-if="description"
                        class="mt-4 text-base leading-7 text-white/75 xl:text-lg"
                    >
                        {{ description }}
                    </p>
                </div>

                <!-- LOGIN FORM -->
                <div class="text-base text-white">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>