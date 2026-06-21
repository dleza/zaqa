<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import FlashMessages from '@/Components/FlashMessages.vue'
import AdminSidebar from '@/Components/AdminSidebar.vue'
import TopbarUserMenu from '@/Components/TopbarUserMenu.vue'
import TopbarNotificationsMenu from '@/Components/TopbarNotificationsMenu.vue'
import { adminNavSections } from '@/Layouts/adminNav'
import { Menu, X } from 'lucide-vue-next'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'

const page = usePage()
const mobileSidebarOpen = ref(false)

const user = computed(() => (page.props as any).auth?.user)
const permissions = computed<string[]>(() => ((page.props as any).auth?.permissions ?? []) as string[])
</script>

<template>
  <div class="zaqa-page min-h-screen">
    <!-- Mobile top bar -->
    <header class="zaqa-topbar lg:hidden">
      <div class="flex w-full items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
        <button type="button" class="zaqa-btn zaqa-btn-ghost-on-brand px-3 py-2" aria-label="Open menu" @click="mobileSidebarOpen = true">
          <Menu class="h-5 w-5" aria-hidden="true" />
        </button>

        <Link href="/admin/dashboard" class="zaqa-brand" aria-label="ZAQA Admin Portal">
          <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-9 w-auto shrink-0 object-contain" />
        </Link>

        <div class="flex items-center gap-2">
          <TopbarNotificationsMenu v-if="user" variant="brand" />
          <TopbarUserMenu :user="user" variant="brand" />
        </div>
      </div>
    </header>

    <div class="flex min-h-screen">
      <!-- Desktop sidebar -->
      <div class="hidden lg:block lg:sticky lg:top-0 lg:h-screen">
        <AdminSidebar :sections="adminNavSections" :permissions="permissions" />
      </div>

      <!-- Mobile sidebar overlay -->
      <transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-opacity duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="mobileSidebarOpen" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Admin menu" @keydown.esc="mobileSidebarOpen = false">
          <button type="button" class="absolute inset-0 bg-black/60" aria-label="Close menu" @click="mobileSidebarOpen = false" />
          <transition enter-active-class="transition-transform duration-200 ease-out" enter-from-class="-translate-x-2 opacity-0" enter-to-class="translate-x-0 opacity-100" leave-active-class="transition-transform duration-150 ease-in" leave-from-class="translate-x-0 opacity-100" leave-to-class="-translate-x-2 opacity-0">
            <div class="relative h-full w-[18rem] bg-surface shadow-2xl">
              <div class="flex items-center justify-between border-b border-border px-4 py-4">
                <div class="text-sm font-semibold text-text-primary">Menu</div>
                <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" aria-label="Close menu" @click="mobileSidebarOpen = false">
                  <X class="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
              <AdminSidebar :sections="adminNavSections" :permissions="permissions" :is-mobile="true" @navigate="mobileSidebarOpen = false" />
            </div>
          </transition>
        </div>
      </transition>

      <!-- Main content -->
      <div class="flex min-w-0 flex-1 flex-col">
        <!-- Desktop header -->
        <header class="hidden border-b border-border bg-surface lg:block">
          <div class="flex w-full items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <div>
              <div class="text-sm font-semibold text-text-primary">Admin Portal</div>
              <div class="mt-0.5 text-xs text-text-muted">ZAQA back-office operations</div>
            </div>
            <div class="flex items-center gap-2">
              <TopbarNotificationsMenu v-if="user" />
              <TopbarUserMenu :user="user" />
            </div>
          </div>
        </header>

        <main class="w-full flex-1 px-4 py-6 sm:px-6 lg:px-8">
          <FlashMessages />
          <slot />
        </main>

        <footer class="border-t border-border bg-surface-muted/60 px-4 py-2.5 text-xs text-text-muted sm:px-6 lg:px-8">
          <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <span>© {{ new Date().getFullYear() }} Zambia Qualifications Authority</span>
            <span class="hidden sm:inline">Admin portal</span>
          </div>
        </footer>
      </div>
    </div>
  </div>
</template>
