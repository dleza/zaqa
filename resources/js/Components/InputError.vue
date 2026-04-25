<script setup lang="ts">
defineProps<{
  message?: string
}>()
</script>

<template>
  <p v-if="message" class="mt-1 text-xs text-danger">
    {{ message }}
  </p>
</template>

