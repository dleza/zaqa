<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Coins } from 'lucide-vue-next'

const props = defineProps<{
  billing_categories: Array<{ id: number; name: string }>
}>()

const form = useForm({
  billing_category_id: props.billing_categories?.[0]?.id ?? null,
  local_fee: '' as string,
  foreign_fee: '' as string,
  currency: 'ZMW',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
  is_active: true,
  change_reason: '',
})

function submit() {
  form.post('/admin/settings/fees', { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="3xl">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <Coins class="h-4 w-4" aria-hidden="true" />
              System Settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">New fee version</h1>
            <p class="mt-1 text-sm text-text-muted">Create an effective-dated fee structure. Existing invoices remain unchanged.</p>
          </div>
          <div class="flex items-center gap-2">
            <Link href="/admin/settings/fees" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <form class="space-y-5" @submit.prevent="submit">
          <div>
            <label class="text-sm font-semibold text-text-primary">Billing category</label>
            <select v-model="form.billing_category_id" class="zaqa-input">
              <option v-for="c in billing_categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <div v-if="form.errors.billing_category_id" class="mt-1 text-xs text-danger">{{ form.errors.billing_category_id }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Local fee (ZMW)</label>
              <input v-model="form.local_fee" class="zaqa-input" inputmode="decimal" placeholder="e.g. 50.00" />
              <div class="mt-1 text-xs text-text-muted">Enter the amount in ZMW (no cents/ngwee).</div>
              <div v-if="form.errors.local_fee" class="mt-1 text-xs text-danger">{{ form.errors.local_fee }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Foreign fee (ZMW)</label>
              <input v-model="form.foreign_fee" class="zaqa-input" inputmode="decimal" placeholder="e.g. 1200.50" />
              <div class="mt-1 text-xs text-text-muted">Enter the amount in ZMW (no cents/ngwee).</div>
              <div v-if="form.errors.foreign_fee" class="mt-1 text-xs text-danger">{{ form.errors.foreign_fee }}</div>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="text-sm font-semibold text-text-primary">Currency</label>
              <input v-model="form.currency" class="zaqa-input font-mono uppercase" maxlength="3" />
              <div v-if="form.errors.currency" class="mt-1 text-xs text-danger">{{ form.errors.currency }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Effective from</label>
              <input v-model="form.effective_from" class="zaqa-input" type="date" />
              <div v-if="form.errors.effective_from" class="mt-1 text-xs text-danger">{{ form.errors.effective_from }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Effective to (optional)</label>
              <input v-model="form.effective_to" class="zaqa-input" type="date" />
              <div v-if="form.errors.effective_to" class="mt-1 text-xs text-danger">{{ form.errors.effective_to }}</div>
            </div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Change reason (optional)</label>
            <textarea v-model="form.change_reason" class="zaqa-input h-auto min-h-[6rem] py-3" />
            <div v-if="form.errors.change_reason" class="mt-1 text-xs text-danger">{{ form.errors.change_reason }}</div>
          </div>

          <label class="flex items-center gap-2 text-sm font-semibold text-text-primary">
            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-border" />
            Active
          </label>
          <div v-if="form.errors.is_active" class="text-xs text-danger">{{ form.errors.is_active }}</div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">Save</button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>

