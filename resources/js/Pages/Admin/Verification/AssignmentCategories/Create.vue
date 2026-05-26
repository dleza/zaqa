<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Save, ShieldCheck } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
  countries: Array<{ id: number; name: string; iso_code: string }>
  institutions: Array<{ id: number; name: string; is_active: boolean }>
}>()

const form = useForm({
  type: 'foreign_country',
  country_id: '' as any,
  awarding_institution_id: '' as any,
  name: '',
  is_active: true,
})

const showCountry = computed(() => form.type === 'foreign_country')
const showInstitution = computed(() => form.type === 'local_institution')

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
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Country</label>
          <select v-model="form.country_id" class="zaqa-input mt-2 h-10">
            <option value="" disabled>Select country…</option>
            <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }} ({{ c.iso_code }})</option>
          </select>
          <div v-if="form.errors.country_id" class="mt-1 text-xs text-danger">{{ form.errors.country_id }}</div>
        </div>

        <div v-if="showInstitution">
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Awarding institution</label>
          <select v-model="form.awarding_institution_id" class="zaqa-input mt-2 h-10">
            <option value="" disabled>Select institution…</option>
            <option v-for="i in institutions" :key="i.id" :value="i.id">{{ i.name }}{{ i.is_active ? '' : ' (inactive)' }}</option>
          </select>
          <div v-if="form.errors.awarding_institution_id" class="mt-1 text-xs text-danger">{{ form.errors.awarding_institution_id }}</div>
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Category name (optional)</label>
          <input v-model="form.name" class="zaqa-input mt-2 h-10" placeholder="Defaults to the selected country/institution name" />
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

