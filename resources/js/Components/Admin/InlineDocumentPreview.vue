<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Download, ExternalLink, X } from 'lucide-vue-next'
import { resolvePreviewKind, type InlinePreviewDocument } from '@/lib/inlineDocumentPreview'

const props = defineProps<{
  document: InlinePreviewDocument | null
}>()

const emit = defineEmits<{
  close: []
}>()

const panelRef = ref<HTMLElement | null>(null)

const previewKind = computed(() => {
  if (!props.document) {
    return null
  }

  return resolvePreviewKind(props.document)
})

watch(
  () => props.document,
  (document) => {
    if (!document) {
      return
    }

    void nextTick(() => {
      panelRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      panelRef.value?.focus()
    })
  },
)
</script>

<template>
  <div
    v-if="document"
    ref="panelRef"
    tabindex="-1"
    class="mt-5 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm outline-none"
  >
    <div class="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
      <div class="min-w-0">
        <h3 class="text-base font-semibold text-text-primary">Document preview</h3>
        <p class="mt-1 text-sm text-text-muted">{{ document.label }}</p>
        <p class="mt-0.5 truncate text-xs text-text-muted">{{ document.filename }}</p>
      </div>
      <button
        type="button"
        class="zaqa-btn zaqa-btn-secondary inline-flex h-9 w-9 shrink-0 items-center justify-center p-0"
        aria-label="Close preview"
        @click="emit('close')"
      >
        <X class="h-4 w-4" aria-hidden="true" />
      </button>
    </div>

    <div class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted/40 px-5 py-3">
      <a
        :href="document.download_url"
        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs"
      >
        <Download class="h-3.5 w-3.5" aria-hidden="true" />
        Download
      </a>
      <a
        :href="document.preview_url"
        target="_blank"
        rel="noopener noreferrer"
        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs"
      >
        <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
        Open in new tab
      </a>
    </div>

    <div class="max-h-[70vh] overflow-auto p-5">
      <img
        v-if="previewKind === 'image'"
        :src="document.preview_url"
        :alt="document.filename"
        class="mx-auto max-h-[420px] w-full object-contain sm:max-h-[600px]"
      />
      <iframe
        v-else-if="previewKind === 'pdf'"
        :src="document.preview_url"
        :title="`Preview ${document.filename}`"
        class="h-[420px] w-full rounded-xl border border-border bg-white sm:h-[600px]"
      />
      <div
        v-else
        class="rounded-xl border border-dashed border-border bg-surface-muted/40 px-4 py-8 text-center text-sm text-text-muted"
      >
        Preview is not available for this file type. You can download the document instead.
      </div>
    </div>
  </div>
</template>
