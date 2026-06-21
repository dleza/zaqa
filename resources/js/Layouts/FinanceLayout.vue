<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import FlashMessages from '@/Components/FlashMessages.vue'
import { Landmark } from 'lucide-vue-next'

const page = usePage()
const user = computed(() => (page.props as any).auth?.user)
</script>

<template>
  <div class="zaqa-page flex min-h-screen flex-col">
    <header class="zaqa-topbar">
      <div class="zaqa-topbar-inner">
        <Link href="/" class="zaqa-brand" aria-label="ZAQA Finance Portal">
          <Landmark class="h-6 w-6" aria-hidden="true" />
          <div class="hidden flex-col sm:flex">
            <span class="zaqa-brand-kicker">ZAQA</span>
            <span class="zaqa-brand-name">Finance</span>
          </div>
        </Link>

        <div class="flex items-center gap-3">
          <div class="text-right">
            <div class="text-sm font-semibold text-text-on-dark">{{ user?.name ?? 'Finance' }}</div>
            <div class="text-xs text-text-on-dark/75">{{ user?.email }}</div>
          </div>
          <Link href="/logout" method="post" as="button" class="zaqa-btn zaqa-btn-ghost-on-brand px-3 py-2 text-sm">
            Log out
          </Link>
        </div>
      </div>
    </header>

    <main class="zaqa-container flex-1 py-6">
      <FlashMessages />
      <slot />
    </main>

    <footer class="zaqa-footer">
      <div class="zaqa-footer-inner">
        <span class="text-text-on-dark/90">© {{ new Date().getFullYear() }} Zambia Qualifications Authority (ZAQA)</span>
        <span class="text-text-on-dark/75">Finance operations • Proof reviews • Receipts</span>
      </div>
    </footer>
  </div>
</template>

