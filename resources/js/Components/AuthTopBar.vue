<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import { ArrowRight } from 'lucide-vue-next'

withDefaults(
  defineProps<{
    rightHref: string
    rightLabel: string
    rightLabelShort?: string
  }>(),
  {},
)
</script>

<template>
  <header class="relative z-20 w-full border-b border-white/30 bg-white/40 backdrop-blur-md">
    <div class="mx-auto flex w-full max-w-[1400px] items-center justify-between gap-3 px-4 py-2.5 sm:gap-4 sm:px-6 sm:py-3">
      <Link href="/" class="flex items-center gap-3" aria-label="ZAQA Portal">
        <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-8 w-auto shrink-0 object-contain sm:h-9" />
        <div class="hidden leading-tight sm:block">
          <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Zambia Qualifications Authority</div>
          <div class="text-sm font-semibold text-text-primary">Verification Portal</div>
        </div>
      </Link>

      <Link
        :href="rightHref"
        class="inline-flex h-10 items-center gap-2 rounded-xl border border-border bg-white/70 px-3 text-sm font-semibold text-text-primary shadow-sm transition hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 sm:px-4"
      >
        <span class="sm:hidden">{{ rightLabelShort ?? rightLabel }}</span>
        <span class="hidden sm:inline">{{ rightLabel }}</span>
        <ArrowRight class="hidden h-4 w-4 text-text-muted sm:inline" aria-hidden="true" />
      </Link>
    </div>
  </header>
</template>
