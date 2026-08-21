<script setup lang="ts">
/**
 * S05 — the layout behind login, invitations, and the two-factor challenge.
 * A single centred column, no navigation, nothing to distract from the one
 * thing being asked for.
 */
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/app/AppLogoIcon.vue';

withDefaults(defineProps<{ title?: string; description?: string }>(), {
    title: '',
    description: '',
});
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6">
        <Head :title="title" />
        <div class="flex w-full max-w-sm flex-col gap-8">
            <div class="flex flex-col items-center gap-4">
                <Link href="/" class="flex flex-col items-center gap-2">
                    <span
                        class="flex size-9 items-center justify-center rounded-md bg-primary text-primary-foreground"
                    >
                        <AppLogoIcon class="size-5 fill-current" />
                    </span>
                    <span class="sr-only">Home</span>
                </Link>
                <div class="flex flex-col gap-2 text-center">
                    <h1 class="text-xl font-semibold text-foreground">{{ title }}</h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
            </div>
            <slot />
        </div>
    </div>
</template>
