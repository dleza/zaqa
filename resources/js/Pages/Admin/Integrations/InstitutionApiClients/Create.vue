<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { KeyRound } from 'lucide-vue-next'
import SingleSelectCombobox from '@/Components/SingleSelectCombobox.vue'

const props = defineProps<{
  institutions: Array<{ id: number; name: string }>
  abilities: string[]
}>()

const form = useForm<{
  awarding_institution_id: number | ''
  name: string
  contact_name: string
  contact_email: string
  is_active: boolean
  scopes: string[]
  notes: string
}>({
  awarding_institution_id: '',
  name: '',
  contact_name: '',
  contact_email: '',
  is_active: true,
  scopes: [...props.abilities],
  notes: '',
})

function toggleScope(scope: string) {
  const idx = form.scopes.indexOf(scope)
  if (idx >= 0) form.scopes.splice(idx, 1)
  else form.scopes.push(scope)
}

function submit() {
  form.post('/admin/integrations/institution-api-clients', { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <KeyRound class="h-4 w-4" aria-hidden="true" />
          Integrations
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">New Institution API Client</h1>
        <p class="mt-1 text-sm text-text-muted">Creates an institution-scoped client that can receive bearer tokens.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/integrations/institution-api-clients" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
      </div>
    </div>

    <div class="mt-6 max-w-3xl mx-auto rounded-2xl border border-border bg-surface p-6">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <SingleSelectCombobox
            v-model="form.awarding_institution_id"
            label="Awarding institution"
            placeholder="Select…"
            :options="institutions.map((i) => ({ id: i.id, label: i.name }))"
            :error="form.errors.awarding_institution_id"
          />
        </div>

        <div class="sm:col-span-2">
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Client name</label>
          <input v-model="form.name" class="zaqa-input mt-2 h-10" placeholder="e.g. UNZA Integration" />
          <p v-if="form.errors.name" class="mt-2 text-xs text-danger">{{ form.errors.name }}</p>
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Contact name (optional)</label>
          <input v-model="form.contact_name" class="zaqa-input mt-2 h-10" placeholder="e.g. ICT Officer" />
          <p v-if="form.errors.contact_name" class="mt-2 text-xs text-danger">{{ form.errors.contact_name }}</p>
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Contact email (optional)</label>
          <input v-model="form.contact_email" class="zaqa-input mt-2 h-10" placeholder="e.g. integration@institution.edu.zm" />
          <p v-if="form.errors.contact_email" class="mt-2 text-xs text-danger">{{ form.errors.contact_email }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="inline-flex items-center gap-2 text-sm text-text-primary">
            <input type="checkbox" v-model="form.is_active" />
            Active
          </label>
          <p v-if="form.errors.is_active" class="mt-2 text-xs text-danger">{{ form.errors.is_active }}</p>
        </div>

        <div class="sm:col-span-2">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Allowed scopes / abilities</div>
          <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <label v-for="a in abilities" :key="a" class="inline-flex items-center gap-2 rounded-xl border border-border bg-surface-muted px-3 py-2 text-sm">
              <input type="checkbox" :checked="form.scopes.includes(a)" @change="toggleScope(a)" />
              <span class="font-semibold text-text-primary">{{ a }}</span>
            </label>
          </div>
          <p v-if="form.errors.scopes" class="mt-2 text-xs text-danger">{{ form.errors.scopes }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Notes (optional)</label>
          <textarea v-model="form.notes" rows="4" class="zaqa-input mt-2"></textarea>
          <p v-if="form.errors.notes" class="mt-2 text-xs text-danger">{{ form.errors.notes }}</p>
        </div>
      </div>

      <div class="mt-6 flex flex-wrap items-center gap-2">
        <button type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm font-semibold disabled:opacity-50" :disabled="form.processing" @click="submit">
          {{ form.processing ? 'Saving…' : 'Create client' }}
        </button>
        <Link href="/admin/integrations/institution-api-clients" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Cancel</Link>
      </div>
    </div>
  </AdminLayout>
</template>
