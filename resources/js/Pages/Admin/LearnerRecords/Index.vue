<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { BookOpen, FileSpreadsheet, Search } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  records: any
  countries: Array<{ id: number; name: string; iso_code: string }>
  institutions: Array<{ id: number; name: string; is_active: boolean }>
  filters: { q: string; country_id: string | null; awarding_institution_id: string | null; year_awarded: string | null }
  can: { view: boolean; import: boolean }
}>()

const q = ref(props.filters.q ?? '')
const countryId = ref(props.filters.country_id ?? '')
const awardingInstitutionId = ref(props.filters.awarding_institution_id ?? '')
const yearAwarded = ref(props.filters.year_awarded ?? '')

watch(
  () => countryId.value,
  () => {
    awardingInstitutionId.value = ''
  },
)

watch([q, countryId, awardingInstitutionId, yearAwarded], () => {
  router.get(
    '/admin/learner-records',
    {
      q: q.value || null,
      country_id: countryId.value || null,
      awarding_institution_id: awardingInstitutionId.value || null,
      year_awarded: yearAwarded.value || null,
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
          <BookOpen class="h-4 w-4" aria-hidden="true" />
          Learner Records
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Learner achievement records</h1>
        <p class="mt-1 text-sm text-text-muted">Internal catalog used for qualification auto-verification and title selection.</p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Link
          v-if="can.import"
          href="/admin/learner-records/imports"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
        >
          <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
          Imports
        </Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">All records</div>
            <div class="mt-1 text-xs text-text-muted">Search by name, identifiers, or program of study.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search..." />
            </div>
            <select v-model="countryId" class="zaqa-input h-10">
              <option value="">All countries</option>
              <option v-for="c in countries" :key="c.id" :value="String(c.id)">{{ c.name }} ({{ c.iso_code }})</option>
            </select>
            <select v-model="awardingInstitutionId" class="zaqa-input h-10">
              <option value="">All institutions</option>
              <option v-for="i in institutions" :key="i.id" :value="String(i.id)">{{ i.name }}{{ i.is_active ? '' : ' (inactive)' }}</option>
            </select>
            <input v-model="yearAwarded" class="zaqa-input h-10 w-32" placeholder="Year" inputmode="numeric" />
          </div>
        </div>
      </div>

      <div v-if="records.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No learner records found</div>
          <div class="mt-1 text-xs text-text-muted">Adjust your filters, or upload an import.</div>
          <div class="mt-4">
            <Link v-if="can.import" href="/admin/learner-records/imports" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
              Upload import
            </Link>
          </div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Program</th>
              <th class="px-5 py-3 text-left">Institution</th>
              <th class="px-5 py-3 text-left">Student ID</th>
              <th class="px-5 py-3 text-left">Certificate</th>
              <th class="px-5 py-3 text-left">Learner</th>
              <th class="px-5 py-3 text-left">Year</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="r in records.data" :key="r.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ r.program_of_study || '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">Source: {{ r.source_type || '—' }}</div>
              </td>
              <td class="px-5 py-3">
                <div class="text-text-primary">{{ r.awarding_institution?.name || r.institution_name_raw || '—' }}</div>
              </td>
              <td class="px-5 py-3 font-mono text-xs text-text-primary">{{ r.student_id || '—' }}</td>
              <td class="px-5 py-3 font-mono text-xs text-text-primary">{{ r.certificate_no || '—' }}</td>
              <td class="px-5 py-3">
                <div class="text-text-primary">
                  {{ [r.first_name, r.other_names, r.last_name].filter(Boolean).join(' ') || '—' }}
                </div>
                <div class="mt-0.5 text-xs text-text-muted">
                  NRC: <span class="font-mono">{{ r.nrc_number || '—' }}</span>
                  · Passport: <span class="font-mono">{{ r.passport_no || '—' }}</span>
                </div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ r.year_awarded || '—' }}</td>
              <td class="px-5 py-3 text-right">
                <Link :href="`/admin/learner-records/records/${r.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                  View
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
