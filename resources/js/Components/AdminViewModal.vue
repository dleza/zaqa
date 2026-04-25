<script setup lang="ts">
import { X } from 'lucide-vue-next'
import { computed, watch } from 'vue'

const props = defineProps<{
  modelValue: boolean
  title: string
  description?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
}>()

const open = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

function close() {
  open.value = false
}

watch(
  () => props.modelValue,
  (v) => {
    if (v) {
      try {
        document.body.style.overflow = 'hidden'
      } catch {}
    } else {
      try {
        document.body.style.overflow = ''
      } catch {}
    }
  },
  { immediate: true },
)
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <button type="button" class="absolute inset-0 bg-black/60" aria-label="Close modal" @click="close" />

    <div class="relative w-full max-w-3xl overflow-hidden rounded-2xl border border-border bg-surface shadow-2xl">
      <div class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
        <div class="min-w-0">
          <div class="truncate text-base font-semibold text-text-primary">{{ title }}</div>
          <div v-if="description" class="mt-1 text-xs text-text-muted">{{ description }}</div>
        </div>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" aria-label="Close" @click="close">
          <X class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>

      <div class="px-5 py-5">
        <slot />
      </div>

      <div class="border-t border-border bg-surface-muted px-5 py-4">
        <div class="flex items-center justify-end gap-2">
          <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="close">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

