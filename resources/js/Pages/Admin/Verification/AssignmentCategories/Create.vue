<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Save, ShieldCheck } from 'lucide-vue-next'
import { computed, watch } from 'vue'
import MultiSelectCombobox from '@/Components/MultiSelectCombobox.vue'

const props = defineProps<{
  countries: Array<{ id: number; name: string; iso_code: string }>
  institutions: Array<{ id: number; name: string; is_active: boolean }>
}>()

const form = useForm({
  type: 'foreign_country',
  countries: [] as number[],
  awarding_institutions: [] as number[],
  name: '',
  is_active: true,
})

const showCountry = computed(() => form.type === 'foreign_country')
const showInstitution = computed(() => form.type === 'local_institution')

watch(
  () => form.type,
  (t) => {
    if (t === 'foreign_country') {
      form.awarding_institutions = []
    } else {
      form.countries = []
    }
  },
)

function submit() {
  form.post('/admin/verification/assignment-categories', { preserveScroll: true })
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
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">New Assignment Category</h1>
        <p class="mt-1 text-sm text-text-muted">Configure routing for Level 1 auto-assignment.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/assignment-categories" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
      </div>
    </div>

    <div class="mt-6 max-w-3xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
      <form class="space-y-5" @submit.prevent="submit">
        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Type</label>
          <select v-model="form.type" class="zaqa-input mt-2 h-10">
            <option value="foreign_country">Foreign (Country of award)</option>
            <option value="local_institution">Local (Awarding institution)</option>
          </select>
          <div v-if="form.errors.type" class="mt-1 text-xs text-danger">{{ form.errors.type }}</div>
        </div>

        <div v-if="showCountry">
          <MultiSelectCombobox
            v-model="form.countries"
            label="Countries"
            placeholder="Select one or more countries…"
            :options="countries.map((c) => ({ id: c.id, label: `${c.name} (${c.iso_code})` }))"
            :error="form.errors.countries"
            help-text="One category can cover multiple countries. Countries cannot overlap across active foreign categories."
          />
        </div>

        <div v-if="showInstitution">
          <MultiSelectCombobox
            v-model="form.awarding_institutions"
            label="Awarding institutions"
            placeholder="Select one or more institutions…"
            :options="institutions.map((i) => ({ id: i.id, label: `${i.name}${i.is_active ? '' : ' (inactive)'}` }))"
            :error="form.errors.awarding_institutions"
            help-text="One category can cover multiple institutions. Institutions cannot overlap across active local categories."
          />
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Category name</label>
          <input v-model="form.name" class="zaqa-input mt-2 h-10" placeholder="e.g. Southern Africa (Foreign) / Public Universities (Local)" />
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
            {{ form.processing ? 'Saving…' : 'Create category' }}
          </button>
        </div>
      </form>
    </div>
  </AdminLayout>
</template>
