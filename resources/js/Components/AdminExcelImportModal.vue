<script setup lang="ts">
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { FileSpreadsheet } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    title: string
    description?: string
    templateUrl: string
    importUrl: string
    canImport: boolean
    maxWidthClass?: string
  }>(),
  { maxWidthClass: 'max-w-4xl' },
)

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
}>()

const fileInput = ref<HTMLInputElement | null>(null)
const form = useForm<{ file: File | null }>({ file: null })

const open = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

watch(open, (v) => {
  if (!v) {
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
  if (!props.canImport || !form.file) return
  form.post(props.importUrl, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      open.value = false
      form.reset()
      if (fileInput.value) fileInput.value.value = ''
    },
  })
}
</script>

<template>
  <AdminActionModal v-model="open" :title="title" :description="description" :max-width-class="maxWidthClass">
    <div class="space-y-4">
      <p class="text-sm text-text-muted">
        Download the template, fill one row per record, then upload. Supported formats: .xlsx, .xls, .csv (max ~12&nbsp;MB).
      </p>
      <div class="flex flex-wrap gap-2">
        <a
          :href="templateUrl"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold"
          download
        >
          <FileSpreadsheet class="h-4 w-4 shrink-0" aria-hidden="true" />
          Download template
        </a>
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Spreadsheet file</label>
        <input
          ref="fileInput"
          type="file"
          accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
          class="mt-2 block w-full text-sm text-text-primary file:mr-3 file:rounded-lg file:border file:border-border file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-semibold"
          @change="pickFile"
        />
        <p v-if="form.errors.file" class="mt-2 text-xs text-danger">{{ form.errors.file }}</p>
      </div>
    </div>

    <template #footer>
      <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="open = false">Cancel</button>
      <button
        type="button"
        class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm font-semibold disabled:opacity-50"
        :disabled="!canImport || !form.file || form.processing"
        @click="submit"
      >
        {{ form.processing ? 'Uploading…' : 'Upload & import' }}
      </button>
    </template>
  </AdminActionModal>
</template>
