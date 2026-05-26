<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { Globe } from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  institutions: any
  filters: { q?: string | null; supports_pull?: string | null }
}>()

const q = ref(props.filters?.q ?? '')
const supportsPull = ref(props.filters?.supports_pull ?? '')

const queryParams = computed(() => ({
  q: q.value || undefined,
  supports_pull: supportsPull.value || undefined,
}))

function applyFilters() {
  router.get('/admin/integrations/institution-integrations', queryParams.value, { preserveState: true, replace: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Globe class="h-4 w-4" aria-hidden="true" />
          Integrations
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Institution Pull Integrations</h1>
        <p class="mt-1 text-sm text-text-muted">Configure ZAQA pull lookup endpoints per awarding institution.</p>
      </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
      <div class="rounded-2xl border border-border bg-surface p-5 lg:col-span-1">
        <div class="text-sm font-semibold text-text-primary">Filters</div>
        <div class="mt-4 space-y-3">
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Search</label>
            <input v-model="q" class="zaqa-input mt-2 h-10" placeholder="Institution name…" @keydown.enter.prevent="applyFilters" />
          </div>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Supports pull</label>
            <select v-model="supportsPull" class="zaqa-input mt-2 h-10">
              <option value="">All</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>
          <button type="button" class="zaqa-btn zaqa-btn-secondary w-full px-4 py-2 text-sm" @click="applyFilters">Apply</button>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm lg:col-span-2">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Institutions</div>
          <div class="mt-1 text-xs text-text-muted">Each institution can optionally be configured for pull lookup during auto-verification.</div>
        </div>

        <div v-if="institutions.data.length === 0" class="px-5 py-6">
          <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
            <div class="text-sm font-semibold text-text-primary">No institutions</div>
          </div>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
              <tr>
                <th class="px-5 py-3 text-left">Institution</th>
                <th class="px-5 py-3 text-left">Pull</th>
                <th class="px-5 py-3 text-left">Lookup URL</th>
                <th class="px-5 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="i in institutions.data" :key="i.id" class="hover:bg-surface-muted/60">
                <td class="px-5 py-3">
                  <div class="font-semibold text-text-primary">{{ i.name }}</div>
                  <div class="mt-0.5 text-xs text-text-muted">
                    Last success: {{ i.integration?.last_success_at || '—' }} • Last failure: {{ i.integration?.last_failure_at || '—' }}
                  </div>
                </td>
                <td class="px-5 py-3">
                  <span class="zaqa-badge" :class="i.integration?.supports_pull ? 'zaqa-badge-success' : 'zaqa-badge-secondary'">
                    {{ i.integration?.supports_pull ? 'enabled' : 'disabled' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-xs text-text-primary">
                  <span class="break-all">{{ i.integration?.lookup_url || '—' }}</span>
                </td>
                <td class="px-5 py-3 text-right">
                  <Link :href="i.edit_url" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs">Configure</Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

