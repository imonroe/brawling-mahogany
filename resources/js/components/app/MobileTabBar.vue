<script setup lang="ts">
/**
 * The mobile collapse — IA §5.3, and the gap Design System §15.8 asks to be
 * closed before the PWA slice rather than discovered during it.
 *
 * The sidebar becomes a bottom tab bar carrying Dashboard, My Work, Deals,
 * and Calendar; everything else lives behind a "More" sheet. Targets are
 * 44px minimum, without exception (Design System §11).
 */
import { Link } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import NavItem from '@/components/app/NavItem.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/utils';
import { mobileTabs, moreEntries } from '@/lib/navigation';

const { can } = usePermissions();
const { isCurrentOrParentUrl } = useCurrentUrl();
const open = ref(false);

const tabs = computed(() => mobileTabs().filter((entry) => can(entry.permission)));
const more = computed(() => moreEntries().filter((entry) => can(entry.permission)));
</script>

<template>
    <nav
        class="flex h-16 shrink-0 items-stretch border-t bg-background shadow-lg md:hidden"
        aria-label="Main"
        data-slot="mobile-tab-bar"
    >
        <Link
            v-for="entry in tabs"
            :key="entry.href"
            :href="entry.href"
            :aria-current="isCurrentOrParentUrl(entry.href) ? 'page' : undefined"
            :class="
                cn(
                    'flex min-h-11 flex-1 flex-col items-center justify-center gap-1',
                    isCurrentOrParentUrl(entry.href) ? 'text-primary' : 'text-muted-foreground',
                )
            "
        >
            <component :is="entry.icon" class="size-5" :stroke-width="2" aria-hidden="true" />
            <span class="text-[11px] font-medium">{{ entry.label }}</span>
        </Link>

        <Sheet v-if="more.length" v-model:open="open">
            <SheetTrigger
                class="flex min-h-11 flex-1 flex-col items-center justify-center gap-1 text-muted-foreground"
            >
                <Ellipsis class="size-5" :stroke-width="2" aria-hidden="true" />
                <span class="text-[11px] font-medium">More</span>
            </SheetTrigger>
            <SheetContent side="bottom" class="p-4">
                <SheetHeader class="p-0">
                    <SheetTitle>More</SheetTitle>
                    <SheetDescription class="sr-only"
                        >The rest of the navigation</SheetDescription
                    >
                </SheetHeader>
                <div class="mt-3 flex flex-col gap-0.5">
                    <NavItem
                        v-for="entry in more"
                        :key="entry.href"
                        :entry="entry"
                        :active="isCurrentOrParentUrl(entry.href)"
                        class="h-11"
                        @click="open = false"
                    />
                </div>
            </SheetContent>
        </Sheet>
    </nav>
</template>
