<script setup lang="ts">
const props = defineProps<{
  showSidebar: boolean
}>()
</script>

<template>
  <div :class="props.showSidebar ? 'mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3' : 'mt-6'">
    <div :class="props.showSidebar ? 'space-y-6 lg:col-span-2' : 'space-y-6'">
      <slot />
    </div>

    <aside v-if="props.showSidebar" class="space-y-4 lg:sticky lg:top-6 lg:self-start">
      <slot name="sidebar" />
    </aside>
  </div>
</template>

