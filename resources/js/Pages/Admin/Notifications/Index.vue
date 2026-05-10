<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Bell, Check, ListChecks } from 'lucide-vue-next'

const props = defineProps<{
  notifications: any
  filter: 'all' | 'unread' | 'read'
  unreadCount: number
}>()

const rows = computed<any[]>(() => (props.notifications?.data ?? []) as any[])

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
  router.post('/admin/notifications/read-all', {}, { preserveScroll: true })
}

function markRead(id: string) {
  router.post(`/admin/notifications/${id}/read`, {}, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-5xl space-y-6">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <div class="flex items-center gap-2 text-base font-semibold text-text-primary">
            <Bell class="h-5 w-5 text-brand" aria-hidden="true" />
            Notifications
          </div>
          <p class="mt-1 text-sm text-text-muted">Assignment and review updates for verification tasks.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            v-if="unreadCount > 0"
            type="button"
            class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs"
            @click="markAllRead"
          >
            <ListChecks class="h-4 w-4" aria-hidden="true" />
            Mark all read
          </button>
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <Link
          href="/admin/notifications"
          :data="{ filter: 'all' }"
          class="zaqa-btn h-9 px-4 py-2 text-xs"
          :class="filter === 'all' ? 'zaqa-btn-primary' : 'zaqa-btn-secondary'"
        >
          All
        </Link>
        <Link
          href="/admin/notifications"
          :data="{ filter: 'unread' }"
          class="zaqa-btn h-9 px-4 py-2 text-xs"
          :class="filter === 'unread' ? 'zaqa-btn-primary' : 'zaqa-btn-secondary'"
        >
          Unread
        </Link>
        <Link
          href="/admin/notifications"
          :data="{ filter: 'read' }"
          class="zaqa-btn h-9 px-4 py-2 text-xs"
          :class="filter === 'read' ? 'zaqa-btn-primary' : 'zaqa-btn-secondary'"
        >
          Read
        </Link>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div v-if="rows.length === 0" class="px-6 py-10 text-center text-sm text-text-muted">
          No notifications found.
        </div>

        <div v-else class="divide-y divide-border/60">
          <div
            v-for="n in rows"
            :key="n.id"
            class="px-6 py-4"
            :class="n.read_at ? '' : 'bg-brand/[0.03]'"
          >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <span v-if="!n.read_at" class="inline-flex h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden="true" />
                  <div class="truncate text-sm font-semibold text-text-primary">{{ n.title || 'Notification' }}</div>
                </div>
                <div class="mt-1 whitespace-pre-wrap text-sm text-text-muted">{{ n.message }}</div>
                <div class="mt-2 text-xs text-text-muted">{{ timeAgo(n.created_at) }}</div>
              </div>

              <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Link
                  class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                  :href="`/admin/notifications/${n.id}/open`"
                  method="post"
                  as="button"
                >
                  Open
                </Link>

                <button
                  v-if="!n.read_at"
                  type="button"
                  class="zaqa-btn zaqa-btn-ghost h-9 px-3 py-2 text-xs text-brand hover:bg-brand/10"
                  @click="markRead(n.id)"
                >
                  <Check class="h-4 w-4" aria-hidden="true" />
                  Mark read
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <AdminPagination :links="notifications.links ?? []" />
    </div>
  </AdminLayout>
</template>

