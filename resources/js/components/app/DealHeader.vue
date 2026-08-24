<script setup lang="ts">
/**
 * Design System §8.4 — the 120px header shared by all eight deal tabs.
 *
 * Two bands: a title row (`py-4 px-6 gap-3`) carrying the deal name, its state
 * badge and a meta row of `[icon 13][text 13 muted]` pairs; and the tab row
 * (`px-6 gap-[22px]`), whose container carries the bottom border.
 *
 * ## Why this is a component rather than markup on the overview
 *
 * Before this existed there was no way to move between deal tabs at all —
 * `Deals/People.vue` and `Deals/Properties.vue` each rendered their own
 * `Heading` and no tabs, so the People tab was reachable only from a link on
 * the Properties tab and vice versa. §8.4's first line is *"Shared by all eight
 * deal tabs (S15–S22)"*, and a header shared by one screen is not the thing
 * the spec describes. `DealLayout` puts it above every one of them.
 *
 * ## The tabs that have no screen yet
 *
 * Timeline (S16), Tasks (S17), Dates (S18) and Documents (S21) are later
 * slices. They are rendered, and disabled, with a title naming the slice —
 * `Tab` already draws a hrefless tab as a `<button>`. Visible, so the shape of
 * a deal is honest; inert, so nothing offers a route that 404s. §7.3's "hide
 * rather than disable" rule is about **permission**: a section somebody may
 * not use should not advertise itself. A section nobody can use yet is a
 * different case and reads as one.
 *
 * **Offers is absent entirely**, which is IA §5.2 read literally: *"hidden
 * when empty and the deal type has no offers."* There is no `offers` table in
 * Slice 2, so every deal is empty of them, and a disabled Offers tab would be
 * asserting that this deal type has some.
 *
 * ## Owner is absent too, and that is a data gap rather than a choice
 *
 * §8.4's meta row lists client, deal type, location and **owner**. `deals` has
 * no owning-agent column — see the migration — so there is nothing to render.
 * Raised on #75 rather than faked with the person who happens to be looking.
 */
import { CircleUser, MapPin, Tag } from '@lucide/vue';
import { computed } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatLocality } from '@/lib/formatters';
import type { AddressParts } from '@/lib/formatters';
import AppButton from './AppButton.vue';
import StatusBadge from './StatusBadge.vue';
import Tab from './Tab.vue';
import TabBar from './TabBar.vue';

export type DealHeaderProps = {
    id: string;
    name: string;
    state: string;
    dealTypeName: string;
    sideLabel: string;
    clientName: string | null;
    location: AddressParts | null;
    counts: { people: number; properties: number };
    /** Null when no single workflow is unambiguously the one to advance. */
    advance: { workflowId: string; stageId: string } | null;
};

const props = defineProps<{
    deal: DealHeaderProps;
    /** The tab segment this page is, or `null` for the overview. */
    active: string | null;
}>();

const emit = defineEmits<{ advance: [workflowId: string, stageId: string] }>();

const { can } = usePermissions();

type TabSpec = {
    label: string;
    segment: string | null;
    count: number | null;
    /** The slice that builds it, or null once it is built. */
    arrivesWith: string | null;
};

/*
 * IA §5.2's order, which is also §8.4's. Counts appear on Tasks, Dates,
 * People, Documents and Offers — never on Overview or Timeline, which are not
 * lists of anything. A tab whose slice has not landed has no count to show
 * either, so it carries null rather than a zero that would read as a fact.
 */
const tabs = computed<TabSpec[]>(() => [
    { label: 'Overview', segment: null, count: null, arrivesWith: null },
    { label: 'Timeline', segment: 'timeline', count: null, arrivesWith: null },
    { label: 'Tasks', segment: 'tasks', count: null, arrivesWith: 'S17' },
    { label: 'Dates', segment: 'dates', count: null, arrivesWith: 'S18' },
    {
        label: 'People',
        segment: 'people',
        count: props.deal.counts.people,
        arrivesWith: null,
    },
    {
        label: 'Properties',
        segment: 'properties',
        count: props.deal.counts.properties,
        arrivesWith: null,
    },
    {
        label: 'Documents',
        segment: 'documents',
        count: null,
        arrivesWith: 'S21',
    },
]);

const locality = computed(() =>
    props.deal.location ? formatLocality(props.deal.location) : '',
);

/**
 * Narrowed here rather than in the template: `deal.advance` is nullable, and a
 * `v-if` on it does not narrow the handler `vue-tsc` type-checks.
 */
function advanceFromHeader(): void {
    if (props.deal.advance === null) {
        return;
    }

    emit('advance', props.deal.advance.workflowId, props.deal.advance.stageId);
}

function hrefFor(tab: TabSpec): string | undefined {
    if (tab.arrivesWith !== null) {
        return undefined;
    }

    return tab.segment === null
        ? `/deals/${props.deal.id}`
        : `/deals/${props.deal.id}/${tab.segment}`;
}
</script>

<template>
    <header class="flex flex-col" data-slot="deal-header">
        <div class="flex flex-wrap items-start justify-between gap-3 px-6 py-4">
            <div class="flex min-w-0 flex-col gap-1.5">
                <div class="flex flex-wrap items-center gap-3">
                    <h1
                        class="truncate text-2xl font-semibold text-foreground"
                        data-slot="deal-name"
                    >
                        {{ deal.name }}
                    </h1>
                    <StatusBadge domain="deal" :state="deal.state" />
                </div>

                <div
                    class="flex flex-wrap items-center gap-3.5 text-13 text-muted-foreground"
                >
                    <span
                        v-if="deal.clientName"
                        class="flex items-center gap-1"
                    >
                        <CircleUser class="size-[13px]" aria-hidden="true" />
                        {{ deal.clientName }}
                    </span>
                    <span class="flex items-center gap-1">
                        <Tag class="size-[13px]" aria-hidden="true" />
                        {{ deal.dealTypeName }}
                    </span>
                    <span v-if="locality" class="flex items-center gap-1">
                        <MapPin class="size-[13px]" aria-hidden="true" />
                        {{ locality }}
                    </span>
                </div>
            </div>

            <!--
                IA §7: **Advance** is the only verb for moving a workflow
                forward — never Progress, Move, Next or Complete.

                Offered only when exactly one workflow is running with a stage
                to leave, which the server decides; with two, the Overview's
                per-workflow cards each carry their own. Hidden rather than
                disabled when the person lacks `workflow.advance`, per §7.3.
            -->
            <div v-if="deal.advance && can('workflow.advance')">
                <AppButton @click="advanceFromHeader">Advance stage</AppButton>
            </div>
        </div>

        <TabBar class="px-6" label="Deal sections">
            <Tab
                v-for="tab in tabs"
                :key="tab.label"
                :label="tab.label"
                :href="hrefFor(tab)"
                :count="tab.count"
                :active="active === tab.segment"
                :disabled="tab.arrivesWith !== null || undefined"
                :title="
                    tab.arrivesWith
                        ? `${tab.label} arrives with ${tab.arrivesWith}.`
                        : undefined
                "
            />
        </TabBar>
    </header>
</template>
