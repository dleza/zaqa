<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { KeyRound } from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  clients: any
  institutions: Array<{ id: number; name: string }>
  filters: { q?: string | null; awarding_institution_id?: string | null; is_active?: string | null }
}>()

const q = ref(props.filters?.q ?? '')
const awardingInstitutionId = ref(props.filters?.awarding_institution_id ?? '')
const isActive = ref(props.filters?.is_active ?? '')

const queryParams = computed(() => ({
  q: q.value || undefined,
  awarding_institution_id: awardingInstitutionId.value || undefined,
  is_active: isActive.value || undefined,
}))

function applyFilters() {
  router.get('/admin/integrations/institution-api-clients', queryParams.value, { preserveState: true, replace: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <KeyRound class="h-4 w-4" aria-hidden="true" />
          Integrations
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Institution API Clients</h1>
        <p class="mt-1 text-sm text-text-muted">Manage institution-scoped bearer tokens for learner record submissions.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/integrations/institution-api-clients/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">New client</Link>
      </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
      <div class="rounded-2xl border border-border bg-surface p-5 lg:col-span-1">
        <div class="text-sm font-semibold text-text-primary">Filters</div>
        <div class="mt-4 space-y-3">
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Search</label>
            <input v-model="q" class="zaqa-input mt-2 h-10" placeholder="Client or institution name…" @keydown.enter.prevent="applyFilters" />
          </div>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Awarding institution</label>
            <select v-model="awardingInstitutionId" class="zaqa-input mt-2 h-10">
              <option value="">All</option>
              <option v-for="i in institutions" :key="i.id" :value="String(i.id)">{{ i.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Active</label>
            <select v-model="isActive" class="zaqa-input mt-2 h-10">
              <option value="">All</option>
              <option value="1">Active</option>
              <option value="0">Disabled</option>
            </select>
          </div>

          <button type="button" class="zaqa-btn zaqa-btn-secondary w-full px-4 py-2 text-sm" @click="applyFilters">Apply</button>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm lg:col-span-2">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Clients</div>
          <div class="mt-1 text-xs text-text-muted">Bearer tokens are generated per client.</div>
        </div>

        <div v-if="clients.data.length === 0" class="px-5 py-6">
          <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
            <div class="text-sm font-semibold text-text-primary">No clients</div>
            <div class="mt-1 text-xs text-text-muted">Create an institution API client to issue tokens.</div>
          </div>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
              <tr>
                <th class="px-5 py-3 text-left">Client</th>
                <th class="px-5 py-3 text-left">Institution</th>
                <th class="px-5 py-3 text-left">Status</th>
                <th class="px-5 py-3 text-left">Tokens</th>
                <th class="px-5 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="c in clients.data" :key="c.id" class="hover:bg-surface-muted/60">
                <td class="px-5 py-3">
                  <div class="font-semibold text-text-primary">{{ c.name }}</div>
                  <div class="mt-0.5 text-xs text-text-muted">Last used: {{ c.last_used_at || '—' }}</div>
                </td>
                <td class="px-5 py-3 text-text-primary">{{ c.awarding_institution?.name || '—' }}</td>
                <td class="px-5 py-3">
                  <span class="zaqa-badge" :class="c.is_active ? 'zaqa-badge-success' : 'zaqa-badge-danger'">
                    {{ c.is_active ? 'active' : 'disabled' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-text-primary">{{ c.tokens_count }}</td>
                <td class="px-5 py-3 text-right">
                  <Link :href="`/admin/integrations/institution-api-clients/${c.id}`" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs">Manage</Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

