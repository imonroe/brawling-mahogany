<script setup lang="ts">
/**
 * S84 — start a support session.
 *
 * Three controls, all required, all enforced on the server as well as here:
 * who, **why in a sentence**, and for how long. The reason is not a dropdown
 * — it is stored verbatim on the audit entry, and a dropdown would let
 * somebody impersonate a customer without ever saying anything.
 */
import { Head, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    team: { id: string; name: string };
    people: { personId: string; name: string; email: string }[];
    maxMinutes: number;
}>();

const form = useForm({
    person_id: props.people[0]?.personId ?? '',
    reason: '',
    minutes: 30,
});

function submit(): void {
    form.post(`/admin/teams/${props.team.id}/impersonate`);
}
</script>

<template>
    <Head :title="`Impersonate in ${team.name}`" />

    <div class="flex max-w-2xl flex-col gap-4 p-6">
        <PageHeader
            :title="`Act as somebody in ${team.name}`"
            subtitle="For support, and only for support"
        />

        <Alert>
            <AlertTitle>This is recorded</AlertTitle>
            <AlertDescription>
                Starting and ending the session are both written to the
                permanent audit log with your name, the time, and the reason you
                type below. The customer’s app shows a banner throughout. You
                get their permissions, not yours.
            </AlertDescription>
        </Alert>

        <Card title="Session">
            <form
                class="flex flex-col gap-4 px-4 py-4"
                @submit.prevent="submit"
            >
                <div class="flex flex-col gap-1.5">
                    <Label for="person_id">Act as</Label>
                    <select
                        id="person_id"
                        v-model="form.person_id"
                        required
                        class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                    >
                        <option
                            v-for="person in people"
                            :key="person.personId"
                            :value="person.personId"
                        >
                            {{ person.name }} — {{ person.email }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="reason">Why</Label>
                    <textarea
                        id="reason"
                        v-model="form.reason"
                        rows="3"
                        required
                        class="rounded-md border bg-background p-[11px] text-base md:text-sm"
                        placeholder="Emily reported that advancing the Bosart listing fails at the inspection gate."
                    ></textarea>
                    <p class="text-[11px] text-muted-foreground">
                        This is written to the permanent audit log with your
                        name and the time. It cannot be edited or deleted.
                    </p>
                    <p
                        v-if="form.errors.reason"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.reason }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="minutes">For how long</Label>
                    <select
                        id="minutes"
                        v-model.number="form.minutes"
                        class="h-11 w-40 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                    >
                        <option :value="15">15 minutes</option>
                        <option :value="30">30 minutes</option>
                        <option :value="maxMinutes">
                            {{ maxMinutes }} minutes
                        </option>
                    </select>
                    <p class="text-[11px] text-muted-foreground">
                        The session ends by itself when the time is up.
                    </p>
                </div>

                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" :href="`/admin/teams/${team.id}`"
                        >Cancel</AppButton
                    >
                    <AppButton
                        type="submit"
                        variant="warning"
                        :disabled="form.processing"
                        >Start the session</AppButton
                    >
                </div>
            </form>
        </Card>
    </div>
</template>
