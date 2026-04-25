<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Coins } from 'lucide-vue-next'
import { formatMoneyFromCents } from '@/utils/money'

const props = defineProps<{ fee: any }>()

const form = useForm({
  is_active: !!props.fee.is_active,
  effective_to: props.fee.effective_to ?? '',
  change_reason: props.fee.change_reason ?? '',
})

function submit() {
  form.put(`/admin/settings/fees/${props.fee.id}`, { preserveScroll: true })
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
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Edit fee structure</h1>
            <p class="mt-1 text-sm text-text-muted">Fee amounts are historically sensitive; this screen manages retirement and metadata.</p>
          </div>
          <div class="flex items-center gap-2">
            <Link href="/admin/settings/fees" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="rounded-xl border border-border bg-surface-muted p-4 text-sm">
          <div class="font-semibold text-text-primary">{{ fee.billing_category?.name ?? '—' }}</div>
          <div class="mt-1 text-xs text-text-muted">
            Local {{ formatMoneyFromCents(fee.local_fee_cents, fee.currency) }} • Foreign {{ formatMoneyFromCents(fee.foreign_fee_cents, fee.currency) }} • {{ fee.currency }}
          </div>
          <div class="mt-1 text-xs text-text-muted">Effective from {{ fee.effective_from }} to {{ fee.effective_to ?? '—' }}</div>
        </div>

        <form class="mt-5 space-y-5" @submit.prevent="submit">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-border" />
              Active
            </label>
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

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">Save changes</button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>

