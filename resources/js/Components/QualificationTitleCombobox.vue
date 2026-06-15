<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import InputError from '@/Components/InputError.vue'

type Option = {
  id: number | 'other'
  title: string
}

const props = defineProps<{
  awardingInstitutionId: number | string | null
  qualificationTypeId?: number | string | null
  modelValue: number | 'other' | '' | null
  selectedTitle?: string | null
  queryEndpoint: string
  label?: string
  error?: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | 'other' | '' | null): void
  (e: 'selected', option: Option): void
}>()

const open = ref(false)
const query = ref('')
const loading = ref(false)
const options = ref<Option[]>([{ id: 'other', title: 'Not Listed - Enter New' }])
const activeIndex = ref(0)
const loadedTitles = ref<Record<number, string>>({})

const selectedOption = computed<Option | null>(() => {
  const id = props.modelValue
  if (id === 'other') return { id: 'other', title: 'Not Listed - Enter New' }
  if (typeof id === 'number' && id > 0) {
    const fromOptions = options.value.find((o) => o.id === id)
    if (fromOptions) return fromOptions
    const cached = loadedTitles.value[id]
    const fallbackTitle = (props.selectedTitle ?? '').toString().trim()
    return { id, title: cached || fallbackTitle || `Title #${id}` }
  }
  return null
})

let debounceTimer: number | null = null
async function load() {
  loading.value = true
  try {
    const params = new URLSearchParams()
    const q = query.value.trim()
    if (q.length > 0) params.set('q', q)
    if (props.awardingInstitutionId && props.awardingInstitutionId !== 'other') {
      params.set('awarding_institution_id', String(props.awardingInstitutionId))
    }
    if (props.qualificationTypeId && props.qualificationTypeId !== '') {
      params.set('qualification_type_id', String(props.qualificationTypeId))
    }

    const res = await fetch(`${props.queryEndpoint}?${params.toString()}`, { headers: { Accept: 'application/json' } })
    const json = await res.json()
    const data = Array.isArray(json?.data) ? json.data : []
    const mapped = data
      .map((r: any) => ({
        id: Number(r.id),
        title: String(r.title ?? '').trim(),
      }))
      .filter((r: Option) => typeof r.id === 'number' && r.id > 0 && r.title.length > 0)

    mapped.forEach((r: Option) => {
      if (typeof r.id === 'number') loadedTitles.value[r.id] = r.title
    })

    options.value = [...mapped, { id: 'other', title: 'Not Listed - Enter New' }]
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
  emit('update:modelValue', opt.id === 'other' ? 'other' : opt.id)
  emit('selected', opt)
  open.value = false
}

function toggleOpen() {
  if (props.disabled) return
  open.value = !open.value
  if (open.value) {
    query.value = ''
    debouncedLoad()
    activeIndex.value = 0
    void nextTick()
  }
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
  () => [props.awardingInstitutionId, props.qualificationTypeId],
  async () => {
    query.value = ''
    emit('update:modelValue', '')
    await load()
  },
)

onMounted(async () => {
  if (typeof props.modelValue === 'number' && props.modelValue > 0 && props.selectedTitle) {
    loadedTitles.value[props.modelValue] = props.selectedTitle
  }
  await load()
})
</script>

<template>
  <div>
    <label class="text-sm font-medium">{{ label ?? 'Title of qualification' }}</label>

    <div class="mt-1 relative" @keydown="onKeydown">
      <button
        type="button"
        class="zaqa-input mt-0 flex items-center justify-between gap-3 text-left"
        :class="disabled ? 'opacity-60 cursor-not-allowed' : ''"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="listbox"
        :disabled="disabled"
        @click="toggleOpen"
      >
        <span class="min-w-0 truncate">
          <span v-if="selectedOption">{{ selectedOption.title }}</span>
          <span v-else class="text-text-muted">Select title…</span>
        </span>
        <span class="text-text-muted text-xs">{{ loading ? 'Loading…' : '▼' }}</span>
      </button>

      <p v-if="disabled" class="mt-1 text-xs text-text-muted">Select the awarding institution first.</p>

      <div v-if="open" class="absolute z-30 mt-2 w-full overflow-hidden rounded-xl border border-border bg-surface shadow-lg">
        <div class="border-b border-border bg-surface-muted p-2">
          <input v-model="query" class="zaqa-input mt-0" placeholder="Search titles…" @input="debouncedLoad" />
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
            {{ opt.title }}
          </li>
        </ul>
      </div>
    </div>

    <InputError :message="error" />
  </div>
</template>
