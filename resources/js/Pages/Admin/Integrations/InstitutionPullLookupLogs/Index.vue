<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import { router } from '@inertiajs/vue3'
import { FileText } from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  logs: any
  institutions: Array<{ id: number; name: string }>
  filters: { q?: string | null; awarding_institution_id?: string | null; status?: string | null }
}>()

const q = ref(props.filters?.q ?? '')
const awardingInstitutionId = ref(props.filters?.awarding_institution_id ?? '')
const status = ref(props.filters?.status ?? '')

const queryParams = computed(() => ({
  q: q.value || undefined,
  awarding_institution_id: awardingInstitutionId.value || undefined,
  status: status.value || undefined,
}))

function applyFilters() {
  router.get('/admin/integrations/institution-pull-lookup-logs', queryParams.value, { preserveState: true, replace: true })
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
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Institution Pull Lookup Logs</h1>
        <p class="mt-1 text-sm text-text-muted">Audit trail for ZAQA → institution lookup calls (sanitized).</p>
      </div>
    </div>

    <div class="mt-6 space-y-4">
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="flex flex-col gap-4">
          <div>
            <div class="text-sm font-semibold text-text-primary">Filters</div>
            <div class="mt-1 text-xs text-text-muted">Narrow logs by request details, institution, or lookup outcome.</div>
          </div>

          <div class="grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(220px,1fr)_minmax(180px,0.8fr)_auto] lg:items-end">
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Search</label>
              <input
                v-model="q"
                class="zaqa-input mt-2 h-10"
                placeholder="Endpoint, correlation, student/cert…"
                @keydown.enter.prevent="applyFilters"
              />
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
                <option value="found">found</option>
                <option value="not_found">not_found</option>
                <option value="failed">failed</option>
                <option value="timeout">timeout</option>
                <option value="invalid_response">invalid_response</option>
              </select>
            </div>
            <button type="button" class="zaqa-btn zaqa-btn-secondary h-10 w-full px-4 py-2 text-sm lg:w-auto" @click="applyFilters">
              Apply
            </button>
          </div>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Requests</div>
          <div class="mt-1 text-xs text-text-muted">Newest first.</div>
        </div>

        <div v-if="logs.data.length === 0" class="px-5 py-6">
          <div class="rounded-2xl border border-dashed border-border bg-surface-muted p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand/10 text-brand">
              <FileText class="h-6 w-6" aria-hidden="true" />
            </div>
            <div class="mt-4 text-sm font-semibold text-text-primary">No pull lookup logs yet</div>
            <div class="mt-1 text-xs text-text-muted">
              Logs will appear here when ZAQA queries institution lookup endpoints.
            </div>
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
                  {{ l.created_at }} • {{ l.awarding_institution?.name || '—' }} • Q#{{ l.qualification_id }}
                </div>
              </div>
              <div class="flex items-center gap-2">
                <span class="zaqa-badge" :class="l.status === 'found' ? 'zaqa-badge-success' : l.status === 'not_found' ? 'zaqa-badge-secondary' : 'zaqa-badge-danger'">
                  {{ l.status || '—' }} {{ l.status_code ? `(${l.status_code})` : '' }}
                </span>
                <span class="text-xs text-text-muted">{{ l.latency_ms ? `${l.latency_ms}ms` : '' }}</span>
              </div>
            </div>

            <div v-if="l.error_message" class="mt-2 text-xs text-danger">Error: {{ l.error_message }}</div>

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

            <div class="mt-2 text-xs text-text-muted">Correlation: {{ l.correlation_id || '—' }}</div>
          </div>
        </div>

        <AdminTablePagination :paginator="logs" label="lookup logs" />
      </div>
    </div>
  </AdminLayout>
</template>
