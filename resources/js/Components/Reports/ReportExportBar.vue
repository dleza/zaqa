<script setup lang="ts">
import { FileDown, FileSpreadsheet, FileText } from 'lucide-vue-next'

const props = defineProps<{
  exportPath: string
  query: Record<string, string | number | null | undefined>
}>()

function link(format: string): string {
  const p = new URLSearchParams()
  Object.entries(props.query).forEach(([k, v]) => {
    if (v === null || v === undefined || v === '') return
    p.set(k, String(v))
  })
  p.set('format', format)
  return `${props.exportPath}?${p.toString()}`
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <a
      :href="link('csv')"
      class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold"
    >
      <FileText class="h-4 w-4 shrink-0" aria-hidden="true" />
      CSV
    </a>
    <a
      :href="link('xlsx')"
      class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold"
    >
      <FileSpreadsheet class="h-4 w-4 shrink-0" aria-hidden="true" />
      Excel
    </a>
    <a
      :href="link('pdf')"
      class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold"
    >
      <FileDown class="h-4 w-4 shrink-0" aria-hidden="true" />
      PDF
    </a>
  </div>
</template>
