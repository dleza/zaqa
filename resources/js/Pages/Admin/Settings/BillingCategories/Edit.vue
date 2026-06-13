<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Tags } from 'lucide-vue-next'

const props = defineProps<{
  category: {
    id: number
    name: string
    code: string
    description: string | null
    local_processing_days: number | null
    foreign_processing_days: number | null
    is_active: boolean
    is_system: boolean
    sort_order: number
  }
}>()

const form = useForm({
  name: props.category.name,
  description: props.category.description ?? '',
  local_processing_days: props.category.local_processing_days,
  foreign_processing_days: props.category.foreign_processing_days,
  is_active: props.category.is_active,
  sort_order: props.category.sort_order,
})

function submit() {
  form.put(`/admin/settings/billing-categories/${props.category.id}`, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="3xl">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <Tags class="h-4 w-4" aria-hidden="true" />
              System Settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Edit billing category</h1>
            <p class="mt-1 text-sm text-text-muted">Rename or adjust processing times. The code is fixed after creation.</p>
          </div>
          <div class="flex items-center gap-2">
            <Link href="/admin/settings/billing-categories" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <form class="space-y-5" @submit.prevent="submit">
          <div>
            <label class="text-sm font-semibold text-text-primary">Name</label>
            <input v-model="form.name" class="zaqa-input" autocomplete="off" />
            <div v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Code</label>
            <input :value="category.code" class="zaqa-input font-mono uppercase bg-surface-muted" readonly disabled />
            <div class="mt-1 text-xs text-text-muted">Code cannot be changed.</div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Description (optional)</label>
            <textarea v-model="form.description" class="zaqa-input h-auto min-h-[5rem] py-3" />
            <div v-if="form.errors.description" class="mt-1 text-xs text-danger">{{ form.errors.description }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Local processing days</label>
              <input v-model.number="form.local_processing_days" class="zaqa-input" type="number" min="0" />
              <div v-if="form.errors.local_processing_days" class="mt-1 text-xs text-danger">{{ form.errors.local_processing_days }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Foreign processing days</label>
              <input v-model.number="form.foreign_processing_days" class="zaqa-input" type="number" min="0" />
              <div v-if="form.errors.foreign_processing_days" class="mt-1 text-xs text-danger">{{ form.errors.foreign_processing_days }}</div>
            </div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Sort order</label>
            <input v-model.number="form.sort_order" class="zaqa-input" type="number" min="0" />
            <div v-if="form.errors.sort_order" class="mt-1 text-xs text-danger">{{ form.errors.sort_order }}</div>
          </div>

          <label class="flex items-center gap-2 text-sm font-semibold text-text-primary" :class="{ 'opacity-60': category.is_system }">
            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-border" :disabled="category.is_system" />
            Active
          </label>
          <div v-if="category.is_system" class="text-xs text-text-muted">This system category must remain active for foreign qualification billing.</div>
          <div v-if="form.errors.is_active" class="text-xs text-danger">{{ form.errors.is_active }}</div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">Save changes</button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>
