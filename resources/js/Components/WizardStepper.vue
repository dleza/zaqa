<script setup lang="ts">
import { computed } from 'vue'

type Step = { key: string; label: string }

const props = defineProps<{
  steps: Step[]
  activeKey: string
  onStepClick?: (key: string) => void
  disabledKeys?: string[]
}>()

const activeIndex = computed(() => Math.max(0, props.steps.findIndex((s) => s.key === props.activeKey)))
const total = computed(() => props.steps.length)
const currentLabel = computed(() => props.steps[activeIndex.value]?.label ?? 'Step')

function stateFor(index: number) {
  if (index < activeIndex.value) return 'complete'
  if (index === activeIndex.value) return 'active'
  return 'upcoming'
}

function isDisabled(key: string) {
  return Array.isArray(props.disabledKeys) && props.disabledKeys.includes(key)
}
</script>

<template>
  <div class="zaqa-wizard-stepper">
    <!-- Mobile compact -->
    <div class="sm:hidden">
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <div class="text-sm font-semibold text-text-primary">Step {{ activeIndex + 1 }} of {{ total }}</div>
          <div class="mt-0.5 truncate text-xs text-text-muted">{{ currentLabel }}</div>
        </div>
        <div class="h-2 w-28 overflow-hidden rounded-full border border-border bg-surface-muted">
          <div
            class="h-full bg-brand"
            :style="{ width: `${Math.round(((activeIndex + 1) / Math.max(1, total)) * 100)}%` }"
            role="progressbar"
            :aria-valuenow="activeIndex + 1"
            aria-valuemin="1"
            :aria-valuemax="total"
            aria-label="Wizard progress"
          />
        </div>
      </div>
    </div>

    <!-- Desktop directional -->
    <div class="hidden sm:block">
      <div class="zaqa-stepper-flow" role="list" aria-label="Application steps">
        <button
          v-for="(s, idx) in steps"
          :key="s.key"
          type="button"
          role="listitem"
          class="zaqa-stepper-item"
          :class="[
            idx === 0 ? 'zaqa-stepper-item-first' : '',
            idx === steps.length - 1 ? 'zaqa-stepper-item-last' : '',
            stateFor(idx) === 'active' ? 'zaqa-stepper-item-active' : '',
            stateFor(idx) === 'complete' ? 'zaqa-stepper-item-complete' : '',
            isDisabled(s.key) ? 'opacity-60 grayscale cursor-not-allowed' : '',
          ]"
          :aria-current="s.key === activeKey ? 'step' : undefined"
          :disabled="!onStepClick || isDisabled(s.key)"
          @click="onStepClick && !isDisabled(s.key) ? onStepClick(s.key) : undefined"
        >
          <span class="zaqa-stepper-index">{{ idx + 1 }}</span>
          <span class="zaqa-stepper-label">{{ s.label }}</span>
        </button>
      </div>
    </div>
  </div>
</template>
