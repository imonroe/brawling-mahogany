<script setup lang="ts">
/**
 * A button at the measured sizes — Design System §4.2 and §7.2.
 *
 * Wraps nothing: shadcn's `Button` carries its own size and weight, and
 * overriding both through `class` is less legible than owning the small
 * amount of markup a button actually is. The variants live in
 * `controlVariants.ts` so a screen picks a size rather than inventing one.
 *
 * Renders as an Inertia `Link` when given an `href`, so a navigation and an
 * action look identical and behave correctly — except when disabled, where it
 * falls back to a real disabled `<button>`. An `<a aria-disabled>` is still
 * clickable and still focusable, and `disabled:pointer-events-none` never
 * matches an anchor, which matters most on exactly the variants where it would
 * hurt: `destructive` and `warning`.
 */
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { appButtonVariants } from './controlVariants';
import type { AppButtonVariants } from './controlVariants';

type Props = {
    variant?: AppButtonVariants['variant'];
    size?: AppButtonVariants['size'];
    href?: NonNullable<InertiaLinkProps['href']>;
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'primary',
    type: 'button',
    disabled: false,
});

/*
 * A ghost button is 32px, not 36px (§7.2). Deriving the default from the
 * variant means `<AppButton variant="ghost">` is the measured ghost button
 * rather than a ghost-coloured primary — the size can still be named
 * explicitly when a screen genuinely wants the other one.
 */
const resolvedSize = computed(
    () => props.size ?? (props.variant === 'ghost' ? 'ghost' : 'default'),
);

// A disabled link is not a thing: it navigates anyway.
const renderAsLink = computed(() => Boolean(props.href) && !props.disabled);
</script>

<template>
    <component
        :is="renderAsLink ? Link : 'button'"
        :href="renderAsLink ? href : undefined"
        :type="renderAsLink ? undefined : props.type"
        :disabled="renderAsLink ? undefined : props.disabled"
        :class="
            cn(
                appButtonVariants({
                    variant: props.variant,
                    size: resolvedSize,
                    disabled: props.disabled,
                }),
                props.class,
            )
        "
        data-slot="app-button"
    >
        <slot />
    </component>
</template>
