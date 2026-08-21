import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';

/**
 * The measured control sizes — Design System §4.2 and §7.2.
 *
 * shadcn's generated `Button` and `Input` ship their own sizes, and §13.2
 * rule 4 forbids editing generated source. The sanctioned way to differ is a
 * `cva` variant set, which is this file: the numbers come from the built
 * designs, and `AppButton`/`AppInput` apply them over the shadcn primitives.
 *
 * Where these disagree with shadcn's defaults, the difference is deliberate:
 *
 * | Control   | shadcn      | Design System §4.2 |
 * |-----------|-------------|--------------------|
 * | Primary   | h-9 px-4/500| h-9 px-3.5 / 600   |
 * | Ghost     | h-8 px-3    | h-8 px-2.5         |
 * | Compact   | —           | h-7 px-2.5 / 12 / 600 |
 * | Input     | h-9         | h-10 px-3          |
 */

export const appButtonVariants = cva(
    'inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md whitespace-nowrap transition-colors duration-150 ease-out outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary:
                    'bg-primary font-semibold text-primary-foreground hover:bg-primary/90',
                secondary:
                    'border bg-background font-medium text-secondary-foreground hover:bg-accent',
                ghost: 'font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                // A destructive action reuses the primary shape with the fill
                // swapped (§7.2), which is what makes it read as consequential
                // rather than as broken.
                destructive:
                    'bg-destructive font-semibold text-destructive-foreground hover:bg-destructive/90',
                // "Override and Advance" is warning-filled for the same reason:
                // it is deliberate and auditable, not an error.
                warning:
                    'bg-state-warning font-semibold text-primary-foreground hover:bg-state-warning/90',
            },
            size: {
                // 44px on a phone, the measured height on a pointer device
                // (§11: 44px minimum on mobile, without exception).
                default:
                    'min-h-11 px-3.5 text-sm md:h-9 md:min-h-0 [&_svg]:size-4',
                ghost: 'min-h-11 px-2.5 text-sm md:h-8 md:min-h-0 [&_svg]:size-4',
                compact:
                    'min-h-11 px-2.5 text-xs md:h-7 md:min-h-0 [&_svg]:size-3.5',
            },
            disabled: {
                // §7.2: a disabled primary is muted, not a faded fill.
                true: 'bg-muted text-muted-foreground hover:bg-muted',
                false: '',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'default',
            disabled: false,
        },
    },
);

export type AppButtonVariants = VariantProps<typeof appButtonVariants>;

export const appInputVariants = cva(
    'flex w-full rounded-md border bg-background px-3 text-sm text-foreground transition-colors duration-150 ease-out placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive',
    {
        variants: {
            size: {
                // §4.2: the form control is 40px. Never `text-13` — that is for
                // rows, not for anything a person types into (§3.3).
                default: 'h-10',
                // §8.6: the inline filter control.
                filter: 'h-8 text-xs',
            },
        },
        defaultVariants: {
            size: 'default',
        },
    },
);

export type AppInputVariants = VariantProps<typeof appInputVariants>;
