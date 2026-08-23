<script setup lang="ts">
/**
 * S82 — the teams list, and where a customer's life in the product begins.
 *
 * PRD §5.1 step 1 is *"Ian provisions a team and invites the owner"*, and both
 * halves happen together here: a team with nobody who can sign in is a team
 * that never starts, so provisioning takes the owner's address and sends the
 * invitation in the same action.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/formatters';
import type { Paginated } from '@/types';

const props = defineProps<{
    search: string;
    teams: Paginated<{
        id: string;
        name: string;
        slug: string;
        memberCount: number;
        suspendedAt: string | null;
        purgeAfter: string | null;
        createdAt: string | null;
    }>;
}>();

const search = ref(props.search);
const provisioning = ref(false);

const form = useForm({
    name: '',
    timezone: 'America/Denver',
    owner_email: '',
});

let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);

    debounce = setTimeout(() => {
        router.get(
            '/admin/teams',
            { search: value || undefined },
            { preserveState: true, replace: true, only: ['teams', 'search'] },
        );
    }, 250);
});

function submit(): void {
    form.post('/admin/teams', {
        onSuccess: () => {
            form.reset();
            provisioning.value = false;
        },
    });
}
</script>

<template>
    <Head title="Teams" />

    <div class="flex flex-col gap-4 p-6">
        <PageHeader title="Teams" :subtitle="`${teams.total} on the platform`">
            <template #actions>
                <AppButton @click="provisioning = !provisioning"
                    >Provision a team</AppButton
                >
            </template>
        </PageHeader>

        <Card v-if="provisioning" title="New team">
            <form
                class="flex flex-col gap-4 px-4 py-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="flex flex-col gap-1.5">
                        <Label for="team_name">Team name</Label>
                        <AppInput id="team_name" v-model="form.name" required />
                        <p
                            v-if="form.errors.name"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="team_timezone">Timezone</Label>
                        <AppInput
                            id="team_timezone"
                            v-model="form.timezone"
                            required
                        />
                        <p
                            v-if="form.errors.timezone"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.timezone }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="owner_email">Owner’s email</Label>
                        <AppInput
                            id="owner_email"
                            v-model="form.owner_email"
                            type="email"
                            required
                        />
                        <p
                            v-if="form.errors.owner_email"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.owner_email }}
                        </p>
                    </div>
                </div>
                <p class="text-[11px] text-muted-foreground">
                    The owner gets an invitation immediately. A team without one
                    cannot be administered at all.
                </p>
                <div class="flex justify-end gap-2">
                    <AppButton
                        variant="ghost"
                        type="button"
                        @click="provisioning = false"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :disabled="form.processing"
                        >Provision and invite</AppButton
                    >
                </div>
            </form>
        </Card>

        <AppInput
            v-model="search"
            size="filter"
            type="search"
            placeholder="Search by name or slug"
            aria-label="Search teams"
            class="w-full sm:w-72"
        />

        <Card>
            <EmptyState
                v-if="teams.data.length === 0"
                title="No teams match"
                :variant="search ? 'filtered' : 'empty'"
                description="Provision one, and its owner gets an invitation."
            />
            <ul v-else class="flex flex-col">
                <li
                    v-for="team in teams.data"
                    :key="team.id"
                    class="border-b last:border-b-0"
                >
                    <Link
                        :href="`/admin/teams/${team.id}`"
                        class="flex min-h-11 items-center gap-3 px-4 py-2.5 hover:bg-accent/60"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-13 font-medium">{{
                                team.name
                            }}</span>
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{ team.slug }}</span
                            >
                        </span>
                        <span class="tabular text-[11px] text-muted-foreground"
                            >{{ team.memberCount }} members</span
                        >
                        <StatusBadge
                            v-if="team.purgeAfter"
                            tone="danger"
                            :label="`Purges ${formatDate(team.purgeAfter)}`"
                            dotless
                        />
                        <StatusBadge
                            v-else-if="team.suspendedAt"
                            tone="warning"
                            label="Suspended"
                            dotless
                        />
                    </Link>
                </li>
            </ul>
        </Card>
    </div>
</template>
