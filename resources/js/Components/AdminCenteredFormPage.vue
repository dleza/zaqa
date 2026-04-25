<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    maxWidth?: '2xl' | '3xl' | '4xl' | '5xl'
  }>(),
  {
    maxWidth: '3xl',
  },
)

const maxWidthClass = computed(() => {
  switch (props.maxWidth) {
    case '2xl':
      return 'max-w-2xl'
    case '3xl':
      return 'max-w-3xl'
    case '4xl':
      return 'max-w-4xl'
    case '5xl':
      return 'max-w-5xl'
    default:
      return 'max-w-3xl'
  }
})
</script>

<template>
  <div :class="['mx-auto w-full', maxWidthClass]">
    <slot name="header" />
    <div class="mt-6">
      <slot />
    </div>
  </div>
</template>

