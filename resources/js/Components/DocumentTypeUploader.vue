<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'

const props = defineProps<{
  applicationId: number
  uploadUrl: string
  type: string
  label: string
  required?: boolean
  documents: Array<any>
}>()

const fileInput = ref<HTMLInputElement | null>(null)

const documentsOfType = computed(() =>
  props.documents
    .filter((d) => d.document_type === props.type)
    .sort((a, b) => (b.version_number ?? 0) - (a.version_number ?? 0)),
)

const currentDocument = computed(() => documentsOfType.value.find((d) => d.is_current_version))

const form = useForm<{ document_type: string; file: File | null }>({
  document_type: props.type,
  file: null,
})

function onFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  form.file = target.files && target.files.length > 0 ? target.files[0] : null
}

function upload() {
  form.document_type = props.type
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
  <div class="rounded-xl border border-border bg-surface p-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <div class="flex items-center gap-2">
          <div class="text-sm font-medium">{{ label }}</div>
          <span
            v-if="required"
            class="rounded-full border border-danger/20 bg-danger/10 px-2 py-0.5 text-xs font-medium text-danger"
          >
            Required
          </span>
        </div>
        <div v-if="currentDocument" class="mt-1 text-xs text-text-muted">
          Current: v{{ currentDocument.version_number }} • {{ currentDocument.original_name }}
        </div>
        <div v-else class="mt-1 text-xs text-text-muted">No file uploaded yet.</div>
        <div v-if="currentDocument" class="mt-2 flex flex-wrap gap-2">
          <a
            :href="currentDocument.preview_url"
            target="_blank"
            rel="noopener"
            class="zaqa-link text-xs"
          >
            Preview
          </a>
          <a
            :href="currentDocument.download_url"
            target="_blank"
            rel="noopener"
            class="zaqa-link text-xs"
          >
            Download
          </a>
        </div>
      </div>

      <div class="w-full sm:w-72">
        <input
          ref="fileInput"
          type="file"
          accept="application/pdf,image/*"
          class="zaqa-input"
          @change="onFileChange"
        />
        <InputError :message="form.errors.file" />

        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary mt-2 w-full px-3 py-2 text-sm"
          :disabled="!form.file || form.processing"
          @click="upload"
        >
          {{ currentDocument ? 'Replace' : 'Upload' }}
        </button>
      </div>
    </div>
  </div>
</template>

