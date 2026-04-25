<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { Landmark, Search, ShieldCheck } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  groups: Array<{ awarding_institution_id: number | null; awarding_institution_name: string; count: number }>
  filters?: { overdue_days?: string | null; submitted_from?: string | null; submitted_to?: string | null; qualification_q?: string | null }
}>()

const overdueDays = ref((props.filters?.overdue_days ?? '').toString())
const submittedFrom = ref((props.filters?.submitted_from ?? '').toString())
const submittedTo = ref((props.filters?.submitted_to ?? '').toString())
const qualificationQ = ref((props.filters?.qualification_q ?? '').toString())

watch([overdueDays, submittedFrom, submittedTo, qualificationQ], () => {
  router.get(
    '/admin/verification/pool/awarding-institution',
    {
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
        <p class="mt-1 text-sm text-text-muted">Local applications grouped by awarding institution.</p>
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
            <div class="mt-1 text-xs text-text-muted">Counts include submitted/resubmitted/in-progress local applications.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
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

      <div v-if="groups.length === 0" class="px-5 py-6 text-sm text-text-muted">No local applications in the pool.</div>

      <div v-else class="divide-y divide-border/60">
        <Link
          v-for="g in groups"
          :key="g.awarding_institution_name"
          :href="
            `/admin/verification/pool?foreign=0&awarding_institution_id=${g.awarding_institution_id ?? ''}` +
            `&submitted_from=${encodeURIComponent(submittedFrom || '')}` +
            `&submitted_to=${encodeURIComponent(submittedTo || '')}` +
            `&qualification_q=${encodeURIComponent(qualificationQ || '')}` +
            `&overdue_days=${encodeURIComponent(overdueDays || '')}`
          "
          class="flex items-center justify-between px-5 py-4 transition hover:bg-surface-muted/60"
        >
          <div class="flex items-center gap-3">
            <Landmark class="h-4 w-4 text-text-muted" aria-hidden="true" />
            <div class="text-sm font-semibold text-text-primary">{{ g.awarding_institution_name }}</div>
          </div>
          <div class="zaqa-badge zaqa-badge-secondary">{{ g.count }}</div>
        </Link>
      </div>
    </div>
  </AdminLayout>
</template>

