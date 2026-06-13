<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue: string
    disabled?: boolean
    readonly?: boolean
    helperText?: string
    placeholder?: string
  }>(),
  {
    disabled: false,
    readonly: false,
    helperText: 'Enter your mobile number without the country code.',
    placeholder: '973936164',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const localDigits = computed({
  get: () => props.modelValue,
  set: (value: string) => {
    emit('update:modelValue', value.replace(/\D/g, '').slice(0, 9))
  },
})

const fieldStateClass = computed(() => {
  if (props.readonly || props.disabled) {
    return 'bg-surface-muted/60 text-text-muted'
  }

  return 'bg-surface text-text-primary'
})
</script>

<template>
  <div>
    <div
      class="flex w-full min-w-0 overflow-hidden rounded-lg border border-border focus-within:ring-2 focus-within:ring-accent/40"
      :class="fieldStateClass"
    >
      <div
        class="flex shrink-0 items-center border-r border-border bg-surface-muted px-3 text-sm font-semibold text-text-primary sm:px-4"
        aria-hidden="true"
      >
        +260
      </div>
      <span class="hidden px-1 text-sm text-text-muted sm:inline-flex sm:items-center" aria-hidden="true">|</span>
      <input
        v-model="localDigits"
        type="tel"
        inputmode="numeric"
        autocomplete="tel-national"
        :placeholder="placeholder"
        class="min-w-0 flex-1 border-0 bg-transparent px-3 py-3 text-sm focus:outline-none focus:ring-0 sm:h-12 sm:px-4"
        :disabled="disabled"
        :readonly="readonly"
      />
    </div>
    <p v-if="helperText" class="mt-2 text-xs text-text-muted">{{ helperText }}</p>
  </div>
</template>
