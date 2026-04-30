<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { ChevronDown, User as UserIcon } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    user?: any | null
    variant?: 'surface' | 'brand'
  }>(),
  {
    user: null,
    variant: 'surface',
  },
)

const page = usePage()

const menuOpen = ref(false)
const rootEl = ref<HTMLElement | null>(null)
const avatarErrored = ref(false)

const avatarUrl = computed<string | null>(() => {
  const u = props.user ?? {}
  const url =
    u.profile_photo_url ??
    u.profilePhotoUrl ??
    u.avatar_url ??
    u.avatarUrl ??
    u.image_url ??
    u.imageUrl ??
    u.photo_url ??
    u.photoUrl ??
    null
  return typeof url === 'string' && url.trim().length > 0 ? url : null
})

watch(avatarUrl, () => {
  avatarErrored.value = false
})

const hasAvatar = computed(() => !!avatarUrl.value && !avatarErrored.value)

const displayName = computed(() => (props.user?.name ?? '').toString().trim() || 'Account')
const email = computed(() => (props.user?.email ?? '').toString().trim())

const initials = computed(() => {
  const name = displayName.value.trim()
  if (!name || name === 'Account') return ''
  const parts = name.split(/\s+/).filter(Boolean)
  const a = parts[0]?.[0] ?? ''
  const b = parts.length > 1 ? parts[parts.length - 1]?.[0] ?? '' : ''
  return `${a}${b}`.toUpperCase()
})

const buttonName = computed(() => {
  if (props.variant !== 'brand') return displayName.value
  const parts = displayName.value.split(/\s+/).filter(Boolean)
  return parts[0] ?? displayName.value
})

const buttonClass = computed(() => {
  const base = 'zaqa-btn h-10 py-2 text-sm'
  if (props.variant === 'brand') return `${base} px-2 zaqa-btn-ghost-on-brand`
  return `${base} px-3 zaqa-btn-secondary`
})

const avatarClass = computed(() => {
  const base = 'flex h-9 w-9 items-center justify-center rounded-full'
  if (props.variant === 'brand') return `${base} border border-brand-foreground/25 bg-brand-dark/20 text-brand-foreground`
  return `${base} border border-border bg-surface-muted text-text-muted`
})

function closeMenu() {
  menuOpen.value = false
}

function toggleMenu() {
  menuOpen.value = !menuOpen.value
}

function onDocumentClick(e: MouseEvent) {
  if (!menuOpen.value) return
  const target = e.target as Node | null
  if (!target || !rootEl.value) return
  if (!rootEl.value.contains(target)) closeMenu()
}

function onDocumentKeydown(e: KeyboardEvent) {
  if (!menuOpen.value) return
  if (e.key === 'Escape') closeMenu()
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
})

watch(
  () => (page.url ?? '').toString(),
  () => closeMenu(),
)
</script>

<template>
  <div ref="rootEl" class="relative">
    <button
      type="button"
      :class="buttonClass"
      aria-haspopup="menu"
      :aria-expanded="menuOpen ? 'true' : 'false'"
      @click="toggleMenu"
    >
      <span class="relative flex items-center gap-2">
        <img
          v-if="hasAvatar"
          :src="avatarUrl as string"
          alt="Profile photo"
          class="h-9 w-9 rounded-full object-cover"
          @error="avatarErrored = true"
        />
        <span v-else :class="avatarClass" aria-hidden="true">
          <span v-if="initials" class="text-xs font-semibold">{{ initials }}</span>
          <UserIcon v-else class="h-4 w-4" aria-hidden="true" />
        </span>

        <span class="max-w-[7rem] truncate font-semibold sm:max-w-[10rem]">{{ buttonName }}</span>
      </span>

      <ChevronDown class="h-4 w-4 shrink-0 opacity-80 transition-transform" :class="menuOpen ? 'rotate-180' : 'rotate-0'" aria-hidden="true" />
    </button>

    <div
      v-if="menuOpen"
      class="absolute right-0 z-50 mt-2 w-60 overflow-hidden rounded-xl border border-border bg-surface shadow-lg"
      role="menu"
    >
      <div class="border-b border-border px-4 py-3">
        <div class="truncate text-sm font-semibold text-text-primary">{{ displayName }}</div>
        <div class="mt-0.5 truncate text-xs text-text-muted">{{ email }}</div>
      </div>

      <div class="py-1">
        <Link
          href="/admin/profile"
          class="flex w-full items-center px-4 py-2.5 text-sm font-semibold text-text-primary transition hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
          role="menuitem"
          @click="closeMenu"
        >
          View Profile
        </Link>
        <Link
          href="/admin/change-password"
          class="flex w-full items-center px-4 py-2.5 text-sm font-semibold text-text-primary transition hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
          role="menuitem"
          @click="closeMenu"
        >
          Change Password
        </Link>
        <Link
          href="/logout"
          method="post"
          as="button"
          class="flex w-full items-center px-4 py-2.5 text-sm font-semibold text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/30"
          role="menuitem"
          @click="closeMenu"
        >
          Logout
        </Link>
      </div>
    </div>
  </div>
</template>
