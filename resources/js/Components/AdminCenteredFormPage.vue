<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    maxWidth?: '2xl' | '3xl' | '4xl' | '5xl' | '6xl' | '7xl' | '8xl'
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
    case '6xl':
      return 'max-w-6xl'
    case '7xl':
      return 'max-w-7xl'
    case '8xl':
      return 'max-w-[90rem]'
    default:
      return 'max-w-3xl'
  }
})
</script>

<template>
  <div class="w-full">
    <slot name="header" />
    <div :class="['mt-6 w-full', maxWidthClass]">
      <slot />
    </div>
  </div>
</template>
