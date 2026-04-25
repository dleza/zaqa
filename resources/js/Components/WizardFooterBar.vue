<script setup lang="ts">
const props = defineProps<{
  showPrev: boolean
  showNext: boolean
  prevDisabled?: boolean
  nextDisabled?: boolean
  prevLabel?: string
  nextLabel?: string
  onPrev?: () => void
  onNext?: () => void
}>()
</script>

<template>
  <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex w-full sm:w-auto">
      <button
        v-if="showPrev"
        type="button"
        class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto"
        :disabled="!!prevDisabled"
        @click="onPrev?.()"
      >
        {{ prevLabel ?? 'Previous' }}
      </button>
    </div>

    <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:justify-center">
      <slot />
    </div>

    <div class="flex w-full sm:w-auto sm:justify-end">
      <button
        v-if="showNext"
        type="button"
        class="zaqa-btn zaqa-btn-primary w-full sm:w-auto"
        :disabled="!!nextDisabled"
        @click="onNext?.()"
      >
        {{ nextLabel ?? 'Next' }}
      </button>
    </div>
  </div>
</template>

