<script setup lang="ts">
import AdminActionModal from '@/Components/AdminActionModal.vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import SingleSelectCombobox from '@/Components/SingleSelectCombobox.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { FileSpreadsheet, UploadCloud } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  imports: any
  institutions: Array<{ id: number; name: string }>
  can: { import: boolean }
  template_url: string
}>()

const importModalOpen = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const form = useForm<{ awarding_institution_id: number | '' | null; file: File | null }>({
  awarding_institution_id: '',
  file: null,
})

watch(importModalOpen, (open) => {
  if (!open) {
    form.reset()
    form.clearErrors()
    if (fileInput.value) fileInput.value.value = ''
  }
})

function pickFile(e: Event) {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  form.file = f ?? null
}

function submit() {
  if (!props.can.import || !form.file || !form.awarding_institution_id) return

  form.post('/admin/learner-records/imports', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      importModalOpen.value = false
    },
  })
}

function formatDate(iso: string | null | undefined) {
  if (!iso) return '—'

  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function statusBadgeClass(status: string | null | undefined) {
  if (status === 'completed') return 'zaqa-badge-success'
  if (status === 'failed') return 'zaqa-badge-danger'
  if (status === 'completed_with_errors') return 'zaqa-badge-warning'
  return 'zaqa-badge-secondary'
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
        <p class="mt-1 text-sm text-text-muted">Queue learner-record spreadsheets and track each import run from one place.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-if="can.import"
          type="button"
          class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold"
          @click="importModalOpen = true"
        >
          <UploadCloud class="h-4 w-4" aria-hidden="true" />
          Upload import file
        </button>
        <Link href="/admin/learner-records" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Records</Link>
      </div>
    </div>

    <AdminActionModal
      v-model="importModalOpen"
      title="Upload learner records import"
      description="Processing runs asynchronously in the queue. You can leave this page after submitting the file."
      max-width-class="max-w-3xl"
    >
      <div class="space-y-4">
        <div class="rounded-2xl border border-border bg-surface-muted/60 p-4 text-sm text-text-muted">
          Select one awarding institution per upload. Download the template, fill one row per learner record, then upload
          the completed spreadsheet in `.xlsx`, `.xls`, or `.csv` format. The optional `Classification` column stores
          award/result classifications such as Credit, Merit, Distinction, or Pass. It is not used for auto-verification scoring.
        </div>

        <div class="flex flex-wrap gap-2">
          <a
            :href="template_url"
            class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold"
            download
          >
            <FileSpreadsheet class="h-4 w-4 shrink-0" aria-hidden="true" />
            Download template
          </a>
        </div>

        <SingleSelectCombobox
          v-model="form.awarding_institution_id"
          label="Awarding institution"
          placeholder="Select institution for this upload"
          :options="institutions.map((i) => ({ id: i.id, label: i.name }))"
          :disabled="!can.import || form.processing"
          :error="form.errors.awarding_institution_id"
          help-text="All rows in the uploaded file will be linked to this institution."
        />

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Spreadsheet file</label>
          <input
            ref="fileInput"
            type="file"
            accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
            class="mt-2 block w-full text-sm text-text-primary file:mr-3 file:rounded-lg file:border file:border-border file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-semibold"
            :disabled="!can.import || form.processing"
            @change="pickFile"
          />
          <p v-if="form.errors.file" class="mt-2 text-xs text-danger">{{ form.errors.file }}</p>
        </div>
      </div>

      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="importModalOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50"
          :disabled="!can.import || !form.file || !form.awarding_institution_id || form.processing"
          @click="submit"
        >
          <UploadCloud class="h-4 w-4" aria-hidden="true" />
          {{ form.processing ? 'Uploading…' : 'Upload & queue import' }}
        </button>
      </template>
    </AdminActionModal>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Import history</div>
            <div class="mt-1 text-xs text-text-muted">Newest uploads first, including processing progress and row counts.</div>
          </div>
          <div class="text-xs text-text-muted">
            {{ imports.total ?? imports.data.length }} import{{ (imports.total ?? imports.data.length) === 1 ? '' : 's' }}
          </div>
        </div>
      </div>

      <div v-if="imports.data.length === 0" class="px-5 py-8">
        <div class="rounded-2xl border border-border bg-surface-muted p-8 text-center">
          <div class="text-sm font-semibold text-text-primary">No imports yet</div>
          <div class="mt-1 text-xs text-text-muted">Use “Upload import file” to queue the first spreadsheet.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">File</th>
              <th class="px-5 py-3 text-left">Institution</th>
              <th class="px-5 py-3 text-left">Uploaded by</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Progress</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="i in imports.data" :key="i.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ i.original_filename }}</div>
                <div class="mt-0.5 text-xs text-text-muted">Uploaded: {{ formatDate(i.created_at) }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ i.awarding_institution?.name || '—' }}</td>
              <td class="px-5 py-3">
                <div class="text-text-primary">{{ i.uploaded_by?.name || '—' }}</div>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="statusBadgeClass(i.status)">
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

      <AdminTablePagination :paginator="imports" label="imports" />
    </div>
  </AdminLayout>
</template>
