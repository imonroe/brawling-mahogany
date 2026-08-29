<script setup lang="ts">
/**
 * S64 — expired, already used, or revoked (PRD §4.7 F7.1 · issue #110).
 *
 * ## Three states, three sentences
 *
 * Screen Inventory lists them separately because they are separate things to
 * the person reading: a link that ran out, a link that has already been
 * opened, and access somebody took away. Collapsing them into *"invalid"*
 * turns a two-second understanding into a phone call, which is the outcome
 * this whole surface exists to reduce.
 *
 * IA §9 still applies. *Expired* is a fact about a link and not an alarming
 * word about a deal; nothing here says *failed*, *error*, or *denied*.
 *
 * ## The escape hatch asks for nothing but an address
 *
 * #110: *"it must not require the client to know anything but their email
 * address."* No deal, no name, no code from the email they no longer have.
 *
 * And the answer is the same whether or not we know the address — the server
 * says so and this screen says so — because anything more specific would let
 * a stranger learn which addresses are clients of this team.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    reason: 'expired' | 'used' | 'revoked';
    requested: boolean;
}>();

const SENTENCES: Record<string, string> = {
    expired:
        'That link has run out. They only work for a short while, which keeps your information private.',
    used: 'That link has already been opened. Each one works once.',
    revoked: 'That link is no longer active.',
};

const sentence = computed(() => SENTENCES[props.reason] ?? SENTENCES.expired);

const form = useForm({ email: '' });

function submit(): void {
    form.post('/s/request', { preserveScroll: true });
}
</script>

<template>
    <Head title="Link no longer works" />

    <div class="client-surface flex min-h-svh flex-col bg-background">
        <main class="mx-auto w-full max-w-[480px] flex-1 p-5">
            <h1 class="mt-8 text-[24px] font-bold">
                This link no longer works
            </h1>

            <p class="mt-3 text-base text-muted-foreground">{{ sentence }}</p>

            <!--
                The same words whether or not the address is one we know. A
                distinct "we have sent you one" would confirm that somebody is
                a client of this team, to somebody who proved nothing.
            -->
            <p
                v-if="requested"
                class="mt-6 rounded-lg border bg-card p-4 text-base"
                role="status"
            >
                If that address is on a transaction with us, a new link is on
                its way. It can take a minute or two to arrive.
            </p>

            <form
                v-else
                class="mt-6 flex flex-col gap-3"
                @submit.prevent="submit"
            >
                <label class="text-base font-semibold" for="status_email"
                    >Send me a new link</label
                >
                <p class="text-base text-muted-foreground">
                    Enter the email address your agent has for you.
                </p>
                <input
                    id="status_email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="min-h-[52px] rounded-lg border bg-background px-4 text-base"
                    placeholder="you@example.com"
                />
                <p v-if="form.errors.email" class="text-base text-state-danger">
                    {{ form.errors.email }}
                </p>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="min-h-[52px] rounded-lg bg-brand px-4 text-base font-semibold text-brand-foreground"
                >
                    Send a new link
                </button>
            </form>
        </main>
    </div>
</template>
