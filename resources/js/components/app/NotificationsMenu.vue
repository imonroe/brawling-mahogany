<script setup lang="ts">
/**
 * S08 — the notification panel (PRD §4.12 F12.4 · issue #101).
 *
 * ## It fetches when it opens, and only then
 *
 * The shell carries an unread **count** on every request and nothing else.
 * The lines come down on a partial reload the first time somebody opens the
 * menu — `Inertia::optional` on the server, `router.reload({ only: [...] })`
 * here — because shipping eight rendered notifications to every page in case
 * somebody looks is the fixed cost `DealsIndexBudgetTest` exists to notice.
 *
 * ## Four states, and #101 names them
 *
 * Empty, unread, grouped, mark-all-read. The grouped one is the requirement
 * rather than a nicety: instantiating a workflow assigns a dozen tasks in one
 * second, and a panel that draws twelve lines for that is a panel whose badge
 * means "a workflow started" and whose contents nobody scrolls.
 */
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, Check } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import IconButton from '@/components/app/IconButton.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatRelativeDate } from '@/lib/formatters';

type Group = {
    id: string;
    type: string;
    summary: string;
    dealId: string | null;
    dealName: string | null;
    teamId: string;
    teamName: string | null;
    url: string | null;
    occurredAt: string | null;
    count: number;
    unread: number;
    ids: string[];
};

const page = usePage();

const open = ref(false);
const loaded = ref(false);

const unread = computed<number>(
    () =>
        (page.props.counts as { notifications?: number } | null)
            ?.notifications ?? 0,
);

const groups = computed<Group[]>(
    () => (page.props.notifications as Group[] | undefined) ?? [],
);

/**
 * Whether more than one team is represented.
 *
 * The team's name is on a line only when it disambiguates. For the ordinary
 * person — one team — repeating the agency's name eight times is noise that
 * makes the deal name harder to find; for the stager working two, it is the
 * whole point of the row carrying `team_id`.
 */
const showsTeams = computed(
    () => new Set(groups.value.map((group) => group.teamId)).size > 1,
);

watch(open, (isOpen) => {
    if (!isOpen || loaded.value) {
        return;
    }

    router.reload({
        only: ['notifications'],
        onSuccess: () => {
            loaded.value = true;
        },
    });
});

function markRead(group: Group): void {
    if (group.unread === 0) {
        return;
    }

    /*
     * Every id the line folded, not just the newest. A line that dismissed one
     * of twelve would leave the badge saying eleven, which is the panel
     * telling somebody their action did not work.
     */
    group.ids.forEach((id) => {
        router.post(
            '/notifications/read',
            { notification: id },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['counts', 'notifications'],
            },
        );
    });
}

function markAllRead(): void {
    router.post(
        '/notifications/read',
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['counts', 'notifications'],
        },
    );
}
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger as-child>
            <IconButton
                :icon="Bell"
                label="Notifications"
                :unread="unread > 0"
            />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-80 p-0">
            <div
                class="flex items-center justify-between border-b border-border px-3 py-2"
            >
                <span class="text-13 font-medium">Notifications</span>
                <button
                    v-if="unread > 0"
                    type="button"
                    :class="[
                        'inline-flex',
                        'items-center',
                        'gap-1',
                        'text-[11px]',
                        'text-muted-foreground',
                        'hover:text-foreground',
                    ]"
                    @click="markAllRead"
                >
                    <Check class="size-3" />
                    Mark all read
                </button>
            </div>

            <!--
                Empty is a real state, not an oversight. #101 lists it first,
                and a menu that opens on nothing with no sentence in it reads
                as broken rather than as quiet.
            -->
            <p
                v-if="loaded && groups.length === 0"
                class="px-3 py-6 text-center text-13 text-muted-foreground"
            >
                Nothing yet. You will hear about tasks assigned to you and
                anything that needs a look.
            </p>

            <p
                v-else-if="!loaded"
                class="px-3 py-6 text-center text-13 text-muted-foreground"
            >
                Loading…
            </p>

            <ul v-else class="max-h-96 divide-y divide-border overflow-y-auto">
                <li v-for="group in groups" :key="group.id">
                    <component
                        :is="group.url ? Link : 'div'"
                        :href="group.url ?? undefined"
                        :class="[
                            'block',
                            'px-3',
                            'py-2.5',
                            'text-13',
                            group.url ? 'hover:bg-muted' : '',
                        ]"
                        @click="markRead(group)"
                    >
                        <span class="flex items-start gap-2">
                            <span
                                :class="[
                                    'mt-1.5',
                                    'size-1.5',
                                    'shrink-0',
                                    'rounded-full',
                                    group.unread > 0
                                        ? 'bg-state-info'
                                        : 'bg-transparent',
                                ]"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block">{{ group.summary }}</span>
                                <span
                                    :class="[
                                        'block',
                                        'text-[11px]',
                                        'text-muted-foreground',
                                    ]"
                                >
                                    <template v-if="group.dealName">
                                        {{ group.dealName }} ·
                                    </template>
                                    <template
                                        v-if="showsTeams && group.teamName"
                                    >
                                        {{ group.teamName }} ·
                                    </template>
                                    {{
                                        group.occurredAt
                                            ? formatRelativeDate(
                                                  group.occurredAt,
                                              )
                                            : ''
                                    }}
                                </span>
                            </span>
                        </span>
                    </component>
                </li>
            </ul>

            <div class="border-t border-border px-3 py-2">
                <Link
                    href="/notifications"
                    class="text-13 text-muted-foreground hover:text-foreground"
                >
                    See all notifications
                </Link>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
