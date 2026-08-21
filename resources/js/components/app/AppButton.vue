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
 * action look identical and behave correctly.
 */
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
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
    size: 'default',
    type: 'button',
    disabled: false,
});
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        :href="href"
        :type="href ? undefined : props.type"
        :disabled="href ? undefined : props.disabled"
        :aria-disabled="props.disabled ? 'true' : undefined"
        :class="
            cn(
                appButtonVariants({
                    variant: props.variant,
                    size: props.size,
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
