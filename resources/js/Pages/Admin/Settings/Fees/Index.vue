<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import AdminViewModal from '@/Components/AdminViewModal.vue'
import { Link, router } from '@inertiajs/vue3'
import { Coins, Plus } from 'lucide-vue-next'
import { ref, watch } from 'vue'
import { formatMoneyFromCents } from '@/utils/money'

const props = defineProps<{
  fees: any
  billing_categories: Array<{ id: number; name: string }>
  filters: { billing_category_id: string | null; active: string | null }
  can: { create: boolean; edit: boolean; delete: boolean }
}>()

const billingCategoryId = ref<string>(props.filters.billing_category_id ?? '')
const active = ref<string>(props.filters.active ?? '')
const viewOpen = ref(false)
const selected = ref<any | null>(null)

watch([billingCategoryId, active], () => {
  router.get(
    '/admin/settings/fees',
    { billing_category_id: billingCategoryId.value || null, active: active.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function retire(id: number) {
  if (!confirm('Retire this fee structure? It will remain for history but will be inactive.')) return
  router.delete(`/admin/settings/fees/${id}`, { preserveScroll: true })
}

function openView(f: any) {
  selected.value = f
  viewOpen.value = true
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Coins class="h-4 w-4" aria-hidden="true" />
          System Settings
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Fees</h1>
        <p class="mt-1 text-sm text-text-muted">Effective-dated fee structures per billing category.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link v-if="can.create" href="/admin/settings/fees/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
          <Plus class="h-4 w-4" aria-hidden="true" />
          New fee version
        </Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Fee structures</div>
            <div class="mt-1 text-xs text-text-muted">Filter by billing category and active state.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <select v-model="billingCategoryId" class="zaqa-input h-10">
              <option value="">All categories</option>
              <option v-for="c in billing_categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
            <select v-model="active" class="zaqa-input h-10">
              <option value="">All</option>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="fees.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No fee structures found</div>
          <div class="mt-1 text-xs text-text-muted">Create a fee version to get started.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Category</th>
              <th class="px-5 py-3 text-left">Local</th>
              <th class="px-5 py-3 text-left">Foreign</th>
              <th class="px-5 py-3 text-left">Effective</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="f in fees.data" :key="f.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ f.billing_category?.name ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ f.currency }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ formatMoneyFromCents(f.local_fee_cents, f.currency) }}</td>
              <td class="px-5 py-3 text-text-primary">{{ formatMoneyFromCents(f.foreign_fee_cents, f.currency) }}</td>
              <td class="px-5 py-3 text-text-primary">
                <div class="text-xs">From {{ f.effective_from }}</div>
                <div class="text-xs text-text-muted">To {{ f.effective_to ?? '—' }}</div>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="f.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ f.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs" @click="openView(f)">View</button>
                  <Link v-if="can.edit" :href="`/admin/settings/fees/${f.id}/edit`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    Edit
                  </Link>
                  <button v-if="can.delete && f.is_active" type="button" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs" @click="retire(f.id)">
                    Retire
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminTablePagination :paginator="fees" label="fee schedules" />
    </div>

    <AdminViewModal v-model="viewOpen" :title="selected ? `Fee: ${selected.billing_category?.name ?? '—'}` : 'Fee'" description="Quick view (read-only).">
      <div v-if="selected" class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-border bg-surface-muted p-4 sm:col-span-2">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Billing category</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.billing_category?.name ?? '—' }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Local fee</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ formatMoneyFromCents(selected.local_fee_cents, selected.currency) }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Foreign fee</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ formatMoneyFromCents(selected.foreign_fee_cents, selected.currency) }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Currency</div>
          <div class="mt-2 font-mono text-sm font-semibold text-text-primary">{{ selected.currency ?? '—' }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Effective</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">From {{ selected.effective_from }}</div>
          <div class="mt-1 text-xs text-text-muted">To {{ selected.effective_to ?? '—' }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4 sm:col-span-2">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Status</div>
          <div class="mt-2">
            <span class="zaqa-badge" :class="selected.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
              {{ selected.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <div v-if="selected.change_reason" class="mt-3 text-xs text-text-muted">
            Reason: <span class="text-text-primary">{{ selected.change_reason }}</span>
          </div>
        </div>
      </div>
    </AdminViewModal>
  </AdminLayout>
</template>

