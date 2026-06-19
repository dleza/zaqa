import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export type UploadLimitsProps = {
  max_file_size_mb?: number
  max_file_size_label?: string
  pdf_or_image_hint?: string
}

export function useUploadLimits() {
  const page = usePage()

  const uploads = computed(() => (page.props.uploads ?? {}) as UploadLimitsProps)

  const maxFileSizeMb = computed(() => Number(uploads.value.max_file_size_mb ?? 3))

  const maxFileSizeLabel = computed(
    () => uploads.value.max_file_size_label ?? `Maximum file size: ${maxFileSizeMb.value} MB`,
  )

  const pdfOrImageHint = computed(
    () => uploads.value.pdf_or_image_hint ?? `PDF or image files only (JPG, PNG, WEBP) — max ${maxFileSizeMb.value} MB`,
  )

  return {
    uploads,
    maxFileSizeMb,
    maxFileSizeLabel,
    pdfOrImageHint,
  }
}
