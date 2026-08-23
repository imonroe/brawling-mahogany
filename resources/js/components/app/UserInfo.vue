<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { formatPersonName, personInitials } from '@/lib/formatters';
import type { Person } from '@/types';

type Props = {
    user: Person;
    showEmail?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

/*
 * Nothing formats a name itself (IA §10, Frontend conventions §3). Both of
 * these come from lib/formatters so the sidebar, the people index, and a
 * client email agree about what somebody is called.
 */
const parts = computed(() => ({
    firstName: props.user.first_name,
    lastName: props.user.last_name,
}));

const name = computed(() => formatPersonName(parts.value));

/*
 * No avatar branch, deliberately.
 *
 * There was one, reading `user.avatar` — a field the server has never sent.
 * It was always falsy, so the fallback initials were always what rendered,
 * and the branch read as an uploaded-photo feature that does not exist.
 * Avatars are not in v1; when they arrive they arrive with a column, a prop,
 * and an upload screen.
 */
</script>

<template>
    <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarFallback class="rounded-lg text-foreground">
            {{ personInitials(parts) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ name }}</span>
        <span v-if="showEmail" class="truncate text-xs text-muted-foreground">{{
            user.email
        }}</span>
    </div>
</template>
