<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { ArrowRight } from 'lucide-vue-next'

withDefaults(
  defineProps<{
    rightHref: string
    rightLabel: string
  }>(),
  {},
)

const zaqaLogoUrl = new URL('../../images/zaqa-logo-tranparent.png', import.meta.url).href
</script>

<template>
  <header class="relative z-20 w-full border-b border-white/30 bg-white/40 backdrop-blur-md">
    <div class="mx-auto flex w-full max-w-[1400px] items-center justify-between gap-4 px-4 py-3 sm:px-6">
      <Link href="/" class="flex items-center gap-3" aria-label="ZAQA Portal">
        <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-9 w-auto shrink-0 object-contain" />
        <div class="leading-tight">
          <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Zambia Qualifications Authority</div>
          <div class="text-sm font-semibold text-text-primary">Verification Portal</div>
        </div>
      </Link>

      <Link
        :href="rightHref"
        class="inline-flex items-center gap-2 rounded-xl border border-border bg-white/70 px-4 py-2 text-sm font-semibold text-text-primary shadow-sm transition hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
      >
        {{ rightLabel }}
        <ArrowRight class="h-4 w-4 text-text-muted" aria-hidden="true" />
      </Link>
    </div>
  </header>
</template>

