<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import FlashMessages from '@/Components/FlashMessages.vue'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'

const props = withDefaults(
  defineProps<{
    maxWidthClass?: string
    card?: boolean
    contentPaddingClass?: string
    headerCompact?: boolean
    hideHeader?: boolean
    centerContent?: boolean
  }>(),
  {
    maxWidthClass: 'max-w-lg',
    card: true,
    contentPaddingClass: 'px-4 py-12 sm:px-6 sm:py-14 lg:py-16',
    headerCompact: false,
    hideHeader: false,
    centerContent: true,
  },
)

const page = usePage()
const currentPath = computed(() => {
  const raw = (page.url ?? '/').split('?')[0]
  return raw.length === 0 ? '/' : raw
})

const isLoginPage = computed(() => currentPath.value === '/login')
const isRegisterPage = computed(() => currentPath.value === '/register')
</script>

<template>
  <div class="zaqa-page flex flex-col">
    <header v-if="!props.hideHeader" class="zaqa-topbar">
      <div class="zaqa-topbar-inner" :class="props.headerCompact ? 'py-3' : 'py-5'">
        <a href="/" class="zaqa-brand" aria-label="ZAQA Portal">
          <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-10 w-auto shrink-0 object-contain" />
          <div class="flex flex-col">
            <span class="zaqa-brand-kicker">Zambia Qualifications Authority</span>
            <span class="zaqa-brand-name">Verification Portal</span>
          </div>
        </a>

        <nav class="hidden items-center gap-2 sm:flex" aria-label="Primary navigation">
          <a v-if="!isLoginPage" href="/login" class="zaqa-btn zaqa-btn-ghost-on-brand h-10 px-4 py-2 text-sm">
            Log in
          </a>
          <a v-if="!isRegisterPage" href="/register" class="zaqa-btn zaqa-btn-ghost-on-brand h-10 px-4 py-2 text-sm">
            Register and Apply
          </a>
        </nav>
      </div>
    </header>

    <main class="relative flex flex-1">
      <div aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden">
        <div class="absolute -top-24 left-1/2 h-96 w-96 -translate-x-1/2 rounded-full bg-brand/15 blur-3xl" />
        <div class="absolute -bottom-32 -right-24 h-[28rem] w-[28rem] rounded-full bg-accent/10 blur-3xl" />
        <div class="absolute inset-0 bg-gradient-to-b from-white/40 via-transparent to-white/25" />
      </div>

      <div
        class="relative mx-auto flex w-full flex-1 flex-col"
        :class="[props.maxWidthClass, props.contentPaddingClass, props.centerContent ? 'sm:justify-center' : '']"
      >
        <FlashMessages />
        <div
          v-if="props.card"
          class="rounded-2xl border border-border/80 bg-surface p-8 shadow-[0_18px_60px_-45px_rgba(11,58,102,0.25)] sm:p-10"
        >
          <slot />
        </div>
        <slot v-else />
      </div>
    </main>

    <footer class="zaqa-footer">
      <div class="zaqa-footer-inner">
        <span class="text-text-on-dark/90">© {{ new Date().getFullYear() }} Zambia Qualifications Authority (ZAQA)</span>
        <span class="text-text-on-dark/75">Secure qualification verification and certificate validation.</span>
      </div>
    </footer>
  </div>
</template>
