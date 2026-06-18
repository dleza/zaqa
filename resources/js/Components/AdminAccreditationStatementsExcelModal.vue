<script setup lang="ts">
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { FileSpreadsheet } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    exportUrl: string
    importUrl: string
    canExport: boolean
    canImport: boolean
    maxWidthClass?: string
  }>(),
  { maxWidthClass: 'max-w-2xl' },
)

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
}>()

const fileInput = ref<HTMLInputElement | null>(null)
const missingOnly = ref(false)
const overwriteExisting = ref(false)

const form = useForm<{ file: File | null; overwrite_existing: boolean }>({
  file: null,
  overwrite_existing: false,
})

const open = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const downloadUrl = computed(() => {
  const base = props.exportUrl
  const sep = base.includes('?') ? '&' : '?'
  return `${base}${sep}missing_only=${missingOnly.value ? '1' : '0'}`
})

watch(open, (v) => {
  if (!v) {
    form.reset()
    form.clearErrors()
    missingOnly.value = false
    overwriteExisting.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
})

function pickFile(e: Event) {
  const input = e.target as HTMLInputElement
  form.file = input.files?.[0] ?? null
}

function submit() {
  if (!props.canImport || !form.file) return
  form.overwrite_existing = overwriteExisting.value
  form.post(props.importUrl, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      open.value = false
      form.reset()
      overwriteExisting.value = false
      if (fileInput.value) fileInput.value.value = ''
    },
  })
}
</script>

<template>
  <AdminActionModal
    v-model="open"
    title="Accreditation statements Excel"
    max-width-class="max-w-2xl"
    scrollable
  >
    <div class="space-y-6">
      <section class="space-y-3">
        <div class="text-sm font-semibold text-text-primary">Download</div>
        <p class="text-sm text-text-muted">
          Download the awarding institution list, fill in the accreditation statements, then upload the completed Excel file.
        </p>
        <label class="flex items-start gap-2 text-sm text-text-primary">
          <input v-model="missingOnly" type="checkbox" class="mt-1 h-4 w-4 rounded border-border" />
          <span>Only include institutions missing accreditation statements</span>
        </label>
        <div>
          <a
            v-if="canExport"
            :href="downloadUrl"
            class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold"
            download
          >
            <FileSpreadsheet class="h-4 w-4 shrink-0" aria-hidden="true" />
            Download Excel
          </a>
          <p v-else class="text-xs text-text-muted">You do not have permission to download the list.</p>
        </div>
      </section>

      <section class="space-y-3 border-t border-border pt-5">
        <div class="text-sm font-semibold text-text-primary">Upload</div>
        <p class="text-xs text-text-muted">Supported formats: .xlsx, .xls, .csv (max ~12&nbsp;MB).</p>
        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Excel file</label>
          <input
            ref="fileInput"
            type="file"
            accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
            class="mt-2 block w-full text-sm text-text-primary file:mr-3 file:rounded-lg file:border file:border-border file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-semibold"
            @change="pickFile"
          />
          <p v-if="form.errors.file" class="mt-2 text-xs text-danger">{{ form.errors.file }}</p>
        </div>
        <label class="flex items-start gap-2 text-sm text-text-primary">
          <input v-model="overwriteExisting" type="checkbox" class="mt-1 h-4 w-4 rounded border-border" />
          <span>Overwrite existing statements when the uploaded statement is different</span>
        </label>
      </section>
    </div>

    <template #footer>
      <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="open = false">Cancel</button>
      <button
        type="button"
        class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm font-semibold disabled:opacity-50"
        :disabled="!canImport || !form.file || form.processing"
        @click="submit"
      >
        {{ form.processing ? 'Uploading…' : 'Upload statements' }}
      </button>
    </template>
  </AdminActionModal>
</template>
