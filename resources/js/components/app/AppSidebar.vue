<script setup lang="ts">
/**
 * The 240px sidebar — Design System §8.2, contents and order from IA §5.1.
 *
 * Three nav groups separated by dividers, a flexible spacer, then the user
 * block. Sections the person lacks permission for are hidden; a group with
 * nothing left in it drops its divider too.
 */
import { usePage } from '@inertiajs/vue3';
import { EllipsisVertical } from '@lucide/vue';
import { computed } from 'vue';
import NavItem from '@/components/app/NavItem.vue';
import PersonAvatar from '@/components/app/PersonAvatar.vue';
import TeamSwitcher from '@/components/app/TeamSwitcher.vue';
import UserMenuContent from '@/components/app/UserMenuContent.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { NAV_GROUPS } from '@/lib/navigation';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        collapsed?: boolean;
        counts?: { myWork?: number | null };
    }>(),
    { collapsed: false },
);

const page = usePage();
const user = computed(() => page.props.auth?.user);
const { can } = usePermissions();
const { isCurrentOrParentUrl } = useCurrentUrl();

const groups = computed(() =>
    NAV_GROUPS.map((group) =>
        group.filter((entry) => can(entry.permission)),
    ).filter((group) => group.length > 0),
);
</script>

<template>
    <aside
        :class="
            cn(
                'flex h-full shrink-0 flex-col border-r bg-sidebar',
                props.collapsed ? 'w-14' : 'w-60',
            )
        "
        data-slot="app-sidebar"
    >
        <TeamSwitcher
            :name="page.props.name as string"
            plan="Team"
            :collapsed="collapsed"
        />

        <nav class="flex flex-1 flex-col overflow-y-auto" aria-label="Main">
            <div
                v-for="(group, index) in groups"
                :key="index"
                :class="
                    cn(
                        'flex flex-col gap-0.5 px-3 py-2.5',
                        index > 0 && 'border-t',
                    )
                "
            >
                <NavItem
                    v-for="entry in group"
                    :key="entry.href"
                    :entry="entry"
                    :collapsed="collapsed"
                    :active="isCurrentOrParentUrl(entry.href)"
                    :count="
                        entry.countKey
                            ? (props.counts?.[entry.countKey] ?? null)
                            : null
                    "
                />
            </div>
        </nav>

        <DropdownMenu v-if="user">
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="flex h-15 w-full items-center gap-[9px] border-t px-3 text-left transition-colors duration-150 ease-out hover:bg-accent/60"
                    data-test="sidebar-menu-button"
                >
                    <PersonAvatar :person="{ name: user.name }" :size="30" />
                    <span
                        v-if="!collapsed"
                        class="flex min-w-0 flex-1 flex-col"
                    >
                        <span
                            class="truncate text-13 font-medium text-foreground"
                            >{{ user.name }}</span
                        >
                        <span
                            class="truncate text-[11px] text-muted-foreground"
                            >{{ user.email }}</span
                        >
                    </span>
                    <EllipsisVertical
                        v-if="!collapsed"
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                class="min-w-56 rounded-lg"
                side="top"
                align="start"
            >
                <UserMenuContent :user="user" />
            </DropdownMenuContent>
        </DropdownMenu>
    </aside>
</template>
