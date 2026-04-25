<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import FlashMessages from '@/Components/FlashMessages.vue'

const props = withDefaults(
  defineProps<{
    maxWidthClass?: string
  }>(),
  {
    maxWidthClass: 'max-w-lg',
  },
)

const zaqaLogoUrl = new URL('../../images/zaqa-logo-tranparent.png', import.meta.url).href

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
    <header class="zaqa-topbar">
      <div class="zaqa-topbar-inner">
        <a href="/" class="zaqa-brand" aria-label="ZAQA Portal">
          <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-10 w-auto shrink-0 object-contain" />
          <div class="flex flex-col">
            <span class="zaqa-brand-kicker">Zambia Qualifications Authority</span>
            <span class="zaqa-brand-name">Verification Portal</span>
          </div>
        </a>

        <nav class="hidden items-center gap-2 sm:flex" aria-label="Primary navigation">
          <a v-if="!isLoginPage" href="/login" class="zaqa-nav-link" :class="{ 'zaqa-nav-link-active': isLoginPage }">
            Log in
          </a>
          <a
            v-if="!isRegisterPage"
            href="/register"
            class="zaqa-nav-link"
            :class="{ 'zaqa-nav-link-active': isRegisterPage }"
          >
            Create account
          </a>
        </nav>
      </div>
    </header>

    <main class="flex-1 flex">
      <div class="mx-auto flex w-full flex-1 flex-col px-4 py-10 sm:py-12 sm:justify-center" :class="props.maxWidthClass">
        <FlashMessages />
        <div class="zaqa-card sm:translate-y-4">
          <slot />
        </div>
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

