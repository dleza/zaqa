<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import type { AdminNavSection } from '@/Layouts/adminNav'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import { ChevronDown, PanelLeftClose, PanelLeftOpen } from 'lucide-vue-next'

const props = defineProps<{
  sections: AdminNavSection[]
  permissions: string[]
  isMobile?: boolean
}>()

const page = usePage()
const url = computed(() => (page.url ?? '').toString())

const openSections = ref<Record<string, boolean>>({})
const openGroups = ref<Record<string, boolean>>({})
const isCollapsed = ref(false)

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
  if (!props.isMobile) {
    try {
      isCollapsed.value = localStorage.getItem('zaqa_admin_sidebar_collapsed') === '1'
    } catch {}
  }

  // Ensure stored open state doesn't keep multiple non-active sections/groups expanded.
  syncOpenPanelsToActiveRoute()
})

function syncOpenPanelsToActiveRoute() {
  const nextSections: Record<string, boolean> = { ...openSections.value }
  visibleSections.value.forEach((s, i) => {
    if (!s.label) return
    const k = sectionKey(s, i)
    nextSections[k] = sectionHasActiveRoute(s)
  })
  openSections.value = nextSections
  persistOpenState('zaqa_admin_sidebar_sections', openSections.value)

  // Also collapse any item groups that aren't active (keeps navigation usable on small screens/heights).
  const nextGroups: Record<string, boolean> = { ...openGroups.value }
  visibleSections.value.forEach((s) => {
    ;(s.items ?? []).forEach((item: any) => {
      if ((item.children?.length ?? 0) === 0) return
      const key = (item?.label ?? '').toString()
      if (!key) return
      nextGroups[key] = itemIsActive(item)
    })
  })
  openGroups.value = nextGroups
  persistOpenState('zaqa_admin_sidebar_groups', openGroups.value)
}

function persistCollapsed(next: boolean) {
  isCollapsed.value = next
  if (props.isMobile) return
  try {
    localStorage.setItem('zaqa_admin_sidebar_collapsed', next ? '1' : '0')
  } catch {}
}

function hasAny(required?: string[]) {
  if (!required || required.length === 0) return true
  const set = new Set(props.permissions ?? [])
  return required.some((p) => set.has(p))
}

function itemIsActive(item: any) {
  const start = item.activeStartsWith ?? item.href
  if (start && url.value.startsWith(start)) return true
  const children = item.children ?? []
  if ((children?.length ?? 0) > 0) {
    return children.some((c: any) => itemIsActive(c))
  }
  return false
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
  // UX: keep sidebar tidy — only one non-active section open at a time.
  // Always keep the section containing the current route open.
  const nextState: Record<string, boolean> = { ...openSections.value }
  visibleSections.value.forEach((s, i) => {
    if (!s.label) return
    const k = sectionKey(s, i)
    if (k === key) {
      nextState[k] = next
      return
    }
    nextState[k] = sectionHasActiveRoute(s)
  })
  openSections.value = nextState
  persistOpenState('zaqa_admin_sidebar_sections', openSections.value)
}

function groupIsOpen(item: any) {
  if (itemIsActive(item)) return true
  const key = (item?.label ?? '').toString()
  const stored = openGroups.value[key]
  return stored ?? false
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

watch(
  url,
  () => {
    // On route changes, auto-close panels that are not active (keeps navigation usable).
    syncOpenPanelsToActiveRoute()
  },
  { flush: 'post', immediate: true },
)
</script>

<template>
  <aside class="flex h-full border-r border-border bg-surface" :class="isCollapsed ? 'w-[4.5rem]' : 'w-[18rem]'">
    <div class="flex min-w-0 flex-1 flex-col">
      <div class="border-b border-border px-4 py-4">
        <div class="flex items-center justify-between gap-3">
          <Link href="/admin/dashboard" class="flex min-w-0 items-center gap-3 no-underline">
            <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-9 w-auto shrink-0 object-contain" />
            <div v-if="!isCollapsed" class="min-w-0">
              <div class="truncate text-sm font-semibold text-text-primary">ZAQA Portal</div>
              <div class="truncate text-xs text-text-muted">Admin Services</div>
            </div>
          </Link>

          <button
            v-if="!isMobile"
            type="button"
            class="zaqa-btn zaqa-btn-secondary px-2 py-2 text-xs"
            :aria-label="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            :title="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            @click="persistCollapsed(!isCollapsed)"
          >
            <PanelLeftOpen v-if="isCollapsed" class="h-4 w-4" aria-hidden="true" />
            <PanelLeftClose v-else class="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      </div>

      <!-- Scrollable nav area -->
      <nav class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden overscroll-y-contain px-3 py-4">
        <div v-for="(section, sectionIndex) in visibleSections" :key="section.label || sectionIndex" class="mb-5">
          <button
            v-if="section.label && !isCollapsed"
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
            v-show="isCollapsed ? true : sectionIsOpen(section, sectionIndex)"
            :id="`admin-section-${domIdFromKey(sectionKey(section, sectionIndex))}`"
            :class="section.label && !isCollapsed ? 'mt-2 space-y-1' : 'space-y-1'"
          >
            <div v-for="item in section.items" :key="item.href ?? item.label">
              <button
                v-if="(item.children?.length ?? 0) > 0 && !isCollapsed"
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

              <div v-if="(item.children?.length ?? 0) > 0 && groupIsOpen(item) && !isCollapsed" class="mt-1 space-y-1 pl-3">
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
                :title="isCollapsed ? item.label : undefined"
                :aria-label="isCollapsed ? item.label : undefined"
              >
                <component v-if="item.icon" :is="item.icon" class="h-4 w-4" aria-hidden="true" />
                <span v-if="!isCollapsed" class="truncate">{{ item.label }}</span>
              </Link>
            </div>
          </div>
        </div>
      </nav>
    </div>
  </aside>
</template>
