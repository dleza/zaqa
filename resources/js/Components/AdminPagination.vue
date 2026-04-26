<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

type LinkRow = { url: string | null; label: string; active: boolean }

const props = defineProps<{
  links: LinkRow[]
}>()
</script>

<template>
  <nav v-if="links && links.length > 3" class="mt-5 flex flex-wrap items-center justify-center gap-2" aria-label="Pagination">
    <template v-for="(l, idx) in links" :key="idx">
      <span
        v-if="!l.url"
        class="zaqa-btn zaqa-btn-secondary h-9 cursor-not-allowed px-3 py-2 text-xs opacity-60"
        v-html="l.label"
      />
      <Link
        v-else
        :href="l.url"
        class="zaqa-btn h-9 px-3 py-2 text-xs"
        :class="l.active ? 'zaqa-btn-primary' : 'zaqa-btn-secondary'"
        v-html="l.label"
      />
    </template>
  </nav>
</template>

