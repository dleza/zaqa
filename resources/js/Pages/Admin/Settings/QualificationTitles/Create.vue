<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { GraduationCap } from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  qualificationTypes: Array<{ id: number; name: string; zqf_level_code: string }>
  awardingInstitutions: Array<{ id: number; name: string; country?: string | null }>
}>()

const institutionFilter = ref('')

const filteredInstitutions = computed(() => {
  const q = institutionFilter.value.trim().toLowerCase()
  if (!q) return props.awardingInstitutions
  return props.awardingInstitutions.filter((i) => {
    const hay = `${i.name} ${i.country ?? ''}`.toLowerCase()
    return hay.includes(q)
  })
})

const form = useForm({
  name: '',
  qualification_type_id: '' as number | string | '',
  description: '',
  is_active: true,
  sort_order: 0,
  awarding_institution_ids: [] as number[],
})

function toggleInstitution(id: number, checked: boolean) {
  const set = new Set(form.awarding_institution_ids)
  if (checked) set.add(id)
  else set.delete(id)
  form.awarding_institution_ids = Array.from(set)
}

function submit() {
  form.post('/admin/settings/qualification-titles', { preserveScroll: true })
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
              System settings
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Add qualification title</h1>
            <p class="mt-1 text-sm text-text-muted">Applicants can select this title when adding a qualification.</p>
          </div>
          <Link href="/admin/settings/qualification-titles" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
        </div>
      </template>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <form class="space-y-5" @submit.prevent="submit">
          <div>
            <label class="text-sm font-semibold text-text-primary">Title</label>
            <input v-model="form.name" class="zaqa-input" autocomplete="off" />
            <div v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="text-sm font-semibold text-text-primary">Qualification type (optional)</label>
              <select v-model="form.qualification_type_id" class="zaqa-input">
                <option value="">None</option>
                <option v-for="t in qualificationTypes" :key="t.id" :value="t.id">{{ t.name }} ({{ t.zqf_level_code }})</option>
              </select>
              <div v-if="form.errors.qualification_type_id" class="mt-1 text-xs text-danger">{{ form.errors.qualification_type_id }}</div>
            </div>
            <div>
              <label class="text-sm font-semibold text-text-primary">Sort order</label>
              <input v-model.number="form.sort_order" class="zaqa-input" type="number" min="0" />
            </div>
          </div>

          <div>
            <label class="text-sm font-semibold text-text-primary">Description (optional)</label>
            <textarea v-model="form.description" class="zaqa-input min-h-[88px]" />
          </div>

          <label class="flex items-center gap-2 text-sm font-semibold text-text-primary">
            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-border" />
            Active
          </label>

          <div class="rounded-xl border border-border bg-surface-muted/40 p-4">
            <div class="text-sm font-semibold text-text-primary">Linked awarding institutions (optional)</div>
            <p class="mt-1 text-xs text-text-muted">
              If linked, applicants only see this title for those institutions. If none are linked, the title is available globally.
            </p>
            <input v-model="institutionFilter" class="zaqa-input mt-3" placeholder="Filter institutions..." />
            <div class="mt-3 max-h-56 space-y-2 overflow-auto">
              <label
                v-for="inst in filteredInstitutions"
                :key="inst.id"
                class="flex items-start gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-sm"
              >
                <input
                  type="checkbox"
                  class="mt-0.5 h-4 w-4 rounded border-border"
                  :checked="form.awarding_institution_ids.includes(inst.id)"
                  @change="toggleInstitution(inst.id, ($event.target as HTMLInputElement).checked)"
                />
                <span>
                  <span class="font-semibold text-text-primary">{{ inst.name }}</span>
                  <span v-if="inst.country" class="mt-0.5 block text-xs text-text-muted">{{ inst.country }}</span>
                </span>
              </label>
            </div>
          </div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">Save</button>
          </div>
        </form>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>
