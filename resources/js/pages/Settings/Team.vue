<script setup lang="ts">
/**
 * S72 — team profile and branding.
 *
 * The **live preview** is the point of the screen. What is set here is what a
 * client sees on the status page (Slice 4) and in every automated email
 * (Slice 3), and PRD §3.1 says Emily *"will not read documentation"* and will
 * not imagine what a hex value looks like on a phone. So show her.
 *
 * On Design System §15.6's open question — warn about a low-contrast accent,
 * or silently adjust it — this **warns**, and shows the failing combination.
 * The client status page is held to WCAG 2.1 AA (PRD §9), and a silently
 * altered colour is a support ticket that arrives later and angrier.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import Heading from '@/components/app/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    team: {
        name: string;
        slug: string;
        timezone: string;
        hasLogo: boolean;
        brandAccentColor: string | null;
        sendingIdentityName: string | null;
        sendingIdentityEmail: string | null;
        signatureBlock: string | null;
    };
    timezones: string[];
    accentWarning: string | null;
}>();

const form = useForm({
    name: props.team.name,
    timezone: props.team.timezone,
    brand_accent_color: props.team.brandAccentColor ?? '',
    sending_identity_name: props.team.sendingIdentityName ?? '',
    sending_identity_email: props.team.sendingIdentityEmail ?? '',
    signature_block: props.team.signatureBlock ?? '',
});

/*
 * The preview follows the field as it is typed, not as it was saved — the
 * whole point is seeing the colour before committing to it. The saved
 * warning still shows until the next save, because it is the server's
 * contrast maths and not a second implementation in the browser.
 */
const previewAccent = computed(() => form.brand_accent_color || 'transparent');

function submit(): void {
    form.patch('/settings/team', { preserveScroll: true });
}

/*
 * The logo is its own request, not a field on the form above.
 *
 * A file needs a multipart body the `PATCH` cannot carry, and it is replaced
 * rather than edited — so it posts on choose, with no separate Save. A `<form>`
 * here would have to nest inside the one wrapping the whole screen, which is
 * invalid HTML and behaves differently in every browser.
 */
const logoForm = useForm<{ logo: File | null }>({ logo: null });

/*
 * The response carries `Cache-Control: private, max-age=300`, which is right
 * for a value that changes twice a year and wrong for the ten seconds after
 * somebody changes it. Bumping this on success is what makes the preview show
 * the logo they just uploaded rather than the one they replaced.
 */
const logoVersion = ref(0);

const logoSrc = computed(() => `/settings/team/logo?v=${logoVersion.value}`);

function chooseLogo(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    if (!file) {
        return;
    }

    logoForm.logo = file;
    logoForm.post('/settings/team/logo', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            logoVersion.value += 1;
        },
        onFinish: () => {
            /*
             * Cleared either way. A file input that still holds the refused
             * file looks like it worked, and choosing the *same* file again
             * fires no `change` event — so a person correcting a 2MB export
             * would have to pick a different file to retry.
             */
            input.value = '';
            logoForm.reset('logo');
        },
    });
}

function removeLogo(): void {
    router.delete('/settings/team/logo', { preserveScroll: true });
}
</script>

<template>
    <Head title="Team" />

    <div class="flex flex-col gap-4">
        <Heading
            title="Team"
            description="Your team’s name, timezone, and the branding your clients see."
        />

        <Alert v-if="accentWarning" variant="destructive">
            <AlertTitle>That accent may be hard to read</AlertTitle>
            <AlertDescription>{{ accentWarning }}</AlertDescription>
        </Alert>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <Card title="Profile">
                <div class="flex flex-col gap-4 px-4 py-4">
                    <div class="flex flex-col gap-1.5">
                        <Label for="name">Team name</Label>
                        <AppInput id="name" v-model="form.name" required />
                        <p
                            v-if="form.errors.name"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="timezone">Timezone</Label>
                        <select
                            id="timezone"
                            v-model="form.timezone"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option
                                v-for="zone in timezones"
                                :key="zone"
                                :value="zone"
                            >
                                {{ zone }}
                            </option>
                        </select>
                        <p class="text-[11px] text-muted-foreground">
                            Every date and time in the product is shown in this
                            zone, for everybody on the team.
                        </p>
                    </div>
                </div>
            </Card>

            <Card title="Branding">
                <div class="flex flex-col gap-4 px-4 py-4">
                    <div class="flex flex-col gap-1.5">
                        <Label for="logo">Logo</Label>
                        <div class="flex flex-wrap items-center gap-3">
                            <!--
                                The plate is Design System §2.6's `--logo-plate`
                                argument applied to somebody else's mark: a
                                raster logo cannot answer the theme, so it is
                                shown on the ground it was drawn for rather
                                than on a card that inverts under it. The
                                emails do the same thing.
                            -->
                            <img
                                v-if="team.hasLogo"
                                :src="logoSrc"
                                :alt="`${team.name} logo`"
                                class="h-12 max-w-40 rounded-md bg-logo-plate object-contain p-1.5"
                            />
                            <span v-else class="text-13 text-muted-foreground"
                                >No logo yet — your team’s name is used
                                instead.</span
                            >
                            <input
                                id="logo"
                                type="file"
                                accept="image/png,image/jpeg,image/gif"
                                class="text-13 file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-13"
                                :disabled="logoForm.processing"
                                @change="chooseLogo"
                            />
                            <AppButton
                                v-if="team.hasLogo"
                                type="button"
                                variant="ghost"
                                @click="removeLogo"
                                >Remove</AppButton
                            >
                        </div>
                        <p
                            v-if="logoForm.errors.logo"
                            class="text-[11px] text-state-danger"
                        >
                            {{ logoForm.errors.logo }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            A PNG, JPEG or GIF up to 1MB, about 400 pixels wide.
                            It goes at the top of every email your clients get,
                            on a white panel so it stays readable when their
                            phone is in dark mode.
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="brand_accent_color">Accent colour</Label>
                        <div class="flex items-center gap-2">
                            <AppInput
                                id="brand_accent_color"
                                v-model="form.brand_accent_color"
                                placeholder="#RRGGBB"
                                class="w-40"
                            />
                            <span
                                class="size-10 shrink-0 rounded-md border"
                                :style="{ backgroundColor: previewAccent }"
                                aria-hidden="true"
                            ></span>
                        </div>
                        <p class="text-[11px] text-muted-foreground">
                            Used on your clients’ status page and in the emails
                            they get. The app you work in keeps its own colours,
                            so nothing moves under you.
                        </p>
                    </div>

                    <div
                        class="flex flex-col gap-2 rounded-lg border p-4"
                        aria-label="Preview of your client’s status page"
                    >
                        <p class="text-[11px] text-muted-foreground">
                            What your client sees
                        </p>
                        <!--
                            The background is the owner's own colour, which
                            arrives as data rather than as a value written into
                            this file. The foreground is the brand token
                            (Design System §2.7) — a literal white here would
                            be a second opinion about what sits on a brand.
                        -->
                        <div
                            class="flex items-center gap-3 rounded-md p-3 text-brand-foreground"
                            :style="{ backgroundColor: previewAccent }"
                        >
                            <span class="text-sm font-semibold">{{
                                form.name || 'Your team'
                            }}</span>
                            <span class="text-13 opacity-90"
                                >Your sale · 123 Main St</span
                            >
                        </div>
                    </div>
                </div>
            </Card>

            <Card title="Sending identity">
                <div class="flex flex-col gap-4 px-4 py-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="sending_identity_name">From name</Label>
                            <AppInput
                                id="sending_identity_name"
                                v-model="form.sending_identity_name"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sending_identity_email"
                                >Reply-to address</Label
                            >
                            <AppInput
                                id="sending_identity_email"
                                v-model="form.sending_identity_email"
                                type="email"
                            />
                            <p
                                v-if="form.errors.sending_identity_email"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.sending_identity_email }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="signature_block">Signature block</Label>
                        <textarea
                            id="signature_block"
                            v-model="form.signature_block"
                            rows="4"
                            class="rounded-md border bg-background p-[11px] text-base md:text-sm"
                        ></textarea>
                        <p class="text-[11px] text-muted-foreground">
                            Goes at the foot of the emails your clients get.
                        </p>
                    </div>
                </div>
            </Card>

            <div class="flex justify-end">
                <AppButton type="submit" :disabled="form.processing"
                    >Save changes</AppButton
                >
            </div>
        </form>
    </div>
</template>
