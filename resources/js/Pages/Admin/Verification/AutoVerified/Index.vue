<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { Search, Sparkles } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  qualifications: any
  institutions: Array<{ id: number; name: string }>
  filters: {
    q: string
    awarding_institution_id?: string | null
    verification_source?: string | null
    confidence_min?: string | null
    confidence_max?: string | null
    locked?: string | null
    submitted_from?: string | null
    submitted_to?: string | null
  }
  lock_ttl_minutes: number
}>()

const q = ref(props.filters.q ?? '')
const awardingInstitutionId = ref<string>(props.filters.awarding_institution_id ?? '')
const verificationSource = ref<string>(props.filters.verification_source ?? '')
const confidenceMin = ref<string>(props.filters.confidence_min ?? '')
const confidenceMax = ref<string>(props.filters.confidence_max ?? '')
const locked = ref<string>(props.filters.locked ?? '')
const submittedFrom = ref<string>(props.filters.submitted_from ?? '')
const submittedTo = ref<string>(props.filters.submitted_to ?? '')

watch([q, awardingInstitutionId, verificationSource, confidenceMin, confidenceMax, locked, submittedFrom, submittedTo], () => {
  router.get(
    '/admin/verification/auto-verified',
    {
      q: q.value || null,
      awarding_institution_id: awardingInstitutionId.value || null,
      verification_source: verificationSource.value || null,
      confidence_min: confidenceMin.value || null,
      confidence_max: confidenceMax.value || null,
      locked: locked.value || null,
      submitted_from: submittedFrom.value || null,
      submitted_to: submittedTo.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const lockBadgeClass = computed(() => {
  return (isLocked: boolean) => (isLocked ? 'zaqa-badge-warning' : 'zaqa-badge-secondary')
})
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Sparkles class="h-4 w-4" aria-hidden="true" />
          Verification
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Auto-Verified Pending Review</h1>
        <p class="mt-1 text-sm text-text-muted">Qualifications auto-verified from learner records and awaiting Level 2 review.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Verification pool</Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Queue</div>
            <div class="mt-1 text-xs text-text-muted">Search, filter, lock, and review auto-verified tasks.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input
                v-model="q"
                class="zaqa-input h-10 pl-9"
                placeholder="Search application #, holder, student/cert #..."
              />
            </div>
            <input v-model="submittedFrom" type="date" class="zaqa-input h-10" />
            <input v-model="submittedTo" type="date" class="zaqa-input h-10" />
            <select v-model="awardingInstitutionId" class="zaqa-input h-10">
              <option value="">All institutions</option>
              <option v-for="i in institutions" :key="i.id" :value="String(i.id)">{{ i.name }}</option>
            </select>
            <select v-model="verificationSource" class="zaqa-input h-10">
              <option value="">All sources</option>
              <option value="internal_learner_record">Internal learner record</option>
              <option value="institution_api">Institution API</option>
            </select>
            <div class="flex items-center gap-2">
              <input v-model="confidenceMin" class="zaqa-input h-10 w-24" placeholder="Min %" inputmode="numeric" />
              <input v-model="confidenceMax" class="zaqa-input h-10 w-24" placeholder="Max %" inputmode="numeric" />
            </div>
            <select v-model="locked" class="zaqa-input h-10">
              <option value="">Locked + Unlocked</option>
              <option value="1">Locked</option>
              <option value="0">Unlocked</option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="qualifications.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No auto-verified tasks found</div>
          <div class="mt-1 text-xs text-text-muted">Adjust filters.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Holder</th>
              <th class="px-5 py-3 text-left">Qualification</th>
              <th class="px-5 py-3 text-left">Verified title</th>
              <th class="px-5 py-3 text-left">Institution</th>
              <th class="px-5 py-3 text-left">Year</th>
              <th class="px-5 py-3 text-left">Confidence</th>
              <th class="px-5 py-3 text-left">Source</th>
              <th class="px-5 py-3 text-left">Lock</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="row in qualifications.data" :key="row.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ row.application?.application_number ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">Task #{{ row.id }}</div>
                <div v-if="row.application?.submitted_at" class="mt-1 text-xs text-text-muted">
                  Submitted: {{ new Date(row.application.submitted_at).toLocaleDateString() }}
                </div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ row.holder_name ?? '—' }}</td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ row.qualification_title ?? '—' }}</div>
              </td>
              <td class="px-5 py-3">
                <div class="text-text-primary">{{ row.verified_title ?? '—' }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ row.awarding_institution ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ row.year_awarded ?? '—' }}</td>
              <td class="px-5 py-3">
                <span class="zaqa-badge zaqa-badge-info">{{ row.confidence != null ? `${row.confidence}%` : '—' }}</span>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ row.verification_source ?? '—' }}</td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="lockBadgeClass(row.lock?.is_locked)">
                  {{ row.lock?.is_locked ? 'Locked' : 'Unlocked' }}
                </span>
                <div v-if="row.lock?.is_locked" class="mt-1 text-xs text-text-muted">
                  By {{ row.lock.locked_by_name || '—' }}
                </div>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="row.review_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Review</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="qualifications.links?.length" class="border-t border-border bg-surface px-5 py-4">
        <div class="flex flex-wrap gap-2">
          <Link
            v-for="(l, idx) in qualifications.links"
            :key="idx"
            :href="l.url || ''"
            class="zaqa-btn h-9 px-3 py-2 text-xs"
            :class="l.active ? 'zaqa-btn-primary' : 'zaqa-btn-secondary'"
            :disabled="!l.url"
            v-html="l.label"
          />
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

