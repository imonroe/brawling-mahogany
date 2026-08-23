<script setup lang="ts">
/**
 * S28 — attach a workflow to a live deal (PRD F4.7 · issue #74).
 *
 * ## The preview is why this is a modal and not a menu
 *
 * Issue #74: *"the preview shows what will be created before it is created.
 * Attaching is not undoable in a tidy way, and a wrong template on a live deal
 * is a mess."* Instantiating copies the whole tree into the runtime tables and
 * activates the first stage — undoing it means deleting stages somebody may
 * already have ticked. So every template shows its stage names, expanded on
 * the one that is selected.
 *
 * ## Already attached is a state, not a refusal
 *
 * The same template twice on one deal is legal and occasionally meant — two
 * rounds of pre-listing improvements — so it is marked and still choosable.
 */
import { useForm } from '@inertiajs/vue3';
import { Check, Layers } from '@lucide/vue';
import { ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatCount } from '@/lib/formatters';

type TemplateStage = { name: string; isMilestone: boolean };

type Template = {
    id: string;
    name: string;
    description: string | null;
    packName: string | null;
    isSystem: boolean;
    isAttached: boolean;
    stages: TemplateStage[];
};

const props = defineProps<{ open: boolean; dealId: string }>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const templates = ref<Template[]>([]);
const packs = ref<{ slug: string; name: string }[]>([]);
const pack = ref<string | null>(null);
const selected = ref<string | null>(null);
const loading = ref(false);
const failed = ref(false);

const form = useForm({ workflow_template_id: '' });

/**
 * The same two guards the other pickers carry: a session that expired answers
 * with HTML that `response.json()` rejects on, and a filter change can land an
 * older response after a newer one.
 */
async function load(): Promise<void> {
    const requested = pack.value ?? 'all';

    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(
            `/deals/${props.dealId}/workflows/available?pack=${encodeURIComponent(requested)}`,
            { headers: { Accept: 'application/json' } },
        );

        const body = response.ok ? await response.json() : null;

        if (requested !== (pack.value ?? 'all')) {
            return;
        }

        templates.value = body?.templates ?? [];
        packs.value = body?.packs ?? [];
        failed.value = body === null;
    } catch {
        if (requested === (pack.value ?? 'all')) {
            templates.value = [];
            failed.value = true;
        }
    } finally {
        if (requested === (pack.value ?? 'all')) {
            loading.value = false;
        }
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            pack.value = null;
            selected.value = null;
            form.reset();
            form.clearErrors();
            void load();
        }
    },
);

watch(pack, () => void load());

function attach(): void {
    if (!selected.value) {
        return;
    }

    form.workflow_template_id = selected.value;

    form.post(`/deals/${props.dealId}/workflows`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[85svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Attach a workflow</DialogTitle>
                <DialogDescription>
                    A deal can run several at once — the sale and the
                    pre-listing work, or the under-contract process once an
                    offer is accepted.
                </DialogDescription>
            </DialogHeader>

            <AppSelect
                v-if="packs.length > 0"
                :model-value="pack"
                :options="
                    Object.fromEntries(
                        packs.map((each) => [each.slug, each.name]),
                    )
                "
                placeholder="Every pack"
                aria-label="Filter by pack"
            />

            <p
                v-if="form.errors.workflow_template_id"
                class="text-[11px] text-state-danger"
            >
                {{ form.errors.workflow_template_id }}
            </p>

            <ul class="flex flex-col gap-2">
                <li
                    v-if="loading"
                    class="rounded-md border px-3 py-2.5 text-xs text-muted-foreground"
                >
                    Looking…
                </li>
                <li
                    v-else-if="failed"
                    class="rounded-md border px-3 py-2.5 text-xs text-state-danger"
                >
                    Couldn’t load your templates. Refresh the page and try
                    again.
                </li>
                <li
                    v-else-if="templates.length === 0"
                    class="rounded-md border px-3 py-2.5 text-xs text-muted-foreground"
                >
                    No workflow templates yet. Install a pack from Templates, or
                    build one — a deal runs fine without one until then.
                </li>
                <li
                    v-for="template in templates"
                    v-else
                    :key="template.id"
                    class="rounded-md border"
                    :class="
                        selected === template.id
                            ? 'border-primary bg-accent/40'
                            : 'border-border'
                    "
                >
                    <button
                        type="button"
                        class="flex min-h-11 w-full items-center gap-2 px-3 py-2.5 text-left"
                        :aria-pressed="selected === template.id"
                        @click="selected = template.id"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-13 font-medium text-foreground"
                                >{{ template.name }}</span
                            >
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{
                                    [
                                        template.packName,
                                        formatCount(
                                            template.stages.length,
                                            'stage',
                                        ),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}</span
                            >
                        </span>

                        <!--
                            Marked, not disabled. Twice is legal and sometimes
                            meant — two rounds of pre-listing improvements.
                        -->
                        <StatusBadge
                            v-if="template.isAttached"
                            tone="neutral"
                            label="Already on this deal"
                            dotless
                        />
                        <Check
                            v-if="selected === template.id"
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                    </button>

                    <!--
                        The preview. Names rather than a count, because the
                        count does not tell you the template is the wrong one.
                    -->
                    <div
                        v-if="selected === template.id"
                        class="flex flex-col gap-1.5 border-t px-3 py-2.5"
                    >
                        <p
                            v-if="template.description"
                            class="text-[11px] text-muted-foreground"
                        >
                            {{ template.description }}
                        </p>
                        <p
                            class="flex items-center gap-1.5 text-[11px] font-semibold text-foreground"
                        >
                            <Layers class="size-3.5" aria-hidden="true" />
                            This creates
                        </p>
                        <ol class="flex flex-col gap-0.5">
                            <li
                                v-for="(stage, index) in template.stages"
                                :key="stage.name"
                                class="text-[11px] text-muted-foreground"
                            >
                                {{ index + 1 }}. {{ stage.name
                                }}<span
                                    v-if="stage.isMilestone"
                                    class="text-primary"
                                >
                                    · milestone</span
                                >
                            </li>
                        </ol>
                        <p class="text-[11px] text-muted-foreground">
                            The first stage starts straight away, and its tasks
                            appear in your queue.
                        </p>
                    </div>
                </li>
            </ul>

            <DialogFooter>
                <AppButton
                    variant="ghost"
                    type="button"
                    @click="emit('update:open', false)"
                    >Cancel</AppButton
                >
                <AppButton
                    :disabled="!selected || form.processing"
                    @click="attach"
                    >Attach workflow</AppButton
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
