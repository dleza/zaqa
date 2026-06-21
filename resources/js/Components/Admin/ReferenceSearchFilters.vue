<script setup lang="ts">
import { Search } from 'lucide-vue-next'

const props = defineProps<{
  applicationReference: string
  qualificationReference: string
  compact?: boolean
}>()

const emit = defineEmits<{
  'update:applicationReference': [value: string]
  'update:qualificationReference': [value: string]
}>()

function onApplicationReferenceInput(event: Event) {
  emit('update:applicationReference', (event.target as HTMLInputElement).value)
}

function onQualificationReferenceInput(event: Event) {
  emit('update:qualificationReference', (event.target as HTMLInputElement).value)
}
</script>

<template>
  <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
    <div class="relative">
      <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
      <input
        :value="props.applicationReference"
        class="zaqa-input h-10 pl-9"
        :class="props.compact ? 'w-full sm:w-44' : 'w-full sm:w-48'"
        placeholder="e.g. 2026-000245"
        aria-label="Application reference"
        @input="onApplicationReferenceInput"
      />
    </div>
    <div class="relative">
      <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
      <input
        :value="props.qualificationReference"
        class="zaqa-input h-10 pl-9"
        :class="props.compact ? 'w-full sm:w-48' : 'w-full sm:w-52'"
        placeholder="e.g. 2026-000245-01"
        aria-label="Qualification reference"
        @input="onQualificationReferenceInput"
      />
    </div>
  </div>
</template>
