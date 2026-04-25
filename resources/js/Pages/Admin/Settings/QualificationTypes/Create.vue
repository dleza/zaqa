<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { GraduationCap } from 'lucide-vue-next'

const props = defineProps<{
  billing_categories: Array<{ id: number; name: string }>
}>()

const form = useForm({
  zqf_level_code: '',
  level_label: '',
  name: '',
  short_name: '',
  description: '',
  billing_category_id: props.billing_categories?.[0]?.id ?? null,
  requires_subject_results: false,
  is_active: true,
  sort_order: 0,
})

function submit() {
  form.post('/admin/settings/qualification-types', { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="3xl">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <GraduationCap class="h-4 w-4" aria-hidden="true" />
              System Settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Add qualification type</h1>
            <p class="mt-1 text-sm text-text-muted">Create a new qualification type and map to a billing category.</p>
          </div>
          <div class="flex items-center gap-2">
            <Link href="/admin/settings/qualification-types" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <form class="space-y-5" @submit.prevent="submit">
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Level code</label>
              <input v-model="form.zqf_level_code" class="zaqa-input font-mono uppercase" autocomplete="off" />
              <div v-if="form.errors.zqf_level_code" class="mt-1 text-xs text-danger">{{ form.errors.zqf_level_code }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Level label</label>
              <input v-model="form.level_label" class="zaqa-input" autocomplete="off" />
              <div v-if="form.errors.level_label" class="mt-1 text-xs text-danger">{{ form.errors.level_label }}</div>
            </div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Name</label>
            <input v-model="form.name" class="zaqa-input" autocomplete="off" />
            <div v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Short name (optional)</label>
              <input v-model="form.short_name" class="zaqa-input" autocomplete="off" />
              <div v-if="form.errors.short_name" class="mt-1 text-xs text-danger">{{ form.errors.short_name }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Billing category</label>
              <select v-model="form.billing_category_id" class="zaqa-input">
                <option v-for="c in billing_categories" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <div v-if="form.errors.billing_category_id" class="mt-1 text-xs text-danger">{{ form.errors.billing_category_id }}</div>
            </div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Description (optional)</label>
            <textarea v-model="form.description" class="zaqa-input h-auto min-h-[6rem] py-3" />
            <div v-if="form.errors.description" class="mt-1 text-xs text-danger">{{ form.errors.description }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <input v-model="form.requires_subject_results" type="checkbox" class="h-4 w-4 rounded border-border" />
              Subject results required
            </label>
            <label class="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-border" />
              Active
            </label>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Sort order</label>
            <input v-model.number="form.sort_order" class="zaqa-input" type="number" min="0" />
            <div v-if="form.errors.sort_order" class="mt-1 text-xs text-danger">{{ form.errors.sort_order }}</div>
          </div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">Save</button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>

