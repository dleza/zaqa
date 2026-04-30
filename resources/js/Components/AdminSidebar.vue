<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import type { AdminNavSection } from '@/Layouts/adminNav'

const props = defineProps<{
  sections: AdminNavSection[]
  permissions: string[]
  isMobile?: boolean
}>()

const zaqaLogoUrl = new URL('../../images/zaqa-logo-tranparent.png', import.meta.url).href

const page = usePage()
const url = computed(() => (page.url ?? '').toString())
const openGroups = computed<Record<string, boolean>>(() => {
  try {
    const raw = localStorage.getItem('zaqa_admin_sidebar_groups')
    return raw ? (JSON.parse(raw) as Record<string, boolean>) : {}
  } catch {
    return {}
  }
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

function toggleGroup(key: string) {
  const current = { ...(openGroups.value ?? {}) }
  current[key] = !current[key]
  try {
    localStorage.setItem('zaqa_admin_sidebar_groups', JSON.stringify(current))
  } catch {}
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
      <div v-for="section in visibleSections" :key="section.label" class="mb-5">
        <div v-if="section.label" class="px-2 text-[11px] font-semibold uppercase tracking-wider text-text-muted">
          {{ section.label }}
        </div>
        <div :class="section.label ? 'mt-2 space-y-1' : 'space-y-1'">
          <div v-for="item in section.items" :key="item.href ?? item.label">
            <button
              v-if="(item.children?.length ?? 0) > 0"
              type="button"
              class="group flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
              :class="itemIsActive(item) ? 'bg-brand/10 text-brand' : 'text-text-primary hover:bg-surface-muted'"
              @click="toggleGroup(item.label)"
            >
              <span class="flex min-w-0 items-center gap-2">
                <component v-if="item.icon" :is="item.icon" class="h-4 w-4" aria-hidden="true" />
                <span class="truncate">{{ item.label }}</span>
              </span>
              <span class="text-xs font-bold opacity-70">{{ (openGroups?.[item.label] ?? itemIsActive(item)) ? '—' : '+' }}</span>
            </button>

            <div v-if="(item.children?.length ?? 0) > 0 && (openGroups?.[item.label] ?? itemIsActive(item))" class="mt-1 space-y-1 pl-3">
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
