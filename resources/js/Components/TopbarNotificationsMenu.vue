<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { Bell, Check, ListChecks } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    variant?: 'surface' | 'brand'
  }>(),
  {
    variant: 'surface',
  },
)

const page = usePage()

const menuOpen = ref(false)
const rootEl = ref<HTMLElement | null>(null)

const unreadCount = computed<number>(() => {
  const n = Number((page.props as any).auth?.notifications_unread_count ?? 0)
  return Number.isFinite(n) ? Math.max(0, n) : 0
})

const notifications = computed<any[]>(() => ((page.props as any).auth?.notifications ?? []) as any[])

const buttonClass = computed(() => {
  const base = 'zaqa-btn h-10 py-2'
  if (props.variant === 'brand') return `${base} px-3 zaqa-btn-ghost-on-brand`
  return `${base} px-3 zaqa-btn-secondary`
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

function timeAgo(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  const t = d.getTime()
  if (Number.isNaN(t)) return ''

  const diffSec = Math.max(0, Math.floor((Date.now() - t) / 1000))
  if (diffSec < 10) return 'just now'
  if (diffSec < 60) return `${diffSec}s ago`
  const diffMin = Math.floor(diffSec / 60)
  if (diffMin < 60) return `${diffMin}m ago`
  const diffHr = Math.floor(diffMin / 60)
  if (diffHr < 24) return `${diffHr}h ago`
  const diffDay = Math.floor(diffHr / 24)
  return `${diffDay}d ago`
}

function markAllRead() {
  router.post(
    '/admin/notifications/read-all',
    {},
    {
      preserveScroll: true,
      onSuccess: () => {
        router.reload({ only: ['auth'] })
      },
    },
  )
}

function markRead(id: string) {
  router.post(
    `/admin/notifications/${id}/read`,
    {},
    {
      preserveScroll: true,
      onSuccess: () => {
        router.reload({ only: ['auth'] })
      },
    },
  )
}
</script>

<template>
  <div ref="rootEl" class="relative">
    <button type="button" :class="buttonClass" aria-haspopup="menu" :aria-expanded="menuOpen ? 'true' : 'false'" @click="toggleMenu">
      <span class="relative inline-flex items-center">
        <Bell class="h-4 w-4" aria-hidden="true" />
        <span
          v-if="unreadCount > 0"
          class="absolute -right-2 -top-2 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white"
        >
          {{ unreadCount > 99 ? '99+' : unreadCount }}
        </span>
      </span>
      <span class="sr-only">Notifications</span>
    </button>

    <div
      v-if="menuOpen"
      class="absolute right-0 z-50 mt-2 w-[22rem] overflow-hidden rounded-2xl border border-border bg-surface shadow-lg"
      role="menu"
    >
      <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
        <div class="min-w-0">
          <div class="text-sm font-semibold text-text-primary">Notifications</div>
          <div class="mt-0.5 text-xs text-text-muted">
            <span v-if="unreadCount === 0">All caught up</span>
            <span v-else>{{ unreadCount }} unread</span>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button
            v-if="unreadCount > 0"
            type="button"
            class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
            @click="markAllRead"
          >
            <ListChecks class="h-4 w-4" aria-hidden="true" />
            Mark all read
          </button>
          <Link href="/admin/notifications" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs" @click="closeMenu">
            View all
          </Link>
        </div>
      </div>

      <div class="max-h-[22rem] overflow-auto">
        <div v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-text-muted">
          No notifications yet.
        </div>

        <div v-else class="divide-y divide-border/60">
          <div v-for="n in notifications" :key="n.id" class="px-4 py-3" :class="n.read_at ? '' : 'bg-brand/[0.03]'">
            <div class="flex items-start justify-between gap-3">
              <Link
                class="min-w-0 text-left"
                :href="`/admin/notifications/${n.id}/open`"
                method="post"
                as="button"
              >
                <div class="flex items-center gap-2">
                  <span v-if="!n.read_at" class="inline-flex h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden="true" />
                  <div class="truncate text-sm font-semibold text-text-primary">{{ n.title || 'Notification' }}</div>
                </div>
                <div class="mt-1 line-clamp-2 text-xs text-text-muted">{{ n.message }}</div>
                <div class="mt-2 text-[11px] text-text-muted">{{ timeAgo(n.created_at) }}</div>
              </Link>

              <button
                v-if="!n.read_at"
                type="button"
                class="zaqa-btn zaqa-btn-ghost h-9 shrink-0 px-2 py-2 text-xs text-brand hover:bg-brand/10"
                title="Mark as read"
                @click="markRead(n.id)"
              >
                <Check class="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

