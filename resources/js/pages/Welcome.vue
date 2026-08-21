<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/app/AppLogoIcon.vue';

defineProps<{ name?: string }>();
</script>

<template>
    <Head title="Welcome" />
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6"
    >
        <span
            class="flex size-11 items-center justify-center rounded-md bg-primary text-primary-foreground"
        >
            <AppLogoIcon class="size-6 fill-current" />
        </span>
        <div class="flex max-w-md flex-col gap-2 text-center">
            <h1 class="text-2xl font-semibold text-foreground">
                {{ $page.props.name }}
            </h1>
            <p class="text-sm text-muted-foreground">
                The process side of a real estate practice: workflows, stages,
                deadlines, and the client updates that follow from them.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <Link
                v-if="$page.props.auth?.user"
                href="/dashboard"
                class="inline-flex h-9 items-center rounded-md bg-primary px-3.5 text-sm font-semibold text-primary-foreground"
                >Go to dashboard</Link
            >
            <Link
                v-else
                href="/login"
                class="inline-flex h-9 items-center rounded-md bg-primary px-3.5 text-sm font-semibold text-primary-foreground"
                >Log in</Link
            >
        </div>
    </div>
</template>
