<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { FileSpreadsheet, UploadCloud } from 'lucide-vue-next'

const props = defineProps<{
  imports: any
  institutions: Array<{ id: number; name: string }>
  can: { import: boolean }
}>()

const form = useForm<{ awarding_institution_id: number | '' | null; file: File | null }>({
  awarding_institution_id: '',
  file: null,
})

function pickFile(e: Event) {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  form.file = f ?? null
}

function submit() {
  if (!props.can.import || !form.file) return
  form.post('/admin/learner-records/imports', {
    forceFormData: true,
    preserveScroll: true,
  })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
          Learner Records
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Imports</h1>
        <p class="mt-1 text-sm text-text-muted">Upload the HE Learner Records template and process it asynchronously.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/learner-records" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Records</Link>
      </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
      <div class="rounded-2xl border border-border bg-surface p-5 lg:col-span-1">
        <div class="text-sm font-semibold text-text-primary">Upload import</div>
        <p class="mt-1 text-xs text-text-muted">Processing happens in the queue. You can leave this page after upload.</p>

        <div class="mt-4 space-y-3">
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Awarding institution (optional)</label>
            <select v-model="form.awarding_institution_id" class="zaqa-input mt-2 h-10" :disabled="!can.import">
              <option value="">Auto-detect from file</option>
              <option v-for="i in institutions" :key="i.id" :value="i.id">{{ i.name }}</option>
            </select>
            <p v-if="form.errors.awarding_institution_id" class="mt-2 text-xs text-danger">{{ form.errors.awarding_institution_id }}</p>
          </div>

          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Spreadsheet file</label>
            <input
              type="file"
              accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
              class="mt-2 block w-full text-sm text-text-primary file:mr-3 file:rounded-lg file:border file:border-border file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-semibold"
              :disabled="!can.import"
              @change="pickFile"
            />
            <p v-if="form.errors.file" class="mt-2 text-xs text-danger">{{ form.errors.file }}</p>
          </div>

          <button
            type="button"
            class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50"
            :disabled="!can.import || !form.file || form.processing"
            @click="submit"
          >
            <UploadCloud class="h-4 w-4" aria-hidden="true" />
            {{ form.processing ? 'Uploading…' : 'Upload & queue import' }}
          </button>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm lg:col-span-2">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Import history</div>
          <div class="mt-1 text-xs text-text-muted">Newest first.</div>
        </div>

        <div v-if="imports.data.length === 0" class="px-5 py-6">
          <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
            <div class="text-sm font-semibold text-text-primary">No imports yet</div>
            <div class="mt-1 text-xs text-text-muted">Upload a spreadsheet to begin.</div>
          </div>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
              <tr>
                <th class="px-5 py-3 text-left">File</th>
                <th class="px-5 py-3 text-left">Institution</th>
                <th class="px-5 py-3 text-left">Status</th>
                <th class="px-5 py-3 text-left">Progress</th>
                <th class="px-5 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="i in imports.data" :key="i.id" class="hover:bg-surface-muted/60">
                <td class="px-5 py-3">
                  <div class="font-semibold text-text-primary">{{ i.original_filename }}</div>
                  <div class="mt-0.5 text-xs text-text-muted">Uploaded: {{ i.created_at }}</div>
                </td>
                <td class="px-5 py-3 text-text-primary">{{ i.awarding_institution?.name || '—' }}</td>
                <td class="px-5 py-3">
                  <span class="zaqa-badge" :class="i.status === 'completed' ? 'zaqa-badge-success' : i.status === 'failed' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'">
                    {{ i.status }}
                  </span>
                </td>
                <td class="px-5 py-3 text-text-primary">
                  <div class="text-xs">
                    {{ i.processed_rows ?? 0 }}/{{ i.total_rows ?? '—' }} · +{{ i.inserted_rows ?? 0 }} / ~{{ i.updated_rows ?? 0 }} · failed {{ i.failed_rows ?? 0 }}
                  </div>
                </td>
                <td class="px-5 py-3 text-right">
                  <Link :href="`/admin/learner-records/imports/${i.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    View
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

