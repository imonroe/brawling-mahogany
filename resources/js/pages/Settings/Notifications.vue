<script setup lang="ts">
/**
 * S78 — notification preferences (PRD §4.12 F12.4 · issue #101).
 *
 * F12.4 in one line: *"Channel and quiet hours per event type. **Nobody wants
 * a 6am push.**"*
 *
 * ## "In the app" is shown and cannot be switched off
 *
 * Rendered as a checked, disabled box rather than left out, because a person
 * reading a row with only "Email" in it cannot tell whether the panel is
 * always on or simply not offered. The reason is on the screen: the panel is
 * the record, and a notification nobody can find later is ADR 0003's failure.
 *
 * ## Quiet hours are one window, in the team's time
 *
 * Not per type — a person has one evening. What is per type is whether
 * something may cross the window at all, and that is a product decision rather
 * than a preference (`NotificationType::bypassesQuietHours()`).
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import InstallPrompt from '@/components/app/InstallPrompt.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { usePushSubscription } from '@/composables/usePushSubscription';
import { update as updateNotifications } from '@/routes/notification-preferences';
import * as pushSubscriptions from '@/routes/push-subscriptions';

type Type = {
    value: string;
    label: string;
    description: string;
    channels: string[];
};

const props = defineProps<{
    teamName: string | null;
    timezone: string | null;
    types: Type[];
    channels: { value: string; label: string }[];
    comingSoon: string[];
    quietHours: { start: string | null; end: string | null };
    push: {
        configured: boolean;
        publicKey: string | null;
        devices: {
            name: string;
            lastSeenAt: string | null;
            fingerprint: string;
        }[];
    };
}>();

const form = useForm({
    channels: Object.fromEntries(
        props.types.map((type) => [
            type.value,
            type.channels.filter((channel) => channel !== 'in_app'),
        ]),
    ) as Record<string, string[]>,
    quiet_hours_start: props.quietHours.start ?? '',
    quiet_hours_end: props.quietHours.end ?? '',
});

function toggle(type: string, channel: string, on: boolean): void {
    const current = new Set(form.channels[type] ?? []);

    if (on) {
        current.add(channel);
    } else {
        current.delete(channel);
    }

    form.channels[type] = [...current];
}

/**
 * S55 — the permission flow (#103).
 *
 * The pre-prompt is the card below; this only runs from a press on it, which
 * is the whole rule: a browser permission prompt fired without explanation is
 * a permission permanently denied, and there is no second chance.
 */
const subscription = usePushSubscription(props.push.publicKey);

onMounted(() => {
    void subscription.refresh();
});

/** The permission as a plain string, so the template can branch on it. */
const pushState = computed(() => subscription.permission.value);

/** Is one of the registered devices this browser? */
const thisDeviceRegistered = computed(
    () =>
        subscription.fingerprint.value !== null &&
        props.push.devices.some(
            (device) => device.fingerprint === subscription.fingerprint.value,
        ),
);

async function enablePush(): Promise<void> {
    const result = await subscription.subscribe();

    if (!result.ok || !result.subscription) {
        return;
    }

    router.post(pushSubscriptions.store.url(), result.subscription, {
        preserveScroll: true,
    });
}

async function disablePush(): Promise<void> {
    const endpoint = await subscription.unsubscribe();

    router.delete(pushSubscriptions.destroy.url(), {
        data: endpoint ? { endpoint } : {},
        preserveScroll: true,
    });
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        // An empty string is not a time; the column is nullable and the
        // validator refuses one half of a window, so both go together.
        quiet_hours_start: data.quiet_hours_start || null,
        quiet_hours_end: data.quiet_hours_end || null,
    })).patch(updateNotifications.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <PageHeader
        title="Notifications"
        :description="
            teamName
                ? `How you are told about work in ${teamName}. Each team you are in has its own settings.`
                : 'How you are told about work.'
        "
    />

    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <Card title="What you hear about">
            <ul class="divide-y divide-border">
                <li
                    v-for="type in types"
                    :key="type.value"
                    class="flex flex-col gap-2 px-4 py-4"
                >
                    <div>
                        <p class="text-13 font-medium">{{ type.label }}</p>
                        <p class="text-13 text-muted-foreground">
                            {{ type.description }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <!--
                            Shown, checked and disabled. Leaving it out
                            would make a row reading only "Email" ambiguous
                            about whether the panel is always on.
                        -->
                        <Label
                            class="flex items-center gap-2 text-13 text-muted-foreground"
                        >
                            <Checkbox :model-value="true" disabled />
                            In the app
                        </Label>

                        <Label
                            v-for="channel in channels"
                            :key="channel.value"
                            class="flex items-center gap-2 text-13"
                        >
                            <Checkbox
                                :model-value="
                                    (form.channels[type.value] ?? []).includes(
                                        channel.value,
                                    )
                                "
                                @update:model-value="
                                    (on) =>
                                        toggle(
                                            type.value,
                                            channel.value,
                                            on === true,
                                        )
                                "
                            />
                            {{ channel.label }}
                        </Label>
                    </div>
                </li>
            </ul>

            <p
                v-if="comingSoon.length > 0"
                class="border-t border-border px-4 py-3 text-13 text-muted-foreground"
            >
                <span v-for="line in comingSoon" :key="line">{{ line }}</span>
            </p>
        </Card>

        <!--
            S55 — push notifications (#103).

            A card rather than a fourth checkbox in the grid above, because
            push is not a preference in the same sense: the other two are a
            choice, and this one is a **permission** that has to be asked for
            once, from a click, and cannot be asked for again if refused. It
            is also per **device** rather than per person, which a checkbox
            column has nowhere to say.
        -->
        <Card title="Push notifications">
            <div class="flex flex-col gap-3 px-4 py-4">
                <!-- Not configured here: say so plainly rather than offering
                     a control that would store a preference nothing can act
                     on. -->
                <p
                    v-if="!props.push.configured"
                    class="text-13 text-muted-foreground"
                >
                    Push notifications are not set up for this installation yet,
                    so there is nothing to switch on. Email and the in-app list
                    are unaffected.
                </p>

                <template v-else-if="pushState === 'unsupported'">
                    <p class="text-13 text-muted-foreground">
                        This browser cannot receive push notifications. On an
                        iPhone or iPad they work once Goldieflow has been added
                        to the home screen.
                    </p>
                    <InstallPrompt />
                </template>

                <!-- Denied. There is no second chance from here, so the copy
                     is about the browser's own settings rather than a button
                     that would do nothing. -->
                <p
                    v-else-if="pushState === 'denied'"
                    class="text-13 text-muted-foreground"
                >
                    You have blocked notifications for Goldieflow in this
                    browser, so we cannot ask again from here. Allow them in
                    your browser's site settings and this will switch on.
                </p>

                <template v-else>
                    <!-- The pre-prompt. #103: "ask in the app, explain what
                         will be sent, and only then trigger the browser
                         prompt." -->
                    <p class="text-13 text-muted-foreground">
                        A short alert on this device when something needs you —
                        a task assigned to you, a deadline tomorrow, an
                        automation that failed. Never anything about a client: a
                        notification can be read off a locked screen, so it
                        names the property and nothing else.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <AppButton
                            v-if="!thisDeviceRegistered"
                            variant="secondary"
                            :disabled="subscription.busy.value"
                            @click="enablePush"
                        >
                            Turn on for this device
                        </AppButton>
                        <AppButton
                            v-else
                            variant="secondary"
                            :disabled="subscription.busy.value"
                            @click="disablePush"
                        >
                            Turn off for this device
                        </AppButton>
                    </div>

                    <!-- The state everybody forgets: yes in the browser, no
                         in iOS Settings, and nothing arrives with every layer
                         reporting success. It cannot be detected directly, so
                         the possibility is named rather than hidden. -->
                    <p
                        v-if="subscription.mayBeBlockedBySystem.value"
                        class="text-state-warning-foreground text-13"
                    >
                        This browser says notifications are allowed, but no
                        subscription is registered for it. On iOS, check that
                        notifications are enabled for Goldieflow in Settings →
                        Notifications as well.
                    </p>
                </template>

                <!-- Every device, so somebody can switch off one they no
                     longer have. The endpoint is never shown: it is the whole
                     authorisation to push to a phone. -->
                <div
                    v-if="props.push.devices.length > 0"
                    class="mt-1 flex flex-col gap-1 border-t border-border pt-3"
                >
                    <p class="text-13 font-medium">Devices receiving these</p>
                    <p
                        v-for="device in props.push.devices"
                        :key="device.fingerprint"
                        class="text-13 text-muted-foreground"
                    >
                        {{ device.name
                        }}<template
                            v-if="
                                device.fingerprint ===
                                subscription.fingerprint.value
                            "
                        >
                            — this one</template
                        >
                    </p>
                </div>
            </div>
        </Card>

        <Card title="Quiet hours">
            <div class="flex flex-col gap-3 px-4 py-4">
                <p class="text-13 text-muted-foreground">
                    Emails and push notifications are held during these hours
                    and sent when they end — never dropped. The app itself
                    always keeps the notification, so nothing is lost either
                    way. Times are
                    <template v-if="timezone">
                        in your team's timezone ({{ timezone }}).
                    </template>
                    <template v-else>in your team's timezone.</template>
                </p>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex flex-col gap-1">
                        <Label for="quiet-start" class="text-13">From</Label>
                        <input
                            id="quiet-start"
                            v-model="form.quiet_hours_start"
                            type="time"
                            class="h-9 rounded-md border border-input bg-background px-3 text-13"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <Label for="quiet-end" class="text-13">Until</Label>
                        <input
                            id="quiet-end"
                            v-model="form.quiet_hours_end"
                            type="time"
                            class="h-9 rounded-md border border-input bg-background px-3 text-13"
                        />
                    </div>
                    <AppButton
                        v-if="form.quiet_hours_start || form.quiet_hours_end"
                        variant="ghost"
                        type="button"
                        @click="
                            form.quiet_hours_start = '';
                            form.quiet_hours_end = '';
                        "
                    >
                        Clear
                    </AppButton>
                </div>

                <p
                    v-if="
                        form.errors.quiet_hours_start ||
                        form.errors.quiet_hours_end
                    "
                    class="text-13 text-state-danger"
                >
                    {{
                        form.errors.quiet_hours_start ??
                        form.errors.quiet_hours_end
                    }}
                </p>
            </div>
        </Card>

        <div>
            <AppButton type="submit" :disabled="form.processing">
                Save
            </AppButton>
        </div>
    </form>
</template>
