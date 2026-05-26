<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import InputError from '@/Components/InputError.vue'
import { Check, ChevronDown } from 'lucide-vue-next'

type Option = {
  id: number
  label: string
  disabled?: boolean
}

const props = defineProps<{
  options: Option[]
  modelValue: number | '' | null
  label: string
  placeholder?: string
  error?: string
  helpText?: string
  disabled?: boolean
  emptyValue?: '' | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | '' | null): void
}>()

const open = ref(false)
const query = ref('')
const activeIndex = ref(0)

const selectedId = computed<number | null>(() => (typeof props.modelValue === 'number' ? props.modelValue : null))
const emptyValue = computed(() => (props.emptyValue === null ? null : ''))

const optionMap = computed(() => {
  const map = new Map<number, string>()
  for (const o of props.options) map.set(o.id, o.label)
  return map
})

const selectedLabel = computed(() => {
  const id = selectedId.value
  if (id === null) return ''
  return optionMap.value.get(id) ?? `#${id}`
})

const filteredOptions = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (q === '') return props.options
  return props.options.filter((o) => o.label.toLowerCase().includes(q))
})

function setSelected(value: number | '' | null) {
  emit('update:modelValue', value)
}

function toggleOpen() {
  if (props.disabled) return
  open.value = !open.value
  if (open.value) {
    query.value = ''
    activeIndex.value = 0
    void nextTick()
  }
}

function choose(id: number) {
  setSelected(id)
  open.value = false
}

function clear() {
  setSelected(emptyValue.value)
}

function onKeydown(e: KeyboardEvent) {
  if (props.disabled) return

  if (!open.value && (e.key === 'ArrowDown' || e.key === 'Enter')) {
    open.value = true
    e.preventDefault()
    return
  }
  if (!open.value) return

  if (e.key === 'Escape') {
    open.value = false
    e.preventDefault()
    return
  }

  if (e.key === 'ArrowDown') {
    activeIndex.value = Math.min(filteredOptions.value.length - 1, activeIndex.value + 1)
    e.preventDefault()
    return
  }

  if (e.key === 'ArrowUp') {
    activeIndex.value = Math.max(0, activeIndex.value - 1)
    e.preventDefault()
    return
  }

  if (e.key === 'Enter') {
    const opt = filteredOptions.value[activeIndex.value]
    if (opt && !opt.disabled) choose(opt.id)
    e.preventDefault()
  }
}
</script>

<template>
  <div>
    <label class="text-sm font-medium">{{ label }}</label>
    <div v-if="helpText" class="mt-0.5 text-xs text-text-muted">{{ helpText }}</div>

    <div class="mt-1 relative" @keydown="onKeydown">
      <button
        type="button"
        class="zaqa-input mt-0 flex min-h-10 items-center justify-between gap-3 text-left"
        :class="disabled ? 'opacity-60 cursor-not-allowed' : ''"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="listbox"
        :disabled="disabled"
        @click="toggleOpen"
      >
        <span class="min-w-0 truncate">
          <span v-if="selectedId !== null" class="font-semibold text-text-primary">{{ selectedLabel }}</span>
          <span v-else class="text-text-muted">{{ placeholder ?? 'Select…' }}</span>
        </span>
        <span class="inline-flex items-center gap-2 text-text-muted text-xs">
          <span
            v-if="selectedId !== null"
            class="rounded-md px-2 py-1 hover:bg-surface-muted"
            role="button"
            tabindex="0"
            @click.stop="clear"
            @keydown.enter.stop.prevent="clear"
            @keydown.space.stop.prevent="clear"
          >
            Clear
          </span>
          <ChevronDown class="h-4 w-4" aria-hidden="true" />
        </span>
      </button>

      <div v-if="open" class="absolute z-30 mt-2 w-full overflow-hidden rounded-xl border border-border bg-surface shadow-lg">
        <div class="border-b border-border bg-surface-muted p-2">
          <input v-model="query" class="zaqa-input mt-0" placeholder="Search..." />
        </div>

        <ul role="listbox" class="max-h-64 overflow-auto p-1">
          <li v-if="filteredOptions.length === 0" class="px-3 py-2 text-sm text-text-muted">No results.</li>
          <li
            v-for="(opt, idx) in filteredOptions"
            :key="String(opt.id)"
            role="option"
            class="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold"
            :class="[
              idx === activeIndex ? 'bg-brand/10 text-brand' : 'text-text-primary hover:bg-surface-muted',
              opt.disabled ? 'opacity-50 cursor-not-allowed' : '',
            ]"
            @mouseenter="activeIndex = idx"
            @click="opt.disabled ? null : choose(opt.id)"
          >
            <span class="min-w-0 truncate">{{ opt.label }}</span>
            <Check v-if="selectedId === opt.id" class="h-4 w-4 shrink-0" aria-hidden="true" />
          </li>
        </ul>
      </div>
    </div>

    <InputError :message="error" />
  </div>
</template>

