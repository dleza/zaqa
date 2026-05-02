<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { Flag, Landmark, MapPin, ShieldCheck } from 'lucide-vue-next'

const props = defineProps<{
  groups: Array<{
    awarding_institution_id: number | null
    awarding_institution_name: string
    count: number
    local_count?: number
    foreign_count?: number
  }>
}>()
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
        <div class="text-sm font-semibold text-text-primary">Awarding institutions</div>
        <div class="mt-1 text-xs text-text-muted">Counts include submitted/resubmitted/in-progress local applications.</div>
      </div>

      <div v-if="groups.length === 0" class="px-5 py-6 text-sm text-text-muted">No local applications in the pool.</div>

      <div v-else class="divide-y divide-border/60">
        <Link
          v-for="g in groups"
          :key="g.awarding_institution_name"
          :href="`/admin/verification/pool?foreign=0&awarding_institution_id=${g.awarding_institution_id ?? ''}`"
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
              >
                <MapPin class="h-3 w-3 opacity-80" aria-hidden="true" />
                Local
                <span class="tabular-nums">{{ g.local_count }}</span>
              </span>
              <span
                v-if="(g.foreign_count ?? 0) > 0"
                class="zaqa-badge zaqa-badge-warning inline-flex items-center gap-1"
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

