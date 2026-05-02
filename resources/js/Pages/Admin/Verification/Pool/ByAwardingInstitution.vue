<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { Flag, Landmark, MapPin, Search, ShieldCheck } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

type Locality = 'all' | 'local' | 'foreign'

const props = defineProps<{
  groups: Array<{
    awarding_institution_id: number | null
    awarding_institution_name: string
    count: number
    local_count?: number
    foreign_count?: number
  }>
  filters?: {
    locality?: string | null
    overdue_days?: string | null
    submitted_from?: string | null
    submitted_to?: string | null
    qualification_q?: string | null
  }
}>()

function normalizeLocality(v: string | null | undefined): Locality {
  if (v === 'local' || v === 'foreign') {
    return v
  }
  return 'all'
}

const locality = ref<Locality>(normalizeLocality(props.filters?.locality))
const overdueDays = ref((props.filters?.overdue_days ?? '').toString())
const submittedFrom = ref((props.filters?.submitted_from ?? '').toString())
const submittedTo = ref((props.filters?.submitted_to ?? '').toString())
const qualificationQ = ref((props.filters?.qualification_q ?? '').toString())

const countsBlurb = computed(() => {
  if (locality.value === 'local') {
    return 'Counts include submitted/resubmitted/in-progress pool items (local qualifications only).'
  }
  if (locality.value === 'foreign') {
    return 'Counts include submitted/resubmitted/in-progress pool items (foreign qualifications only).'
  }
  return 'Counts include submitted/resubmitted/in-progress pool items (local and foreign qualifications).'
})

const emptyBlurb = computed(() => {
  if (locality.value === 'local') {
    return 'No local qualifications in the pool.'
  }
  if (locality.value === 'foreign') {
    return 'No foreign qualifications in the pool.'
  }
  return 'No qualifications in the pool.'
})

function poolHref(awardingInstitutionId: number | null): string {
  const p = new URLSearchParams()
  if (locality.value === 'local') {
    p.set('foreign', '0')
  } else if (locality.value === 'foreign') {
    p.set('foreign', '1')
  }
  if (awardingInstitutionId != null) {
    p.set('awarding_institution_id', String(awardingInstitutionId))
  }
  if (submittedFrom.value) {
    p.set('submitted_from', submittedFrom.value)
  }
  if (submittedTo.value) {
    p.set('submitted_to', submittedTo.value)
  }
  if (qualificationQ.value) {
    p.set('q', qualificationQ.value)
  }
  const qs = p.toString()
  return qs ? `/admin/verification/pool?${qs}` : '/admin/verification/pool'
}

watch([locality, overdueDays, submittedFrom, submittedTo, qualificationQ], () => {
  router.get(
    '/admin/verification/pool/awarding-institution',
    {
      locality: locality.value === 'all' ? null : locality.value,
      overdue_days: overdueDays.value || null,
      submitted_from: submittedFrom.value || null,
      submitted_to: submittedTo.value || null,
      qualification_q: qualificationQ.value || null,
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
          <ShieldCheck class="h-4 w-4" aria-hidden="true" />
          Verification
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Category view: Awarding institution</h1>
        <p class="mt-1 text-sm text-text-muted">
          Qualifications grouped by awarding institution. Filter by locality or view both.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to pool</Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Awarding institutions</div>
            <div class="mt-1 text-xs text-text-muted">{{ countsBlurb }}</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <select v-model="locality" class="zaqa-input h-10 min-w-[10rem]" aria-label="Qualification locality">
              <option value="all">Locality: all</option>
              <option value="local">Local only</option>
              <option value="foreign">Foreign only</option>
            </select>
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="qualificationQ" class="zaqa-input h-10 pl-9" placeholder="Qualification contains..." />
            </div>
            <input v-model="submittedFrom" type="date" class="zaqa-input h-10" />
            <input v-model="submittedTo" type="date" class="zaqa-input h-10" />
            <select v-model="overdueDays" class="zaqa-input h-10">
              <option value="">Overdue by</option>
              <option value="30">30+ days</option>
              <option value="60">60+ days</option>
              <option value="90">90+ days</option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="groups.length === 0" class="px-5 py-6 text-sm text-text-muted">{{ emptyBlurb }}</div>

      <div v-else class="divide-y divide-border/60">
        <Link
          v-for="g in groups"
          :key="`${g.awarding_institution_id ?? 'x'}-${g.awarding_institution_name}`"
          :href="poolHref(g.awarding_institution_id)"
          class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 transition hover:bg-surface-muted/60"
        >
          <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
            <div class="flex min-w-0 items-center gap-3">
              <Landmark class="h-4 w-4 shrink-0 text-text-muted" aria-hidden="true" />
              <div class="truncate text-sm font-semibold text-text-primary">{{ g.awarding_institution_name }}</div>
            </div>
            <div class="flex flex-wrap items-center gap-1.5 pl-7 sm:pl-0">
              <span
                v-if="(g.local_count ?? 0) > 0"
                class="zaqa-badge zaqa-badge-success inline-flex items-center gap-1"
                :title="`${g.local_count} local qualification(s) in the pool`"
              >
                <MapPin class="h-3 w-3 opacity-80" aria-hidden="true" />
                Local
                <span class="tabular-nums">{{ g.local_count }}</span>
              </span>
              <span
                v-if="(g.foreign_count ?? 0) > 0"
                class="zaqa-badge zaqa-badge-warning inline-flex items-center gap-1"
                :title="`${g.foreign_count} foreign qualification(s) in the pool`"
              >
                <Flag class="h-3 w-3 opacity-90" aria-hidden="true" />
                Foreign
                <span class="tabular-nums">{{ g.foreign_count }}</span>
              </span>
            </div>
          </div>
          <div class="zaqa-badge zaqa-badge-secondary shrink-0 tabular-nums">{{ g.count }}</div>
        </Link>
      </div>
    </div>
  </AdminLayout>
</template>

