<script setup lang="ts">
/**
 * Design System §8.2, top band of the sidebar. Screen Inventory S09.
 *
 * Three states, and the first is the one that matters most: **on a single
 * team the switcher is not a switcher.** It renders the team's name and
 * nothing to press — a control with one option is a decision nobody asked to
 * make. The other two are several teams, and none at all.
 *
 * Switching posts to the server rather than changing anything locally.
 * Issue #40: *"Context switching must change the resolved team for every
 * subsequent query, including anything queued from that request."* Only the
 * server can promise that.
 */
import { router } from '@inertiajs/vue3';
import { Check, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { TeamOption } from '@/types';

const props = withDefaults(
    defineProps<{
        name: string;
        plan?: string | null;
        mark?: string;
        teams?: TeamOption[];
        currentTeamId?: string | null;
        collapsed?: boolean;
    }>(),
    { plan: null, teams: () => [], currentTeamId: null, collapsed: false },
);

const switchable = computed(() => props.teams.length > 1);

function switchTo(team: TeamOption): void {
    if (team.id === props.currentTeamId) {
        return;
    }

    router.put('/teams/current', { team: team.id }, { preserveScroll: false });
}
</script>

<template>
    <DropdownMenu v-if="switchable">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex h-14 w-full items-center gap-[9px] border-b px-3 text-left transition-colors duration-150 ease-out hover:bg-accent/60"
                data-slot="team-switcher"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary text-[11px] font-bold text-primary-foreground"
                    aria-hidden="true"
                    >{{ mark ?? name.slice(0, 2).toUpperCase() }}</span
                >
                <span v-if="!collapsed" class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-sm font-semibold text-foreground"
                        >{{ name }}</span
                    >
                    <span
                        v-if="plan"
                        class="truncate text-[11px] text-muted-foreground"
                        >{{ plan }}</span
                    >
                </span>
                <ChevronsUpDown
                    v-if="!collapsed"
                    class="size-3.5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="min-w-56" align="start">
            <DropdownMenuLabel>Switch team</DropdownMenuLabel>
            <DropdownMenuItem
                v-for="team in teams"
                :key="team.id"
                class="min-h-11 gap-2"
                @select="switchTo(team)"
            >
                <Check
                    :class="
                        cn(
                            'size-4 shrink-0',
                            team.id === currentTeamId
                                ? 'opacity-100'
                                : 'opacity-0',
                        )
                    "
                    aria-hidden="true"
                />
                <span class="truncate">{{ team.name }}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <div
        v-else
        class="flex h-14 w-full items-center gap-[9px] border-b px-3 text-left"
        data-slot="team-switcher"
    >
        <span
            class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary text-[11px] font-bold text-primary-foreground"
            aria-hidden="true"
            >{{ mark ?? name.slice(0, 2).toUpperCase() }}</span
        >
        <span v-if="!collapsed" class="flex min-w-0 flex-1 flex-col">
            <span class="truncate text-sm font-semibold text-foreground">{{
                name
            }}</span>
            <span
                v-if="plan"
                class="truncate text-[11px] text-muted-foreground"
                >{{ plan }}</span
            >
        </span>
    </div>
</template>
