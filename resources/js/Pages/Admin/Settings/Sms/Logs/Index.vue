<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { MessageSquare } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  logs: any
  filters: { status: string | null; message_type: string | null; from: string | null; to: string | null }
}>()

const status = ref(props.filters.status ?? '')
const messageType = ref(props.filters.message_type ?? '')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')

watch([status, messageType, from, to], () => {
  router.get(
    '/admin/settings/sms/logs',
    {
      status: status.value || null,
      message_type: messageType.value || null,
      from: from.value || null,
      to: to.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <MessageSquare class="h-4 w-4" aria-hidden="true" />
          System Settings
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">SMS Logs</h1>
        <p class="mt-1 text-sm text-text-muted">Review queued, sent, failed, and skipped SMS attempts.</p>
      </div>
      <Link href="/admin/settings/sms/balance" class="zaqa-btn px-4 py-2 text-sm">SMS balance</Link>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="grid gap-2 md:grid-cols-4">
          <select v-model="status" class="zaqa-input h-10">
            <option value="">All statuses</option>
            <option value="queued">Queued</option>
            <option value="sent">Sent</option>
            <option value="failed">Failed</option>
            <option value="skipped">Skipped</option>
          </select>
          <input v-model="messageType" type="text" class="zaqa-input h-10" placeholder="Message type" />
          <input v-model="from" type="date" class="zaqa-input h-10" />
          <input v-model="to" type="date" class="zaqa-input h-10" />
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase tracking-wide text-text-muted">
            <tr>
              <th class="px-5 py-3">When</th>
              <th class="px-5 py-3">Status</th>
              <th class="px-5 py-3">Type</th>
              <th class="px-5 py-3">Recipient</th>
              <th class="px-5 py-3">Provider</th>
              <th class="px-5 py-3">HTTP</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in logs.data" :key="row.id" class="border-t border-border">
              <td class="px-5 py-3">{{ row.created_at }}</td>
              <td class="px-5 py-3">{{ row.status }}</td>
              <td class="px-5 py-3">{{ row.message_type }}</td>
              <td class="px-5 py-3">{{ row.phone_number }}</td>
              <td class="px-5 py-3">{{ row.provider }}</td>
              <td class="px-5 py-3">{{ row.http_status ?? '—' }}</td>
              <td class="px-5 py-3 text-right">
                <Link :href="row.show_url" class="zaqa-btn px-3 py-1.5 text-xs">View</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminTablePagination :paginator="logs" label="SMS logs" />
    </div>
  </AdminLayout>
</template>
