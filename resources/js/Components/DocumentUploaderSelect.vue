<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'
import { useUploadLimits } from '@/lib/uploadLimits'

const { pdfOrImageHint } = useUploadLimits()

type DocType = 'nrc_copy' | 'certificate_copy' | 'transcript'

const props = defineProps<{
  uploadUrl: string
  documents: Array<any>
  transcriptOptional?: boolean
}>()

const selectedType = ref<DocType>('nrc_copy')
const fileInput = ref<HTMLInputElement | null>(null)

const typeLabel = computed(() => {
  if (selectedType.value === 'nrc_copy') return 'NRC or Passport'
  if (selectedType.value === 'certificate_copy') return 'Qualification document'
  return 'Transcript'
})

const documentsOfType = computed(() =>
  props.documents
    .filter((d) => d.document_type === selectedType.value)
    .sort((a, b) => (b.version_number ?? 0) - (a.version_number ?? 0)),
)

const currentDocument = computed(() => documentsOfType.value.find((d) => d.is_current_version))

const form = useForm<{ document_type: string; file: File | null }>({
  document_type: selectedType.value,
  file: null,
})

function onFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  form.file = target.files && target.files.length > 0 ? target.files[0] : null
}

function upload() {
  form.document_type = selectedType.value
  form.post(props.uploadUrl, {
    forceFormData: true,
    onSuccess: () => {
      form.reset('file')
      if (fileInput.value) fileInput.value.value = ''
    },
  })
}
</script>

<template>
  <div class="rounded-xl border border-border bg-surface p-5">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0">
        <h3 class="text-sm font-semibold text-text-primary">Upload document</h3>
        <p class="mt-1 text-xs text-text-muted">
          Select the document type, then upload a clear PDF or image. Re-uploads replace the current version. {{ pdfOrImageHint }}
        </p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="text-sm font-medium">Document type</label>
            <select v-model="selectedType" class="zaqa-input">
              <option value="nrc_copy">NRC or Passport</option>
              <option value="certificate_copy">Qualification document</option>
              <option value="transcript">Transcript (optional)</option>
            </select>
          </div>

          <div>
            <label class="text-sm font-medium">File</label>
            <input
              ref="fileInput"
              type="file"
              accept="application/pdf,image/*"
              class="zaqa-input"
              @change="onFileChange"
            />
            <InputError :message="form.errors.file" />
          </div>
        </div>

        <div v-if="selectedType === 'nrc_copy'" class="mt-3 text-xs text-text-muted">
          Upload either NRC copy or Passport copy. If you upload a passport here, ZAQA will still treat it as your identity document.
        </div>
      </div>

      <div class="w-full sm:w-60">
        <div class="rounded-lg border border-border bg-surface-muted px-4 py-3">
          <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Selected</div>
          <div class="mt-1 text-sm font-semibold text-text-primary">{{ typeLabel }}</div>
          <div v-if="currentDocument" class="mt-2 text-xs text-text-muted">
            Current: v{{ currentDocument.version_number }} • {{ currentDocument.original_name }}
          </div>
          <div v-else class="mt-2 text-xs text-text-muted">No file uploaded yet.</div>
          <div v-if="currentDocument" class="mt-2 flex flex-wrap gap-2">
            <a :href="currentDocument.preview_url" target="_blank" rel="noopener" class="zaqa-link text-xs">Preview</a>
            <a :href="currentDocument.download_url" target="_blank" rel="noopener" class="zaqa-link text-xs">Download</a>
          </div>
        </div>

        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary mt-3 w-full"
          :disabled="!form.file || form.processing"
          @click="upload"
        >
          {{ currentDocument ? 'Replace upload' : 'Upload' }}
        </button>
      </div>
    </div>
  </div>
</template>

