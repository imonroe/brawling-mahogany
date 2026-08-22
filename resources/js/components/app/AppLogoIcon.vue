<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

// Imported rather than written as a literal src: Vite fingerprints the file
// and rewrites the URL for the built bundle. `transformAssetUrls` is switched
// off for absolute paths in vite.config.ts, so a path in the template would
// ship unrewritten and 404 against the hashed build output.
import goldieflow from '../../../img/goldieflow.png';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- `object-contain` lives here, not at the call sites: the mark is 734x779,
         so a square box would stretch it, and that is a property of the asset
         rather than of any one place it appears.

         The plate is the same kind of decision. The mark is a fixed two-tone
         PNG, so its darkest tone all but disappears on the dark theme's
         near-black ground; `--logo-plate` gives it a light one. Dark only —
         in light mode the page is already the plate. The inset belongs to the
         call sites, which are the ones that know how big the mark is: the
         same padding cannot serve 200px and 32px.

         Decorative `alt`: every call site already carries the product name in
         text beside or beneath the mark, so naming it again only adds noise
         for a screen reader. -->
    <img
        :src="goldieflow"
        alt=""
        class="object-contain dark:rounded-lg dark:bg-logo-plate"
        :class="className"
        v-bind="$attrs"
    />
</template>
