<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Users } from 'lucide-vue-next'

const props = defineProps<{
  department: { id: number; name: string; code: string | null; description: string | null; is_active: boolean; sort_order: number }
}>()

const form = useForm({
  name: props.department.name,
  code: props.department.code ?? '',
  description: props.department.description ?? '',
  is_active: props.department.is_active,
  sort_order: props.department.sort_order,
})

function submit() {
  form.put(`/admin/settings/departments/${props.department.id}`, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="2xl">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <Users class="h-4 w-4" aria-hidden="true" />
              System Settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Edit department</h1>
            <p class="mt-1 text-sm text-text-muted">Update department details.</p>
          </div>
          <div class="flex items-center gap-2">
            <Link href="/admin/settings/departments" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
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

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Code (optional)</label>
              <input v-model="form.code" class="zaqa-input font-mono uppercase" autocomplete="off" />
              <div v-if="form.errors.code" class="mt-1 text-xs text-danger">{{ form.errors.code }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Sort order</label>
              <input v-model.number="form.sort_order" class="zaqa-input" type="number" min="0" />
              <div v-if="form.errors.sort_order" class="mt-1 text-xs text-danger">{{ form.errors.sort_order }}</div>
            </div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Description (optional)</label>
            <textarea v-model="form.description" class="zaqa-input h-auto min-h-[6rem] py-3" />
            <div v-if="form.errors.description" class="mt-1 text-xs text-danger">{{ form.errors.description }}</div>
          </div>

          <label class="flex items-center gap-2 text-sm font-semibold text-text-primary">
            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-border" />
            Active
          </label>
          <div v-if="form.errors.is_active" class="text-xs text-danger">{{ form.errors.is_active }}</div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">Save changes</button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>

