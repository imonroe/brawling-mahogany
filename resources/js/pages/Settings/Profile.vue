<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
/* @chisel-email-verification */
import { Link } from '@inertiajs/vue3';
/* @end-chisel-email-verification */
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/app/DeleteUser.vue';
import Heading from '@/components/app/Heading.vue';
import InputError from '@/components/forms/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
/* @chisel-email-verification */
import { send } from '@/routes/verification';
/* @end-chisel-email-verification */

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

/**
 * The name is what this team calls you, and the address is your account
 * (issue 140). Somebody in two teams edits the name once per team, which is why
 * the heading names the team.
 */
const props = defineProps<{
    teamName?: string | null;
    firstName?: string | null;
    lastName?: string | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const description = computed(() =>
    props.teamName
        ? `Update your email address, and the name ${props.teamName} sees`
        : 'Update your email address',
);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading variant="small" title="Profile" :description="description" />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <!--
                Two fields, not one: IA §10 displays a person as First Last and
                sorts them by last, which a single `name` column cannot do.
            -->
            <div class="grid gap-2 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="first_name">First name</Label>
                    <Input
                        id="first_name"
                        class="mt-1 block w-full"
                        name="first_name"
                        :default-value="props.firstName ?? user.first_name"
                        required
                        autocomplete="given-name"
                        placeholder="First name"
                    />
                    <InputError class="mt-2" :message="errors.first_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="last_name">Last name</Label>
                    <Input
                        id="last_name"
                        class="mt-1 block w-full"
                        name="last_name"
                        :default-value="props.lastName ?? ''"
                        autocomplete="family-name"
                        placeholder="Last name"
                    />
                    <InputError class="mt-2" :message="errors.last_name" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <!-- @chisel-email-verification -->
            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-border underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-state-success"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>
            <!-- @end-chisel-email-verification -->

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
