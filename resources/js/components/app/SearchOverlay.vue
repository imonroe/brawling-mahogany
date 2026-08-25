<script setup lang="ts">
/**
 * S07 — the global search overlay (PRD §4.9 F9.3 · Design System §8.3 · #82).
 *
 * ## Keyboard-first, because that is what makes it faster than the sidebar
 *
 * *"A shortcut opens it, arrow keys navigate, enter opens."* ⌘K / Ctrl-K from
 * anywhere, and the shortcut is swallowed rather than shared with the
 * browser's own find — somebody who has learnt it must not sometimes get the
 * other thing.
 *
 * ## Grouped, with the type visible
 *
 * *"'123 Main St' is plausibly a deal, a property, or a document."* So the
 * type is a heading rather than an icon somebody has to learn, and an empty
 * group is not rendered at all: three headings above one result buries it.
 *
 * ## Recent before anything is typed
 *
 * *"The fastest search is the one you do not have to type."* The overlay opens
 * onto the team's recent deals, which is what somebody is overwhelmingly
 * reaching for.
 *
 * The results come from a JSON endpoint rather than an Inertia visit, because
 * this opens **over** whatever screen somebody is on and a visit would replace
 * it.
 */
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type Result = {
    id: string;
    label: string;
    meta: string | null;
    url: string;
};

type Group = { type: string; label: string; results: Result[] };

const open = defineModel<boolean>('open', { default: false });

const term = ref('');
const groups = ref<Group[]>([]);
const tooShort = ref(false);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);

/** Every result, flattened, so the arrow keys cross group boundaries. */
const flat = computed(() => groups.value.flatMap((group) => group.results));

let debounce: ReturnType<typeof setTimeout> | undefined;
/** The query this component last asked for, so a slow reply cannot overwrite a fast one. */
let inFlight = '';

async function load(query: string): Promise<void> {
    inFlight = query;

    const response = await fetch(`/search?q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok || inFlight !== query) {
        return;
    }

    const body = (await response.json()) as {
        groups: Group[];
        tooShort: boolean;
    };

    groups.value = body.groups;
    tooShort.value = body.tooShort;
    active.value = 0;
}

watch(term, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => void load(value), 200);
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    // Every open is a fresh search. Somebody reaching for ⌘K is starting a new
    // question, not resuming the last one.
    term.value = '';
    groups.value = [];
    tooShort.value = false;
    active.value = 0;

    void load('');
    void nextTick(() => input.value?.focus());
});

function choose(index: number): void {
    const result = flat.value[index];

    if (!result) {
        return;
    }

    open.value = false;
    router.visit(result.url);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(active.value + 1, flat.value.length - 1);

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        choose(active.value);
    }
}

/** ⌘K, and Ctrl-K for everybody not on a Mac. */
function onShortcut(event: KeyboardEvent): void {
    if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        open.value = true;
    }
}

onMounted(() => window.addEventListener('keydown', onShortcut));
onUnmounted(() => {
    window.removeEventListener('keydown', onShortcut);
    clearTimeout(debounce);
});

/** The flat index a group's row sits at, for highlighting across groups. */
function indexOf(group: Group, result: Result): number {
    return flat.value.findIndex((one) => one === result) ?? 0;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[70svh] flex-col gap-0 overflow-hidden p-0 sm:max-w-[560px]"
        >
            <DialogTitle class="sr-only">Search</DialogTitle>
            <DialogDescription class="sr-only">
                Search this team’s deals, people, and properties.
            </DialogDescription>

            <div class="flex items-center gap-2.5 border-b px-4 py-3">
                <Search
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <input
                    ref="input"
                    v-model="term"
                    type="search"
                    class="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    placeholder="Search deals, people, and properties"
                    aria-label="Search"
                    @keydown="onKeydown"
                />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <p
                    v-if="tooShort"
                    class="px-4 py-6 text-center text-13 text-muted-foreground"
                >
                    Keep typing — two letters or more.
                </p>

                <!--
                    "No results" is a claim about the team's data, and it is
                    only made once a real query has run. Before that the panel
                    shows what somebody probably wants instead.
                -->
                <p
                    v-else-if="groups.length === 0"
                    class="px-4 py-6 text-center text-13 text-muted-foreground"
                >
                    {{
                        term
                            ? `Nothing matches “${term}”.`
                            : 'Nothing here yet.'
                    }}
                </p>

                <template v-else>
                    <section v-for="group in groups" :key="group.type">
                        <h2
                            class="px-4 pt-3 pb-1 text-xs font-semibold text-muted-foreground uppercase"
                        >
                            {{ group.label }}
                        </h2>
                        <ul>
                            <li
                                v-for="result in group.results"
                                :key="result.id"
                            >
                                <button
                                    type="button"
                                    class="flex min-h-11 w-full items-center gap-3 px-4 py-2 text-left transition-colors duration-150 ease-out"
                                    :class="
                                        indexOf(group, result) === active
                                            ? 'bg-accent'
                                            : 'hover:bg-accent/60'
                                    "
                                    @click="choose(indexOf(group, result))"
                                >
                                    <span
                                        class="min-w-0 flex-1 truncate text-13 text-foreground"
                                        >{{ result.label }}</span
                                    >
                                    <span
                                        v-if="result.meta"
                                        class="shrink-0 text-[11px] text-muted-foreground"
                                        >{{ result.meta }}</span
                                    >
                                </button>
                            </li>
                        </ul>
                    </section>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
