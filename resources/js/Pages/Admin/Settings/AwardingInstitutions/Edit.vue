<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Building2 } from 'lucide-vue-next'

const props = defineProps<{
  institution: { id: number; country_id: number; name: string; is_active: boolean; sort_order: number }
  countries: Array<{ id: number; name: string; iso_code: string }>
}>()

const form = useForm({
  country_id: props.institution.country_id,
  name: props.institution.name,
  is_active: props.institution.is_active,
  sort_order: props.institution.sort_order,
})

function submit() {
  form.put(`/admin/settings/awarding-institutions/${props.institution.id}`, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="2xl">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <Building2 class="h-4 w-4" aria-hidden="true" />
              System Settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Edit awarding institution</h1>
            <p class="mt-1 text-sm text-text-muted">Update institution details.</p>
          </div>
          <div class="flex items-center gap-2">
            <Link href="/admin/settings/awarding-institutions" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <form class="space-y-5" @submit.prevent="submit">
          <div>
            <label class="text-sm font-semibold text-text-primary">Country</label>
            <select v-model="form.country_id" class="zaqa-input">
              <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <div v-if="form.errors.country_id" class="mt-1 text-xs text-danger">{{ form.errors.country_id }}</div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Institution name</label>
            <input v-model="form.name" class="zaqa-input" autocomplete="off" />
            <div v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Sort order</label>
              <input v-model.number="form.sort_order" class="zaqa-input" type="number" min="0" />
              <div v-if="form.errors.sort_order" class="mt-1 text-xs text-danger">{{ form.errors.sort_order }}</div>
            </div>
            <div class="flex items-end">
              <label class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-border" />
                Active
              </label>
            </div>
          </div>
          <div v-if="form.errors.is_active" class="text-xs text-danger">{{ form.errors.is_active }}</div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">
              Save changes
            </button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>

