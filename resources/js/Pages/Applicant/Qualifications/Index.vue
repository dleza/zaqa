<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { computed } from 'vue'
import { ChevronRight, GraduationCap } from 'lucide-vue-next'

const props = defineProps<{
  filter: string
  filterLabel: string
  qualifications: Array<{
    id: number
    application_id: number
    application_number: string | null
    title_of_qualification: string | null
    verification_reference_number: string | null
    status_label: string
    href: string
    updated_at: string | null
  }>
  counts: Record<string, number>
}>()

const filters = [
  { key: 'total', label: 'Total' },
  { key: 'draft', label: 'Draft' },
  { key: 'processing', label: 'Processing' },
  { key: 'sent_back', label: 'Sent back' },
  { key: 'completed', label: 'Completed' },
] as const

const activeFilter = computed(() => props.filter || 'total')

function filterHref(key: string) {
  return `/applicant/qualifications?filter=${encodeURIComponent(key)}`
}

function formatWhen(iso: string | null | undefined) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(d)
}

function badgeClass(label: string) {
  const s = label.toLowerCase()
  if (s === 'draft' || s === 'sent back') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'processing') return 'zaqa-badge zaqa-badge-info'
  if (s === 'approved' || s === 'certificate issued' || s === 'closed') return 'zaqa-badge zaqa-badge-success'
  if (s === 'rejected') return 'zaqa-badge zaqa-badge-danger'
  return 'zaqa-badge'
}
</script>

<template>
  <ApplicantLayout wide>
    <div class="w-full">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
            <GraduationCap class="h-4 w-4" aria-hidden="true" />
            Qualifications
          </div>
          <h2 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">{{ filterLabel }}</h2>
          <p class="mt-1 text-sm text-text-muted">
            {{ qualifications.length }} qualification{{ qualifications.length === 1 ? '' : 's' }} listed.
          </p>
        </div>
        <Link href="/applicant/dashboard" class="zaqa-link text-sm font-semibold">Back to dashboard</Link>
      </div>

      <div class="mt-5 flex gap-2 overflow-x-auto pb-1">
        <Link
          v-for="f in filters"
          :key="f.key"
          :href="filterHref(f.key)"
          class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
          :class="
            activeFilter === f.key
              ? 'border-brand bg-brand text-white'
              : 'border-border bg-surface text-text-muted hover:border-brand/30 hover:text-text-primary'
          "
        >
          {{ f.label }}
          <span class="ml-1 tabular-nums opacity-80">{{ counts?.[f.key] ?? 0 }}</span>
        </Link>
      </div>

      <div class="mt-6">
        <div
          v-if="qualifications.length === 0"
          class="rounded-2xl border border-dashed border-border bg-surface-muted/40 px-5 py-10 text-center text-sm text-text-muted"
        >
          No qualifications in this category.
        </div>

        <div v-else class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04]">
          <ul class="divide-y divide-border/60">
            <li v-for="q in qualifications" :key="q.id">
              <Link
                :href="q.href"
                class="group flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-surface-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 sm:px-6"
              >
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <div class="text-sm font-semibold text-text-primary">
                      {{ q.title_of_qualification || 'Untitled qualification' }}
                    </div>
                    <span :class="badgeClass(q.status_label)">{{ q.status_label }}</span>
                  </div>
                  <div class="mt-1 text-xs text-text-muted">
                    <span v-if="q.verification_reference_number" class="font-mono">{{ q.verification_reference_number }}</span>
                    <span v-else>No reference yet</span>
                    <span class="mx-1.5">•</span>
                    Application {{ q.application_number ?? '—' }}
                    <span class="mx-1.5">•</span>
                    Updated {{ formatWhen(q.updated_at) }}
                  </div>
                </div>
                <ChevronRight class="h-5 w-5 shrink-0 text-text-muted transition group-hover:translate-x-0.5" aria-hidden="true" />
              </Link>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
