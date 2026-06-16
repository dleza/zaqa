<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import FlashMessages from '@/Components/FlashMessages.vue'
import ApplicantSidebar from '@/Components/ApplicantSidebar.vue'
import TopbarNotificationsMenu from '@/Components/TopbarNotificationsMenu.vue'
import TopbarUserMenu from '@/Components/TopbarUserMenu.vue'
import { applicantNavSections } from '@/Layouts/applicantNav'
import { Menu, X } from 'lucide-vue-next'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'

const props = withDefaults(
  defineProps<{
    containerMaxWidthClass?: string
    /** Full-width main area with generous horizontal padding (dashboard workspace). */
    wide?: boolean
  }>(),
  {
    containerMaxWidthClass: 'max-w-none',
    wide: false,
  },
)

const mainClass = computed(() =>
  props.wide
    ? 'w-full min-w-0 flex-1 py-6'
    : `mx-auto w-full flex-1 px-4 py-6 lg:px-6 2xl:px-10 ${props.containerMaxWidthClass}`,
)

const headerInnerClass = computed(() =>
  props.wide
    ? 'flex w-full items-center justify-between px-4 py-4 sm:px-6 lg:px-8 xl:px-10 2xl:px-12'
    : `mx-auto flex w-full items-center justify-between px-4 py-4 lg:px-6 2xl:px-10 ${props.containerMaxWidthClass}`,
)

const footerInnerClass = computed(() =>
  props.wide
    ? 'flex w-full flex-col gap-2 px-4 py-8 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8 xl:px-10 2xl:px-12'
    : `zaqa-footer-inner ${props.containerMaxWidthClass}`,
)

const page = usePage()

const mobileSidebarOpen = ref(false)
const user = computed(() => (page.props as any).auth?.user)
</script>

<template>
  <div class="zaqa-page min-h-screen">
    <!-- Mobile top bar (minimal) -->
    <header class="zaqa-topbar lg:hidden">
      <div class="zaqa-topbar-inner">
        <button
          type="button"
          class="zaqa-btn zaqa-btn-ghost-on-brand px-3 py-2"
          aria-label="Open menu"
          @click="mobileSidebarOpen = true"
        >
          <Menu class="h-5 w-5" aria-hidden="true" />
        </button>

        <Link href="/applicant/dashboard" class="zaqa-brand">
          <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-9 w-auto shrink-0 object-contain" />
          <div class="flex flex-col">
            <span class="zaqa-brand-kicker">ZAQA</span>
            <span class="zaqa-brand-name">Applicant</span>
          </div>
        </Link>

        <div class="flex items-center gap-2">
          <TopbarNotificationsMenu v-if="user" variant="brand" basePath="/applicant/notifications" />
          <TopbarUserMenu
            v-if="user"
            :user="user"
            variant="brand"
            profile-href="/applicant/profile"
            password-href="/applicant/change-password"
            :hide-label-on-mobile="true"
          />
        </div>
      </div>
    </header>

    <div class="flex min-h-screen">
      <!-- Desktop sidebar -->
      <div class="hidden lg:block lg:sticky lg:top-0 lg:h-screen">
        <ApplicantSidebar :sections="applicantNavSections" />
      </div>

      <!-- Mobile sidebar overlay -->
      <transition
        enter-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="mobileSidebarOpen"
          class="fixed inset-0 z-50 lg:hidden"
          role="dialog"
          aria-modal="true"
          aria-label="Applicant menu"
          @keydown.esc="mobileSidebarOpen = false"
        >
          <button
            type="button"
            class="absolute inset-0 bg-black/60"
            aria-label="Close menu"
            @click="mobileSidebarOpen = false"
          />

          <transition
            enter-active-class="transition-transform duration-200 ease-out"
            enter-from-class="-translate-x-2 opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition-transform duration-150 ease-in"
            leave-from-class="translate-x-0 opacity-100"
            leave-to-class="-translate-x-2 opacity-0"
          >
            <div class="relative h-full w-[18rem] bg-surface shadow-2xl">
              <div class="flex items-center justify-between border-b border-border px-4 py-4">
                <div class="text-sm font-semibold text-text-primary">Menu</div>
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs"
                  aria-label="Close menu"
                  @click="mobileSidebarOpen = false"
                >
                  <X class="h-4 w-4" aria-hidden="true" />
                </button>
              </div>

              <ApplicantSidebar :sections="applicantNavSections" :is-mobile="true" @navigate="mobileSidebarOpen = false" />
            </div>
          </transition>
        </div>
      </transition>

      <!-- Main content -->
      <div class="flex min-w-0 flex-1 flex-col">
        <!-- Desktop header (minimal, no horizontal nav) -->
        <header class="hidden border-b border-border bg-surface lg:block">
          <div :class="headerInnerClass">
            <div>
              <div class="text-sm font-semibold text-text-primary">Applicant Portal</div>
            </div>

            <div class="flex items-center gap-3">
              <TopbarNotificationsMenu v-if="user" basePath="/applicant/notifications" />
              <TopbarUserMenu v-if="user" :user="user" profile-href="/applicant/profile" password-href="/applicant/change-password" />
            </div>
          </div>
        </header>

        <main :class="mainClass">
          <div v-if="wide" class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 2xl:px-12">
            <FlashMessages />
            <slot name="pageHeader" />
            <slot />
          </div>
          <template v-else>
            <FlashMessages />
            <slot name="pageHeader" />
            <slot />
          </template>
        </main>

        <footer class="zaqa-footer hidden md:block">
          <div :class="footerInnerClass">
            <span class="text-text-on-dark/90">© {{ new Date().getFullYear() }} Zambia Qualifications Authority (ZAQA)</span>
            <span class="text-text-on-dark/75">Applicant services • Verification workflow • Secure downloads</span>
          </div>
        </footer>
      </div>
    </div>
  </div>
</template>
