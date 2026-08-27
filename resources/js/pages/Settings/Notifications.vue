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
import { Head, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { update as updateNotifications } from '@/routes/notification-preferences';

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
