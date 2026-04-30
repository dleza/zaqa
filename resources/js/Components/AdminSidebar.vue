<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import type { AdminNavSection } from '@/Layouts/adminNav'
import { ChevronDown } from 'lucide-vue-next'

const props = defineProps<{
  sections: AdminNavSection[]
  permissions: string[]
  isMobile?: boolean
}>()

const zaqaLogoUrl = new URL('../../images/zaqa-logo-tranparent.png', import.meta.url).href

const page = usePage()
const url = computed(() => (page.url ?? '').toString())

const openSections = ref<Record<string, boolean>>({})
const openGroups = ref<Record<string, boolean>>({})

function readOpenState(storageKey: string): Record<string, boolean> {
  try {
    const raw = localStorage.getItem(storageKey)
    if (!raw) return {}
    const parsed = JSON.parse(raw) as unknown
    if (!parsed || typeof parsed !== 'object') return {}
    return parsed as Record<string, boolean>
  } catch {
    return {}
  }
}

function persistOpenState(storageKey: string, state: Record<string, boolean>) {
  try {
    localStorage.setItem(storageKey, JSON.stringify(state))
  } catch {}
}

onMounted(() => {
  openSections.value = readOpenState('zaqa_admin_sidebar_sections')
  openGroups.value = readOpenState('zaqa_admin_sidebar_groups')
})

function hasAny(required?: string[]) {
  if (!required || required.length === 0) return true
  const set = new Set(props.permissions ?? [])
  return required.some((p) => set.has(p))
}

function itemIsActive(item: any) {
  const start = item.activeStartsWith ?? item.href
  if (!start) return false
  if (url.value.startsWith(start)) return true
  const children = item.children ?? []
  return children.some((c: any) => itemIsActive(c))
}

function sectionKey(section: AdminNavSection, index: number) {
  const label = (section.label ?? '').trim()
  return label.length > 0 ? label : `__section_${index}`
}

function domIdFromKey(key: string) {
  return key
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9_-]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function sectionIsOpen(section: AdminNavSection, index: number) {
  if (!section.label) return true
  const key = sectionKey(section, index)
  const stored = openSections.value[key]
  return stored ?? section.items.some((item) => itemIsActive(item))
}

function sectionHasActiveRoute(section: AdminNavSection) {
  return section.items.some((item) => itemIsActive(item))
}

function toggleSection(section: AdminNavSection, index: number) {
  const key = sectionKey(section, index)
  const next = !sectionIsOpen(section, index)
  openSections.value = { ...openSections.value, [key]: next }
  persistOpenState('zaqa_admin_sidebar_sections', openSections.value)
}

function groupIsOpen(item: any) {
  const key = (item?.label ?? '').toString()
  const stored = openGroups.value[key]
  return stored ?? itemIsActive(item)
}

function toggleGroup(item: any) {
  const key = (item?.label ?? '').toString()
  if (!key) return
  const next = !groupIsOpen(item)
  openGroups.value = { ...openGroups.value, [key]: next }
  persistOpenState('zaqa_admin_sidebar_groups', openGroups.value)
}

const visibleSections = computed(() => {
  return (props.sections ?? [])
    .filter((s) => hasAny(s.requiredAnyPermissions))
    .map((s) => ({
      ...s,
      items: (s.items ?? [])
        .map((i: any) => ({
          ...i,
          children: (i.children ?? []).filter((c: any) => hasAny(c.requiredAnyPermissions)),
        }))
        .filter((i: any) => hasAny(i.requiredAnyPermissions) && ((i.children?.length ?? 0) > 0 || !!i.href)),
    }))
    .filter((s) => s.items.length > 0)
})
</script>

<template>
  <aside class="h-full w-[18rem] border-r border-border bg-surface">
    <div class="border-b border-border px-4 py-4">
      <Link href="/admin/dashboard" class="flex items-center gap-3 no-underline">
        <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-9 w-auto object-contain" />
        <div class="min-w-0">
          <div class="truncate text-sm font-semibold text-text-primary">ZAQA Portal</div>
          <div class="truncate text-xs text-text-muted">Admin Services</div>
        </div>
      </Link>
    </div>

    <nav class="px-3 py-4">
      <div v-for="(section, sectionIndex) in visibleSections" :key="section.label || sectionIndex" class="mb-5">
        <button
          v-if="section.label"
          type="button"
          class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-[11px] font-semibold uppercase tracking-wider transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
          :class="sectionHasActiveRoute(section) ? 'bg-brand/10 text-brand' : 'text-text-muted hover:bg-surface-muted'"
          :aria-expanded="sectionIsOpen(section, sectionIndex) ? 'true' : 'false'"
          :aria-controls="`admin-section-${domIdFromKey(sectionKey(section, sectionIndex))}`"
          @click="toggleSection(section, sectionIndex)"
        >
          <span>{{ section.label }}</span>
          <ChevronDown
            class="h-4 w-4 shrink-0 opacity-70 transition-transform"
            :class="sectionIsOpen(section, sectionIndex) ? 'rotate-180' : 'rotate-0'"
            aria-hidden="true"
          />
        </button>

        <div
          v-show="sectionIsOpen(section, sectionIndex)"
          :id="`admin-section-${domIdFromKey(sectionKey(section, sectionIndex))}`"
          :class="section.label ? 'mt-2 space-y-1' : 'space-y-1'"
        >
          <div v-for="item in section.items" :key="item.href ?? item.label">
            <button
              v-if="(item.children?.length ?? 0) > 0"
              type="button"
              class="group flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
              :class="itemIsActive(item) ? 'bg-brand/10 text-brand' : 'text-text-primary hover:bg-surface-muted'"
              @click="toggleGroup(item)"
            >
              <span class="flex min-w-0 items-center gap-2">
                <component v-if="item.icon" :is="item.icon" class="h-4 w-4" aria-hidden="true" />
                <span class="truncate">{{ item.label }}</span>
              </span>
              <ChevronDown
                class="h-4 w-4 shrink-0 opacity-70 transition-transform"
                :class="groupIsOpen(item) ? 'rotate-180' : 'rotate-0'"
                aria-hidden="true"
              />
            </button>

            <div v-if="(item.children?.length ?? 0) > 0 && groupIsOpen(item)" class="mt-1 space-y-1 pl-3">
              <Link
                v-for="child in item.children"
                :key="child.href"
                :href="child.href"
                class="group flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
                :class="url.startsWith(child.activeStartsWith ?? child.href) ? 'bg-brand/10 text-brand' : 'text-text-primary hover:bg-surface-muted'"
              >
                <component v-if="child.icon" :is="child.icon" class="h-4 w-4" aria-hidden="true" />
                <span class="truncate">{{ child.label }}</span>
              </Link>
            </div>

            <Link
              v-else-if="item.href"
              :href="item.href"
              class="group flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
              :class="url.startsWith(item.activeStartsWith ?? item.href) ? 'bg-brand/10 text-brand' : 'text-text-primary hover:bg-surface-muted'"
            >
              <component v-if="item.icon" :is="item.icon" class="h-4 w-4" aria-hidden="true" />
              <span class="truncate">{{ item.label }}</span>
            </Link>
          </div>
        </div>
      </div>
    </nav>
  </aside>
</template>
