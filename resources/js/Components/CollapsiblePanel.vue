<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next'
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    title: string
    subtitle?: string
    collapsedSummary?: string
    open: boolean
    titleSize?: 'sm' | 'base'
    contentClass?: string
  }>(),
  {
    subtitle: undefined,
    collapsedSummary: undefined,
    titleSize: 'base',
    contentClass: 'px-5 pb-5',
  },
)

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void
}>()

const isOpen = computed({
  get: () => props.open,
  set: (value: boolean) => emit('update:open', value),
})

const titleClass = computed(() =>
  props.titleSize === 'sm'
    ? 'text-sm font-bold tracking-tight text-text-primary'
    : 'text-base font-bold tracking-tight text-text-primary',
)

function toggle() {
  isOpen.value = !isOpen.value
}
</script>

<template>
  <section class="rounded-2xl border border-border/70 bg-surface shadow-sm">
    <button
      type="button"
      class="flex w-full cursor-pointer items-start justify-between gap-3 p-5 text-left transition-colors hover:bg-surface-muted/20"
      :aria-expanded="isOpen"
      @click="toggle"
    >
      <div class="min-w-0 flex-1">
        <component :is="titleSize === 'sm' ? 'div' : 'h2'" :class="titleClass">
          {{ title }}
        </component>
        <p v-if="isOpen && subtitle" class="mt-1 text-sm leading-relaxed text-text-muted">
          {{ subtitle }}
        </p>
        <p
          v-else-if="!isOpen && collapsedSummary"
          class="mt-1 text-xs leading-relaxed text-text-muted"
        >
          {{ collapsedSummary }}
        </p>
      </div>
      <div class="flex shrink-0 items-center gap-2 pt-0.5">
        <slot name="icon" />
        <ChevronDown
          class="h-4 w-4 text-text-muted transition-transform duration-200"
          :class="{ 'rotate-180': isOpen }"
          aria-hidden="true"
        />
      </div>
    </button>
    <div
      v-show="isOpen"
      class="border-t border-border/60"
      :class="contentClass"
    >
      <slot />
    </div>
  </section>
</template>
