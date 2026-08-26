<script setup lang="ts">
/**
 * F5.9's rails, where a person can reach them (PRD §4.5 · issue #96).
 *
 * The rails run in the worker; this is the screen that turns them on and off.
 * It exists because a kill switch with no hand on it is a column — the finding
 * `CLAUDE.md` records from S17, one feature over: *a row nothing can reach is
 * a rule nobody is following.*
 *
 * ## The stop switch is first and reads as what it is
 *
 * F5.9 describes it as *"one toggle"*, for *"when a team calls to say stop,
 * something is wrong"*. Somebody opening this screen in that state is not
 * reading — so the switch is above everything, tinted `danger` when it is on,
 * and the page says how many messages it is currently holding rather than
 * promising that it holds them.
 *
 * ## Sandbox is a separate idea from stopping
 *
 * Stopping means nothing goes. Sandbox means everything goes **to the team
 * owner** with a banner on it. Both are on this page and they are deliberately
 * not one control: a team switching sandbox off is going live, and a team
 * pulling the stop switch is putting out a fire.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { OctagonX, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import Heading from '@/components/app/Heading.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatCount, formatDateTime } from '@/lib/formatters';

const props = defineProps<{
    settings: {
        sendsDisabled: boolean;
        sendsDisabledReason: string | null;
        sendsDisabledAt: string | null;
        sandboxMode: boolean;
        hourlySendLimit: number;
        dailySendLimit: number;
        approvalRequiredUntil: string | null;
        approvalIsMandatory: boolean;
    };
    queued: number;
    sentInTheLastHour: number;
}>();

/*
 * The two limits are held as **strings**, and that is `AppInput`'s rule rather
 * than a slip: it is a text input, binds `:value`, and emits a string — its
 * docblock refuses `type="number"` for exactly that reason. So the field is
 * `inputmode="numeric"`, which gets a phone keyboard without lying about what
 * comes back, and the server's `integer` rule does the deciding. A number
 * typed here and a number typed in the database agree because only one of them
 * is trusted.
 */
const form = useForm({
    sends_disabled: props.settings.sendsDisabled,
    sends_disabled_reason: props.settings.sendsDisabledReason ?? '',
    sandbox_mode: props.settings.sandboxMode,
    hourly_send_limit: String(props.settings.hourlySendLimit),
    daily_send_limit: String(props.settings.dailySendLimit),
});

/**
 * What the switch would catch, as a sentence rather than a promise.
 *
 * F5.9 says it *"must catch everything already queued"*, and a number is the
 * difference between somebody believing that and hoping so.
 */
const holding = computed(() =>
    props.queued === 0
        ? 'Nothing is queued right now.'
        : `${formatCount(props.queued, 'message')} queued right now, and turning this on holds every one of them.`,
);

function submit(): void {
    form.patch('/settings/sending', { preserveScroll: true });
}
</script>

<template>
    <Head title="Sending" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Sending"
            description="What can leave the building, and what stops it."
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card title="Stop everything">
                <div class="flex flex-col gap-3 px-4 py-4">
                    <Alert v-if="settings.sendsDisabled" variant="destructive">
                        <OctagonX class="size-4" aria-hidden="true" />
                        <AlertDescription>
                            Sending is off
                            <template v-if="settings.sendsDisabledAt">
                                since
                                {{
                                    formatDateTime(settings.sendsDisabledAt)
                                }} </template
                            >. Nothing automated is reaching anybody.
                        </AlertDescription>
                    </Alert>

                    <label class="flex cursor-pointer items-start gap-2.5">
                        <Checkbox
                            :model-value="form.sends_disabled"
                            class="mt-0.5"
                            @update:model-value="
                                (value) =>
                                    (form.sends_disabled = value === true)
                            "
                        />
                        <span class="flex flex-col gap-0.5">
                            <span class="text-sm font-medium text-foreground">
                                Stop all automated sending
                            </span>
                            <span class="text-13 text-muted-foreground">
                                {{ holding }} They are held, not cancelled —
                                turning this off releases them.
                            </span>
                        </span>
                    </label>

                    <div
                        v-if="form.sends_disabled"
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="reason">Why (optional)</Label>
                        <AppInput
                            id="reason"
                            v-model="form.sends_disabled_reason"
                            size="default"
                            maxlength="500"
                            placeholder="The Vanterpool template is sending to the wrong person"
                        />
                        <p class="text-[11px] text-muted-foreground">
                            Shown against every message this holds, so whoever
                            looks at the queue next knows what happened.
                        </p>
                    </div>
                </div>
            </Card>

            <Card title="Sandbox">
                <div class="flex flex-col gap-3 px-4 py-4">
                    <label class="flex cursor-pointer items-start gap-2.5">
                        <Checkbox
                            :model-value="form.sandbox_mode"
                            class="mt-0.5"
                            @update:model-value="
                                (value) => (form.sandbox_mode = value === true)
                            "
                        />
                        <span class="flex flex-col gap-0.5">
                            <span class="text-sm font-medium text-foreground">
                                Send everything to the team owner instead
                            </span>
                            <span class="text-13 text-muted-foreground">
                                Messages still go out, and they go to your owner
                                with a banner saying so — never to a client. New
                                teams start here. Turn it off when you trust
                                what your templates say.
                            </span>
                        </span>
                    </label>
                </div>
            </Card>

            <Card title="How much can go out">
                <div class="grid gap-3 px-4 py-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="hourly">Messages an hour</Label>
                        <AppInput
                            id="hourly"
                            v-model="form.hourly_send_limit"
                            size="default"
                            inputmode="numeric"
                        />
                        <p
                            v-if="form.errors.hourly_send_limit"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.hourly_send_limit }}
                        </p>
                        <p v-else class="text-[11px] text-muted-foreground">
                            {{ sentInTheLastHour }} sent in the last hour.
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="daily">Messages a day</Label>
                        <AppInput
                            id="daily"
                            v-model="form.daily_send_limit"
                            size="default"
                            inputmode="numeric"
                        />
                        <p
                            v-if="form.errors.daily_send_limit"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.daily_send_limit }}
                        </p>
                    </div>

                    <p class="text-13 text-muted-foreground sm:col-span-2">
                        Reaching a limit <strong>pauses</strong> sending rather
                        than cancelling it. If a template ever goes wrong in a
                        loop, this is what stops it at the limit instead of at
                        six thousand.
                    </p>
                </div>
            </Card>

            <Card
                v-if="settings.approvalIsMandatory"
                title="Review before send"
            >
                <div class="flex flex-col gap-2 px-4 py-4">
                    <p class="flex items-start gap-2 text-13 text-foreground">
                        <ShieldCheck
                            class="mt-0.5 size-4 shrink-0 text-state-info"
                            aria-hidden="true"
                        />
                        <span>
                            Every outbound email waits for somebody to read it
                            until
                            <strong v-if="settings.approvalRequiredUntil">{{
                                formatDateTime(settings.approvalRequiredUntil)
                            }}</strong
                            >, whatever each automation is set to. This is the
                            safety net for the period when your templates are
                            least tested.
                        </span>
                    </p>
                </div>
            </Card>

            <div class="flex items-center gap-3">
                <AppButton type="submit" :disabled="form.processing">
                    Save
                </AppButton>
                <span
                    v-if="form.recentlySuccessful"
                    class="text-13 text-muted-foreground"
                >
                    Saved.
                </span>
            </div>
        </form>
    </div>
</template>
