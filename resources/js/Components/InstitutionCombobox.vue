<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import InputError from '@/Components/InputError.vue'

type Option = {
  id: number | 'other'
  name: string
  country_id?: number
  has_consent_form?: boolean
  consent_form_url?: string | null
}

const props = defineProps<{
  countryId: number | string | null
  modelValue: number | 'other' | '' | null
  queryEndpoint: string
  label?: string
  error?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | 'other' | '' | null): void
  (e: 'selected', option: Option): void
}>()

const open = ref(false)
const query = ref('')
const loading = ref(false)
const options = ref<Option[]>([{ id: 'other', name: 'Other (not listed)' }])
const activeIndex = ref(0)

const selectedOption = computed<Option | null>(() => {
  const id = props.modelValue
  if (id === 'other') return { id: 'other', name: 'Other (not listed)' }
  if (typeof id === 'number') return options.value.find((o) => o.id === id) ?? null
  return null
})

let debounceTimer: number | null = null
async function load() {
  loading.value = true
  try {
    const params = new URLSearchParams()
    const q = query.value.trim()
    if (q.length > 0) params.set('q', q)
    if (props.countryId) params.set('country_id', String(props.countryId))

    const res = await fetch(`${props.queryEndpoint}?${params.toString()}`, { headers: { Accept: 'application/json' } })
    const json = await res.json()
    const data = Array.isArray(json?.data) ? json.data : []
    options.value = [
      ...data.map((r: any) => ({
        id: r.id,
        name: r.name,
        country_id: r.country_id,
        has_consent_form: r.has_consent_form,
        consent_form_url: r.consent_form_url ?? null,
      })),
      { id: 'other', name: 'Other (not listed)' },
    ]
    activeIndex.value = 0
  } finally {
    loading.value = false
  }
}

function debouncedLoad() {
  if (debounceTimer) window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(() => load(), 180)
}

function choose(opt: Option) {
  emit('update:modelValue', opt.id === 'other' ? 'other' : (opt.id as number))
  emit('selected', opt)
  open.value = false
}

function toggleOpen() {
  open.value = !open.value
  if (open.value) {
    query.value = ''
    debouncedLoad()
    activeIndex.value = 0
    void nextTick()
  }
}

function onKeydown(e: KeyboardEvent) {
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
    activeIndex.value = Math.min(options.value.length - 1, activeIndex.value + 1)
    e.preventDefault()
    return
  }

  if (e.key === 'ArrowUp') {
    activeIndex.value = Math.max(0, activeIndex.value - 1)
    e.preventDefault()
    return
  }

  if (e.key === 'Enter') {
    const opt = options.value[activeIndex.value]
    if (opt) choose(opt)
    e.preventDefault()
  }
}

watch(
  () => props.countryId,
  async () => {
    query.value = ''
    emit('update:modelValue', '')
    await load()
  },
)

onMounted(async () => {
  await load()
})
</script>

<template>
  <div>
    <label class="text-sm font-medium">{{ label ?? 'Awarding institution' }}</label>

    <div class="mt-1 relative" @keydown="onKeydown">
      <button
        type="button"
        class="zaqa-input mt-0 flex items-center justify-between gap-3 text-left"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="listbox"
        @click="toggleOpen"
      >
        <span class="min-w-0 truncate">
          <span v-if="selectedOption">{{ selectedOption.name }}</span>
          <span v-else class="text-text-muted">Select institution…</span>
        </span>
        <span class="text-text-muted text-xs">{{ loading ? 'Loading…' : '▼' }}</span>
      </button>

      <div v-if="open" class="absolute z-30 mt-2 w-full overflow-hidden rounded-xl border border-border bg-surface shadow-lg">
        <div class="border-b border-border bg-surface-muted p-2">
          <input
            v-model="query"
            class="zaqa-input mt-0"
            placeholder="Search by name…"
            @input="debouncedLoad"
          />
        </div>

        <ul role="listbox" class="max-h-64 overflow-auto p-1">
          <li v-if="options.length === 0" class="px-3 py-2 text-sm text-text-muted">No results.</li>
          <li
            v-for="(opt, idx) in options"
            :key="String(opt.id)"
            role="option"
            class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold"
            :class="idx === activeIndex ? 'bg-brand/10 text-brand' : 'text-text-primary hover:bg-surface-muted'"
            @mouseenter="activeIndex = idx"
            @click="choose(opt)"
          >
            {{ opt.name }}
          </li>
        </ul>
      </div>
    </div>

    <InputError :message="error" />
  </div>
</template>

