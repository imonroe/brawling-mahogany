<script setup lang="ts">
/**
 * S05 system pages. IA §10: say what happened, then what to do.
 *
 * Three variants because the reader is different in each. The tenant variant
 * is the internal app; the admin variant is unmistakably the admin surface;
 * the client variant follows IA §9 — no alarming words, no jargon, and a
 * route back to a human.
 */
import type { Component } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        icon?: Component;
        code?: string | null;
        title: string;
        description: string;
        actionLabel?: string | null;
        actionHref?: string | null;
        variant?: 'tenant' | 'admin' | 'client';
    }>(),
    { variant: 'tenant', code: null, actionLabel: null, actionHref: null },
);
</script>

<template>
    <div
        :class="
            cn(
                'flex min-h-svh flex-col items-center justify-center gap-4 p-6 text-center',
                variant === 'admin' ? 'bg-foreground text-background' : 'bg-background',
                variant === 'client' && 'client-surface',
            )
        "
        data-slot="system-message"
    >
        <span
            v-if="variant === 'admin'"
            class="flex items-center gap-2 text-sm font-semibold"
            data-slot="admin-marker"
        >
            <ShieldAlert class="size-4" :stroke-width="2" aria-hidden="true" />
            Super admin
        </span>

        <component
            :is="icon"
            v-if="icon"
            :class="cn('size-8', variant === 'admin' ? 'text-background' : 'text-muted-foreground')"
            :stroke-width="1.5"
            aria-hidden="true"
        />

        <div class="flex flex-col gap-2">
            <p
                v-if="code"
                :class="
                    cn(
                        'tabular text-xs font-medium',
                        variant === 'admin' ? 'text-background/70' : 'text-muted-foreground',
                    )
                "
            >
                {{ code }}
            </p>
            <h1
                :class="
                    cn(
                        'font-semibold',
                        variant === 'client' ? 'text-2xl' : 'text-xl',
                        variant === 'admin' ? 'text-background' : 'text-foreground',
                    )
                "
            >
                {{ title }}
            </h1>
            <p
                :class="
                    cn(
                        'max-w-md',
                        variant === 'client' ? 'text-base' : 'text-sm',
                        variant === 'admin' ? 'text-background/70' : 'text-muted-foreground',
                    )
                "
            >
                {{ description }}
            </p>
        </div>

        <Link
            v-if="actionHref && actionLabel"
            :href="actionHref"
            :class="
                cn(
                    'inline-flex h-9 items-center rounded-md px-3.5 text-sm font-semibold',
                    variant === 'admin'
                        ? 'bg-background text-foreground'
                        : 'bg-primary text-primary-foreground',
                )
            "
            >{{ actionLabel }}</Link
        >
        <slot />
    </div>
</template>
