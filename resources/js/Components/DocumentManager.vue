<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'
import Swal from 'sweetalert2'
import { AlertCircle, CheckCircle2, Download, Eye, FileText, RefreshCw, Trash2, Upload, X } from 'lucide-vue-next'

type DocType = 'nrc_copy' | 'certificate_copy' | 'transcript'

type DocItem = {
  id: number
  document_type: string
  original_name: string
  mime_type: string
  size_bytes: number
  version_number: number
  is_current_version: boolean
  preview_url?: string
  download_url?: string
}

const props = defineProps<{
  uploadUrl: string
  documents: DocItem[]
  transcriptRequired: boolean
}>()

const requiredTypes = computed<DocType[]>(() => ['nrc_copy', 'certificate_copy'])
const allTypes = computed<DocType[]>(() => ['nrc_copy', 'certificate_copy', 'transcript'])

function typeMeta(type: DocType) {
  if (type === 'nrc_copy')
    return {
      label: 'NRC / Passport',
      helper: 'Upload a clear copy of your NRC or Passport.',
      required: true,
      icon: FileText,
    }
  if (type === 'certificate_copy')
    return {
      label: 'Qualification document',
      helper: 'Upload the certificate or official qualification document.',
      required: true,
      icon: FileText,
    }
  return {
    label: 'Transcript',
    helper: 'Transcript is optional. Upload it if you have it available.',
    required: false,
    icon: FileText,
  }
}

const currentByType = computed(() => {
  const map = new Map<DocType, DocItem | null>()
  for (const t of allTypes.value) {
    const current = props.documents
      .filter((d) => d.document_type === t)
      .sort((a, b) => (b.version_number ?? 0) - (a.version_number ?? 0))
      .find((d) => d.is_current_version) ?? null
    map.set(t, current)
  }
  return map
})

const uploadedRequiredCount = computed(() => requiredTypes.value.filter((t) => !!currentByType.value.get(t)).length)
const requiredCount = computed(() => requiredTypes.value.length)

const modalOpen = ref(false)
const modalType = ref<DocType>('nrc_copy')
const modalMode = ref<'upload' | 'replace'>('upload')
const modalDropActive = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const form = useForm<{ document_type: string; file: File | null }>({
  document_type: 'nrc_copy',
  file: null,
})

const selectedPreviewUrl = ref<string | null>(null)
const selectedPreviewKind = ref<'image' | 'pdf' | 'other' | null>(null)

function resetSelectedPreview() {
  if (selectedPreviewUrl.value) {
    URL.revokeObjectURL(selectedPreviewUrl.value)
  }
  selectedPreviewUrl.value = null
  selectedPreviewKind.value = null
}

function computeKind(file: File): 'image' | 'pdf' | 'other' {
  const t = (file.type ?? '').toLowerCase()
  if (t.startsWith('image/')) return 'image'
  if (t === 'application/pdf') return 'pdf'
  return 'other'
}

watch(
  () => form.file,
  (file) => {
    resetSelectedPreview()
    if (!file) return
    selectedPreviewKind.value = computeKind(file)
    selectedPreviewUrl.value = URL.createObjectURL(file)
  },
)

onBeforeUnmount(() => {
  resetSelectedPreview()
})

function openUpload(type: DocType) {
  modalOpen.value = true
  modalType.value = type
  modalMode.value = currentByType.value.get(type) ? 'replace' : 'upload'
  form.clearErrors()
  form.reset('file')
  resetSelectedPreview()
  form.document_type = type
  window.setTimeout(() => {
    fileInput.value?.focus?.()
  }, 0)
}

function cancelUpload() {
  modalOpen.value = false
  modalDropActive.value = false
  form.clearErrors()
  form.reset('file')
  resetSelectedPreview()
}

function setFile(file: File | null) {
  form.file = file
}

function onFileChange(type: DocType, e: Event) {
  const target = e.target as HTMLInputElement
  const file = target.files && target.files.length > 0 ? target.files[0] : null
  modalType.value = type
  form.document_type = type
  setFile(file)
}

function upload() {
  if (!modalOpen.value) return
  form.document_type = modalType.value
  form.post(props.uploadUrl, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      cancelUpload()
      router.reload({ only: ['application'] })
    },
  })
}

function replace(type: DocType) {
  modalOpen.value = true
  modalType.value = type
  modalMode.value = 'replace'
  form.clearErrors()
  form.reset('file')
  resetSelectedPreview()
  form.document_type = type
  window.setTimeout(() => {
    fileInput.value?.focus?.()
  }, 0)
}

function onDrop(e: DragEvent) {
  e.preventDefault()
  modalDropActive.value = false
  const file = e.dataTransfer?.files?.[0] ?? null
  if (!file) return
  setFile(file)
}

function onDragOver(e: DragEvent) {
  e.preventDefault()
  modalDropActive.value = true
}

function onDragLeave(e: DragEvent) {
  e.preventDefault()
  modalDropActive.value = false
}

function preview(doc: DocItem | null) {
  if (!doc?.preview_url) return
  window.open(doc.preview_url, '_blank', 'noopener,noreferrer')
}

function download(doc: DocItem | null) {
  if (!doc?.download_url) return
  window.open(doc.download_url, '_blank', 'noopener,noreferrer')
}

async function confirmDelete(doc: DocItem | null) {
  if (!doc) return

  const result = await Swal.fire({
    icon: 'warning',
    title: 'Delete document?',
    text: 'This will remove the uploaded file from your application. You can upload it again later if needed.',
    showCancelButton: true,
    confirmButtonText: 'Delete',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#B42318',
  })

  if (!result.isConfirmed) return

  router.delete(`/applicant/documents/${doc.id}`, {
    preserveScroll: true,
    onSuccess: () => router.reload({ only: ['application'] }),
  })
}
</script>

<template>
  <div class="space-y-4">
    <div class="rounded-xl border border-border bg-surface-muted p-4">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
          <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Document status</div>
          <div class="mt-1 text-sm font-semibold text-text-primary">
            {{ uploadedRequiredCount }} of {{ requiredCount }} required documents uploaded
          </div>
          <div v-if="requiredCount !== uploadedRequiredCount" class="mt-1 text-xs text-text-muted">
            Upload the missing required documents before continuing.
          </div>
        </div>
        <span
          class="zaqa-badge"
          :class="uploadedRequiredCount === requiredCount ? 'zaqa-badge-success' : 'zaqa-badge-warning'"
          aria-label="Documents completion status"
        >
          <component :is="uploadedRequiredCount === requiredCount ? CheckCircle2 : AlertCircle" class="h-4 w-4" aria-hidden="true" />
          <span>{{ uploadedRequiredCount === requiredCount ? 'Complete' : 'Incomplete' }}</span>
        </span>
      </div>
    </div>

    <div class="divide-y divide-border/60 overflow-hidden rounded-xl border border-border bg-surface">
      <div
        v-for="type in allTypes"
        :key="type"
        class="p-5"
      >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="flex min-w-0 items-start gap-3">
            <div class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-border bg-surface-muted">
              <component :is="typeMeta(type).icon" class="h-5 w-5 text-text-muted" aria-hidden="true" />
            </div>
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <div class="text-sm font-semibold text-text-primary">{{ typeMeta(type).label }}</div>
                <span v-if="typeMeta(type).required" class="zaqa-badge zaqa-badge-warning">Required</span>
                <span v-else class="zaqa-badge">Optional</span>
                <span v-if="currentByType.get(type)" class="zaqa-badge zaqa-badge-success">
                  <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
                  Uploaded
                </span>
                <span v-else class="zaqa-badge">
                  <AlertCircle class="h-4 w-4 text-text-muted" aria-hidden="true" />
                  Missing
                </span>
              </div>
              <div class="mt-1 text-xs text-text-muted">
                {{ typeMeta(type).helper }}
              </div>

              <div v-if="currentByType.get(type)" class="mt-3">
                <div class="flex flex-wrap gap-2">
                  <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 text-xs" @click="preview(currentByType.get(type))">
                    <Eye class="h-4 w-4" aria-hidden="true" />
                    Preview
                  </button>
                  <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 text-xs" @click="download(currentByType.get(type))">
                    <Download class="h-4 w-4" aria-hidden="true" />
                    Download
                  </button>
                  <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 text-xs" @click="replace(type)">
                    <RefreshCw class="h-4 w-4" aria-hidden="true" />
                    Replace
                  </button>
                  <button type="button" class="zaqa-btn border border-danger/20 bg-danger/10 px-3 text-xs font-semibold text-danger hover:bg-danger/15" @click="confirmDelete(currentByType.get(type))">
                    <Trash2 class="h-4 w-4" aria-hidden="true" />
                    Delete
                  </button>
                </div>
              </div>
              <div v-else class="mt-3">
                <button type="button" class="zaqa-btn zaqa-btn-primary" @click="openUpload(type)">
                  <Upload class="h-4 w-4" aria-hidden="true" />
                  Upload
                </button>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Upload/Replace modal -->
    <div v-if="modalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Upload document">
      <button type="button" class="absolute inset-0 bg-black/60" aria-label="Close upload modal" @click="cancelUpload" />
      <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl border border-border bg-surface shadow-2xl">
        <div class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <div class="text-sm font-semibold text-text-primary">
                {{ modalMode === 'replace' ? 'Replace document' : 'Upload document' }}
              </div>
              <span class="zaqa-badge">
                {{ typeMeta(modalType).label }}
              </span>
            </div>
            <div class="mt-1 text-xs text-text-muted">
              Upload a PDF or image. Re-uploads replace the current version.
            </div>
          </div>
          <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" aria-label="Close" @click="cancelUpload">
            <X class="h-4 w-4" aria-hidden="true" />
          </button>
        </div>

        <div class="px-5 py-5">
          <div
            class="rounded-2xl border border-dashed p-5 transition"
            :class="modalDropActive ? 'border-brand bg-brand/5' : 'border-border bg-surface-muted'"
            @drop="onDrop"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
          >
            <div class="flex flex-col items-center justify-center gap-2 text-center">
              <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-border bg-surface">
                <Upload class="h-6 w-6 text-brand" aria-hidden="true" />
              </div>
              <div class="text-sm font-semibold text-text-primary">Drag & drop your file here</div>
              <div class="text-xs text-text-muted">or choose a file from your device</div>

              <div class="mt-3 w-full">
                <input
                  ref="fileInput"
                  type="file"
                  accept="application/pdf,image/*"
                  class="zaqa-input"
                  @change="onFileChange(modalType, $event)"
                />
                <InputError :message="form.errors.file" />
              </div>

              <div v-if="form.file" class="mt-2 w-full rounded-xl border border-border bg-surface px-4 py-3 text-left">
                <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Selected file</div>
                <div class="mt-1 truncate text-sm font-semibold text-text-primary">{{ form.file.name }}</div>
              </div>

              <div v-if="form.file && selectedPreviewUrl" class="mt-3 w-full overflow-hidden rounded-xl border border-border bg-surface">
                <div class="border-b border-border bg-surface-muted px-4 py-2 text-xs font-semibold text-text-muted uppercase tracking-wider">
                  Preview
                </div>
                <div class="p-3">
                  <img
                    v-if="selectedPreviewKind === 'image'"
                    :src="selectedPreviewUrl"
                    alt="Selected document preview"
                    class="mx-auto max-h-[420px] w-full rounded-lg object-contain"
                  />
                  <iframe
                    v-else-if="selectedPreviewKind === 'pdf'"
                    :src="selectedPreviewUrl"
                    title="Selected PDF preview"
                    class="h-[420px] w-full rounded-lg border border-border"
                  />
                  <div v-else class="text-xs text-text-muted">
                    Preview is available for images and PDFs. This file will still be uploaded.
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="form.progress" class="mt-4">
            <div class="flex items-center justify-between text-xs text-text-muted">
              <span>Uploading…</span>
              <span>{{ form.progress.percentage }}%</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-border/60">
              <div class="h-full bg-brand" :style="{ width: `${form.progress.percentage}%` }" />
            </div>
          </div>
        </div>

        <div class="flex flex-col gap-3 border-t border-border bg-surface-muted px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-xs text-text-muted">Accepted: PDF or image. Keep documents clear and readable.</p>
          <div class="flex gap-2">
            <button type="button" class="zaqa-btn zaqa-btn-secondary px-4" :disabled="form.processing" @click="cancelUpload">Cancel</button>
            <button type="button" class="zaqa-btn zaqa-btn-primary px-5" :disabled="!form.file || form.processing" @click="upload">
              {{ form.processing ? 'Uploading…' : modalMode === 'replace' ? 'Replace upload' : 'Upload' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

