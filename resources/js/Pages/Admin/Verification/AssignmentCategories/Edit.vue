<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Save, ShieldCheck } from 'lucide-vue-next'
import { computed } from 'vue'
import MultiSelectCombobox from '@/Components/MultiSelectCombobox.vue'

const props = defineProps<{
  category: { id: number; name: string; type: string; is_active: boolean; country_ids: number[]; awarding_institution_ids: number[] }
  countries: Array<{ id: number; name: string; iso_code: string }>
  institutions: Array<{ id: number; name: string; is_active: boolean }>
}>()

const form = useForm({
  _method: 'put',
  name: props.category.name,
  is_active: props.category.is_active,
  countries: props.category.country_ids ?? ([] as number[]),
  awarding_institutions: props.category.awarding_institution_ids ?? ([] as number[]),
})

const isForeign = computed(() => props.category.type === 'foreign_country')

if (isForeign.value) {
  form.awarding_institutions = []
} else {
  form.countries = []
}

function submit() {
  form.post(`/admin/verification/assignment-categories/${props.category.id}`, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <ShieldCheck class="h-4 w-4" aria-hidden="true" />
          Verification
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Edit Assignment Category</h1>
        <p class="mt-1 text-sm text-text-muted">Update category name and active state.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link :href="`/admin/verification/assignment-categories/${category.id}`" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
      </div>
    </div>

    <div class="mt-6 max-w-3xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-border bg-surface-muted p-4 sm:col-span-2">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Type</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">
            {{ category.type === 'foreign_country' ? 'Foreign (Country)' : 'Local (Institution)' }}
          </div>
        </div>
      </div>

      <form class="mt-6 space-y-5" @submit.prevent="submit">
        <div v-if="isForeign">
          <MultiSelectCombobox
            v-model="form.countries"
            label="Countries"
            placeholder="Select one or more countries…"
            :options="countries.map((c) => ({ id: c.id, label: `${c.name} (${c.iso_code})` }))"
            :error="form.errors.countries"
          />
        </div>

        <div v-else>
          <MultiSelectCombobox
            v-model="form.awarding_institutions"
            label="Awarding institutions"
            placeholder="Select one or more institutions…"
            :options="institutions.map((i) => ({ id: i.id, label: `${i.name}${i.is_active ? '' : ' (inactive)'}` }))"
            :error="form.errors.awarding_institutions"
          />
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Name</label>
          <input v-model="form.name" class="zaqa-input mt-2 h-10" />
          <div v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</div>
        </div>

        <div>
          <label class="inline-flex items-center gap-2 text-sm text-text-primary">
            <input type="checkbox" v-model="form.is_active" />
            Active
          </label>
          <div v-if="form.errors.is_active" class="mt-1 text-xs text-danger">{{ form.errors.is_active }}</div>
        </div>

        <div class="pt-2">
          <button type="submit" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold" :disabled="form.processing">
            <Save class="h-4 w-4" aria-hidden="true" />
            {{ form.processing ? 'Saving…' : 'Save changes' }}
          </button>
        </div>
      </form>
    </div>
  </AdminLayout>
</template>
