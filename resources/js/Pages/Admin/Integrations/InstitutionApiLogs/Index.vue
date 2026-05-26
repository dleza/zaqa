<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { router } from '@inertiajs/vue3'
import { FileText } from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  logs: any
  institutions: Array<{ id: number; name: string }>
  filters: {
    q?: string | null
    awarding_institution_id?: string | null
    status?: string | null
    endpoint?: string | null
    from?: string | null
    to?: string | null
  }
}>()

const q = ref(props.filters?.q ?? '')
const awardingInstitutionId = ref(props.filters?.awarding_institution_id ?? '')
const status = ref(props.filters?.status ?? '')
const endpoint = ref(props.filters?.endpoint ?? '')
const from = ref(props.filters?.from ?? '')
const to = ref(props.filters?.to ?? '')

const queryParams = computed(() => ({
  q: q.value || undefined,
  awarding_institution_id: awardingInstitutionId.value || undefined,
  status: status.value || undefined,
  endpoint: endpoint.value || undefined,
  from: from.value || undefined,
  to: to.value || undefined,
}))

function applyFilters() {
  router.get('/admin/integrations/institution-api-logs', queryParams.value, { preserveState: true, replace: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <FileText class="h-4 w-4" aria-hidden="true" />
          Integrations
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Institution API Logs</h1>
        <p class="mt-1 text-sm text-text-muted">Audit trail for institution API requests (sanitized).</p>
      </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
      <div class="rounded-2xl border border-border bg-surface p-5 lg:col-span-1">
        <div class="text-sm font-semibold text-text-primary">Filters</div>
        <div class="mt-4 space-y-3">
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Search</label>
            <input v-model="q" class="zaqa-input mt-2 h-10" placeholder="Correlation ID, endpoint, client…" @keydown.enter.prevent="applyFilters" />
          </div>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Institution</label>
            <select v-model="awardingInstitutionId" class="zaqa-input mt-2 h-10">
              <option value="">All</option>
              <option v-for="i in institutions" :key="i.id" :value="String(i.id)">{{ i.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Status</label>
            <select v-model="status" class="zaqa-input mt-2 h-10">
              <option value="">All</option>
              <option value="success">success</option>
              <option value="validation_failed">validation_failed</option>
              <option value="unauthorized">unauthorized</option>
              <option value="throttled">throttled</option>
              <option value="failed">failed</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Endpoint contains</label>
            <input v-model="endpoint" class="zaqa-input mt-2 h-10" placeholder="/api/institution/v1/…" @keydown.enter.prevent="applyFilters" />
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">From</label>
              <input v-model="from" type="date" class="zaqa-input mt-2 h-10" />
            </div>
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">To</label>
              <input v-model="to" type="date" class="zaqa-input mt-2 h-10" />
            </div>
          </div>
          <button type="button" class="zaqa-btn zaqa-btn-secondary w-full px-4 py-2 text-sm" @click="applyFilters">Apply</button>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm lg:col-span-2">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Requests</div>
          <div class="mt-1 text-xs text-text-muted">Newest first.</div>
        </div>

        <div v-if="logs.data.length === 0" class="px-5 py-6">
          <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
            <div class="text-sm font-semibold text-text-primary">No logs</div>
            <div class="mt-1 text-xs text-text-muted">Requests will appear here once institutions start integrating.</div>
          </div>
        </div>

        <div v-else class="divide-y divide-border/60">
          <div v-for="l in logs.data" :key="l.id" class="p-5 hover:bg-surface-muted/60">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-text-primary">
                  <span class="mr-2">{{ l.method }}</span>
                  <span class="break-all">{{ l.endpoint }}</span>
                </div>
                <div class="mt-1 text-xs text-text-muted">
                  {{ l.created_at }} • {{ l.awarding_institution?.name || '—' }} • {{ l.client?.name || '—' }}
                </div>
              </div>
              <div class="flex items-center gap-2">
                <span class="zaqa-badge" :class="l.status === 'success' ? 'zaqa-badge-success' : l.status === 'validation_failed' ? 'zaqa-badge-warning' : 'zaqa-badge-danger'">
                  {{ l.status || '—' }} {{ l.status_code ? `(${l.status_code})` : '' }}
                </span>
                <span class="text-xs text-text-muted">{{ l.latency_ms ? `${l.latency_ms}ms` : '' }}</span>
              </div>
            </div>

            <div class="mt-3 grid gap-3 lg:grid-cols-2">
              <div class="rounded-xl border border-border bg-surface p-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Request</div>
                <pre class="mt-2 max-h-52 overflow-auto text-xs text-text-primary">{{ JSON.stringify(l.request_payload || {}, null, 2) }}</pre>
              </div>
              <div class="rounded-xl border border-border bg-surface p-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Response</div>
                <pre class="mt-2 max-h-52 overflow-auto text-xs text-text-primary">{{ JSON.stringify(l.response_payload || {}, null, 2) }}</pre>
              </div>
            </div>

            <div class="mt-2 text-xs text-text-muted">Correlation: {{ l.correlation_id || '—' }} • IP: {{ l.ip_address || '—' }}</div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

