<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { BookOpen } from 'lucide-vue-next'

const form = useForm({
  name: '',
  is_active: true,
  sort_order: 0,
})

function submit() {
  form.post('/admin/settings/certificate-subjects', { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="2xl">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <BookOpen class="h-4 w-4" aria-hidden="true" />
              Reference data · System settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Add subject</h1>
            <p class="mt-1 text-sm text-text-muted">Appears in applicant dropdowns for school-certificate qualifications.</p>
          </div>
          <div class="flex items-center gap-2">
            <Link href="/admin/settings/certificate-subjects" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <form class="space-y-5" @submit.prevent="submit">
          <div>
            <label class="text-sm font-semibold text-text-primary">Subject name</label>
            <input v-model="form.name" class="zaqa-input" autocomplete="off" />
            <div v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Sort order</label>
              <input v-model.number="form.sort_order" class="zaqa-input" type="number" min="0" />
              <div v-if="form.errors.sort_order" class="mt-1 text-xs text-danger">{{ form.errors.sort_order }}</div>
            </div>
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
