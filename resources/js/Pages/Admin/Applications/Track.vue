<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { FileSearch, FileText, Search } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  selected?: any | null
  timeline?: Array<any>
  statuses?: Array<any>
  filters?: { application_id?: string | null }
  can?: { view_verification: boolean }
}>()

const query = ref('')
const loading = ref(false)
const suggestions = ref<Array<any>>([])
const open = ref(false)

let debounce: number | null = null
watch(
  () => query.value,
  () => {
    if (debounce) window.clearTimeout(debounce)
    const q = query.value.trim()
    if (q.length < 3) {
      suggestions.value = []
      open.value = false
      return
    }
    debounce = window.setTimeout(async () => {
      loading.value = true
      try {
        const res = await fetch(`/admin/applications/track/suggest?q=${encodeURIComponent(q)}`, {
          headers: { Accept: 'application/json' },
        })
        const json = await res.json()
        suggestions.value = Array.isArray(json?.data) ? json.data : []
        open.value = true
      } finally {
        loading.value = false
      }
    }, 250)
  },
)

function selectSuggestion(s: any) {
  open.value = false
  suggestions.value = []
  query.value = ''
  router.get('/admin/applications/track', { application_id: s.id }, { preserveScroll: true })
}

const viewHref = computed(() => {
  if (!props.selected) return null
  if (props.can?.view_verification) return `/admin/verification/applications/${props.selected.id}`
  return null
})

const statusBadgeClass = computed(() => {
  return (status: string | null | undefined) => {
    const s = (status ?? '').toString()
    if (['approved', 'certificate_ready', 'completed'].includes(s)) return 'zaqa-badge-success'
    if (['rejected', 'failed'].includes(s)) return 'zaqa-badge-danger'
    if (['submitted', 'resubmitted'].includes(s)) return 'zaqa-badge-warning'
    if (['in_progress', 'under_review'].includes(s)) return 'zaqa-badge-info'
    if (['sent_back', 'returned_to_applicant'].includes(s)) return 'zaqa-badge-warning'
    return 'zaqa-badge-secondary'
  }
})
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <FileText class="h-4 w-4" aria-hidden="true" />
          Applications
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Track applications</h1>
        <p class="mt-1 text-sm text-text-muted">Search and pull up an application timeline quickly.</p>
      </div>
    </div>

    <div class="mt-6 rounded-2xl border border-border bg-surface p-6 shadow-sm">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="text-sm font-semibold text-text-primary">Retrieve application</div>
          <div class="mt-1 text-xs text-text-muted">Search by Application number, NRC, or Passport. Type at least 3 characters.</div>
        </div>

        <div class="relative w-full sm:max-w-xl">
          <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
          <input
            v-model="query"
            class="zaqa-input h-11 pl-9"
            placeholder="e.g. ZAQA-VER-1234, 111111/11/1, P1234567"
            @focus="open = suggestions.length > 0"
            @keydown.escape="open = false"
          />

          <div
            v-if="open"
            class="absolute z-20 mt-2 w-full overflow-hidden rounded-xl border border-border bg-surface shadow-lg"
          >
            <div v-if="loading" class="px-4 py-3 text-sm text-text-muted">Searching…</div>
            <div v-else-if="suggestions.length === 0" class="px-4 py-3 text-sm text-text-muted">No matches.</div>
            <button
              v-for="s in suggestions"
              :key="s.id"
              type="button"
              class="flex w-full items-start justify-between gap-3 px-4 py-3 text-left text-sm transition hover:bg-surface-muted"
              @click="selectSuggestion(s)"
            >
              <div class="min-w-0">
                <div class="font-semibold text-text-primary">{{ s.application_number }}</div>
                <div class="mt-0.5 truncate text-xs text-text-muted">
                  {{ s.name ?? '—' }} • {{ s.nrc_passport ?? '—' }} • {{ s.qualification_title ?? '—' }}
                </div>
              </div>
              <div class="shrink-0">
                <span class="zaqa-badge" :class="statusBadgeClass(s.status)">{{ s.status }}</span>
              </div>
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="selected" class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2 space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-sm font-semibold text-text-primary">Application activity</div>
              <div class="mt-1 text-xs text-text-muted">Latest lifecycle events and operational milestones.</div>
            </div>
            <Link v-if="viewHref" :href="viewHref" class="zaqa-btn zaqa-btn-secondary h-10 px-4 py-2 text-sm">
              <FileSearch class="h-4 w-4" aria-hidden="true" />
              View application
            </Link>
          </div>

          <div class="mt-4 space-y-3">
            <div v-if="(timeline?.length ?? 0) === 0" class="text-sm text-text-muted">No lifecycle events.</div>
            <div v-for="e in timeline" :key="e.id" class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="flex items-center justify-between gap-4">
                <div class="text-sm font-semibold text-text-primary">{{ e.title }}</div>
                <div class="text-xs text-text-muted">{{ e.occurred_at ? new Date(e.occurred_at).toLocaleString() : '—' }}</div>
              </div>
              <div class="mt-1 text-xs text-text-muted">{{ e.stage }} • {{ e.visibility }} • {{ e.actor_name ?? '—' }}</div>
              <div v-if="e.description" class="mt-2 text-sm text-text-primary">{{ e.description }}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Selected application</div>
          <div class="mt-3 grid gap-2 text-sm">
            <div class="flex items-center justify-between">
              <div class="text-text-muted">Application #</div>
              <div class="font-semibold text-text-primary">{{ selected.application_number }}</div>
            </div>
            <div class="flex items-center justify-between">
              <div class="text-text-muted">Status</div>
              <div><span class="zaqa-badge" :class="statusBadgeClass(selected.current_status)">{{ selected.current_status }}</span></div>
            </div>
            <div class="flex items-center justify-between">
              <div class="text-text-muted">Verification</div>
              <div class="font-semibold text-text-primary">{{ selected.verification_state ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between">
              <div class="text-text-muted">Applicant</div>
              <div class="font-semibold text-text-primary">{{ selected.applicant_name ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between">
              <div class="text-text-muted">NRC/Passport</div>
              <div class="font-semibold text-text-primary">{{ selected.nrc_passport_number ?? '—' }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Status history</div>
          <div class="mt-4 space-y-3">
            <div v-if="(statuses?.length ?? 0) === 0" class="text-sm text-text-muted">No status history.</div>
            <div v-for="s in statuses" :key="s.id" class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="flex items-center justify-between gap-4">
                <div class="text-sm font-semibold text-text-primary">{{ s.to_status }}</div>
                <div class="text-xs text-text-muted">{{ s.changed_at ? new Date(s.changed_at).toLocaleString() : '—' }}</div>
              </div>
              <div class="mt-1 text-xs text-text-muted">From {{ s.from_status ?? '—' }} • {{ s.changed_by ?? '—' }}</div>
              <div v-if="s.comment" class="mt-2 text-sm text-text-primary">{{ s.comment }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

