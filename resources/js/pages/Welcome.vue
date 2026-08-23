<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppButton from '@/components/app/AppButton.vue';
import AppLogoIcon from '@/components/app/AppLogoIcon.vue';

defineProps<{ name?: string }>();
</script>

<template>
    <Head title="Welcome" />
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6"
    >
        <AppLogoIcon class="size-50 dark:p-4" />
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
            <AppButton v-if="$page.props.auth?.user" href="/dashboard"
                >Go to dashboard</AppButton
            >
            <AppButton v-else href="/login">Log in</AppButton>
        </div>
    </div>
</template>
