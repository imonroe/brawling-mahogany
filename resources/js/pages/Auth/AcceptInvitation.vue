<script setup lang="ts">
/**
 * S04 — accept an invitation.
 *
 * Four states, and each gets its own words rather than one generic failure.
 * Somebody clicking a link from a fortnight-old email needs to know *which*
 * thing went wrong and what to do next — "expired" and "already used" have
 * different answers.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import TextLink from '@/components/app/TextLink.vue';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    token: string;
    state: 'pending' | 'expired' | 'accepted' | 'revoked';
    email: string;
    firstName: string | null;
    lastName: string | null;
    teamName: string;
    inviterName: string | null;
    passwordRules: string;
}>();

const form = useForm({
    first_name: props.firstName ?? '',
    last_name: props.lastName ?? '',
    password: '',
    password_confirmation: '',
});

const heading = computed(() => {
    switch (props.state) {
        case 'expired':
            return 'This invitation has expired';
        case 'accepted':
            return 'This invitation has already been used';
        case 'revoked':
            return 'This invitation was withdrawn';
        default:
            return `Join ${props.teamName}`;
    }
});

const explanation = computed(() => {
    switch (props.state) {
        case 'expired':
            return 'Invitations last two weeks. Ask whoever invited you to send a new one — it’ll come to the same address.';
        case 'accepted':
            return 'You already have an account for this team. Sign in and you’re there.';
        case 'revoked':
            return 'Whoever invited you cancelled it. If that’s a surprise, ask them to invite you again.';
        default:
            return props.inviterName
                ? `${props.inviterName} invited you. Pick a password and you’re in.`
                : 'Pick a password and you’re in.';
    }
});

function submit(): void {
    form.post(`/invitations/${props.token}`, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2 text-center">
            <h1 class="text-xl font-semibold text-foreground">{{ heading }}</h1>
            <p class="text-13 text-muted-foreground">{{ explanation }}</p>
        </div>

        <form
            v-if="state === 'pending'"
            class="flex flex-col gap-4"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-1.5">
                <Label for="email">Email</Label>
                <AppInput
                    id="email"
                    :model-value="email"
                    type="email"
                    readonly
                    disabled
                />
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <Label for="first_name">First name</Label>
                    <AppInput
                        id="first_name"
                        v-model="form.first_name"
                        required
                        autofocus
                    />
                    <p
                        v-if="form.errors.first_name"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.first_name }}
                    </p>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="last_name">Last name</Label>
                    <AppInput id="last_name" v-model="form.last_name" />
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <Label for="password">Password</Label>
                <AppInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                />
                <p class="text-[11px] text-muted-foreground">
                    {{ passwordRules }}
                </p>
                <p
                    v-if="form.errors.password"
                    class="text-[11px] text-state-danger"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <div class="flex flex-col gap-1.5">
                <Label for="password_confirmation">Confirm password</Label>
                <AppInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                />
            </div>

            <AppButton type="submit" :disabled="form.processing"
                >Join {{ teamName }}</AppButton
            >
        </form>

        <div v-else class="flex justify-center">
            <TextLink href="/login">Go to sign in</TextLink>
        </div>
    </div>
</template>
