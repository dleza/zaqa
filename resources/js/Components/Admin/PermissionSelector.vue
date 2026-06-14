<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue'
import { ChevronDown, Search, ShieldCheck, X } from 'lucide-vue-next'

type PermissionInput = { name: string } | string

type ModuleKey =
  | 'admin'
  | 'dashboard'
  | 'finance'
  | 'verification'
  | 'learner_records'
  | 'institution_api'
  | 'reports'
  | 'settings'
  | 'sms'
  | 'other'

const props = withDefaults(defineProps<{
  permissions: PermissionInput[]
  modelValue?: string[]
  readOnly?: boolean
  searchPlaceholder?: string
  emptyMessage?: string
}>(), {
  modelValue: () => [],
  readOnly: false,
  searchPlaceholder: 'Search permissions...',
  emptyMessage: 'No permissions match your search.',
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: string[]): void
}>()

const search = ref('')
const expandedGroups = ref<Record<string, boolean>>({})

const moduleMeta: Record<ModuleKey, { label: string; description: string; order: number }> = {
  dashboard: {
    label: 'Dashboard',
    description: 'Portal entry and overview access.',
    order: 0,
  },
  admin: {
    label: 'Admin',
    description: 'Core admin management and operational controls.',
    order: 1,
  },
  finance: {
    label: 'Finance',
    description: 'Payments, proofs, receipts and finance reports.',
    order: 2,
  },
  verification: {
    label: 'Verification',
    description: 'Qualification review, workflow and decision actions.',
    order: 3,
  },
  learner_records: {
    label: 'Learner Records',
    description: 'Learner record import and lookup tools.',
    order: 4,
  },
  institution_api: {
    label: 'Institution API',
    description: 'Institution integration clients, logs and API controls.',
    order: 5,
  },
  reports: {
    label: 'Reports',
    description: 'Reporting dashboards and exports.',
    order: 6,
  },
  settings: {
    label: 'Settings',
    description: 'Reference data, fees and system settings.',
    order: 7,
  },
  sms: {
    label: 'SMS',
    description: 'SMS balance, messaging and log access.',
    order: 8,
  },
  other: {
    label: 'Other',
    description: 'Permissions that do not match a known module.',
    order: 99,
  },
}

const normalizedPermissions = computed(() => props.permissions
  .map((permission) => typeof permission === 'string' ? permission : permission.name)
  .filter((permission): permission is string => Boolean(permission))
  .sort((left, right) => left.localeCompare(right)))

const selectedSet = computed(() => new Set(props.modelValue ?? []))
const selectedCount = computed(() => selectedSet.value.size)

function humanizeToken(token: string) {
  return token
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function moduleKeyFor(permission: string): ModuleKey {
  const prefix = permission.split('.')[0] ?? 'other'

  if (prefix in moduleMeta) {
    return prefix as ModuleKey
  }

  return 'other'
}

function permissionHint(permission: string) {
  const parts = permission.split('.').slice(1)
  if (parts.length === 0) {
    return 'General access'
  }

  return parts.map(humanizeToken).join(' · ')
}

const groupedPermissions = computed(() => {
  const query = search.value.trim().toLowerCase()
  const groups = new Map<ModuleKey, {
    key: ModuleKey
    label: string
    description: string
    items: Array<{ name: string; hint: string }>
  }>()

  for (const permission of normalizedPermissions.value) {
    const key = moduleKeyFor(permission)
    const meta = moduleMeta[key]
    const hint = permissionHint(permission)
    const haystack = `${permission} ${hint} ${meta.label}`.toLowerCase()

    if (query !== '' && !haystack.includes(query)) {
      continue
    }

    if (!groups.has(key)) {
      groups.set(key, {
        key,
        label: meta.label,
        description: meta.description,
        items: [],
      })
    }

    groups.get(key)?.items.push({ name: permission, hint })
  }

  return Array.from(groups.values())
    .sort((left, right) => moduleMeta[left.key].order - moduleMeta[right.key].order)
})

const visiblePermissionCount = computed(() => groupedPermissions.value.reduce((sum, group) => sum + group.items.length, 0))

watchEffect(() => {
  for (const group of groupedPermissions.value) {
    if (expandedGroups.value[group.key] === undefined) {
      expandedGroups.value[group.key] = true
    }
  }
})

function updateSelection(next: Set<string>) {
  emit('update:modelValue', Array.from(next).sort((left, right) => left.localeCompare(right)))
}

function togglePermission(permission: string) {
  if (props.readOnly) return

  const next = new Set(selectedSet.value)
  if (next.has(permission)) {
    next.delete(permission)
  } else {
    next.add(permission)
  }

  updateSelection(next)
}

function selectAllInGroup(items: Array<{ name: string }>) {
  if (props.readOnly) return

  const next = new Set(selectedSet.value)
  for (const item of items) {
    next.add(item.name)
  }

  updateSelection(next)
}

function clearGroup(items: Array<{ name: string }>) {
  if (props.readOnly) return

  const next = new Set(selectedSet.value)
  for (const item of items) {
    next.delete(item.name)
  }

  updateSelection(next)
}

function clearAll() {
  if (props.readOnly) return
  emit('update:modelValue', [])
}

function toggleGroup(groupKey: string) {
  expandedGroups.value[groupKey] = !expandedGroups.value[groupKey]
}

function itemId(groupKey: string, permission: string) {
  const slug = permission.replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase()
  return `permission-${groupKey}-${slug}`
}
</script>

<template>
    <div class="space-y-4 min-w-0">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <label class="relative block w-full lg:max-w-md">
        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
        <input
          v-model="search"
          type="search"
          class="zaqa-input h-11 w-full pl-10"
          :placeholder="searchPlaceholder"
          aria-label="Search permissions"
        />
      </label>

      <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center rounded-full border border-border/70 bg-surface-muted/45 px-3 py-1 text-xs font-semibold text-text-primary">
          {{ visiblePermissionCount }} shown
        </span>
        <span
          v-if="!readOnly"
          class="inline-flex items-center rounded-full border border-brand/15 bg-brand/10 px-3 py-1 text-xs font-semibold text-brand"
        >
          Selected: {{ selectedCount }}
        </span>
        <button
          v-if="!readOnly && selectedCount > 0"
          type="button"
          class="inline-flex items-center gap-1 rounded-full border border-border/70 bg-surface px-3 py-1 text-xs font-semibold text-text-primary transition hover:border-border hover:bg-surface-muted"
          @click="clearAll"
        >
          <X class="h-3.5 w-3.5" aria-hidden="true" />
          Clear selected
        </button>
      </div>
    </div>

    <div v-if="groupedPermissions.length === 0" class="rounded-2xl border border-dashed border-border bg-surface-muted/35 px-5 py-10 text-center">
      <ShieldCheck class="mx-auto h-9 w-9 text-text-muted" aria-hidden="true" />
      <div class="mt-3 text-sm font-semibold text-text-primary">No permissions found</div>
      <div class="mt-1 text-sm text-text-muted">{{ emptyMessage }}</div>
    </div>

    <div v-else class="space-y-4">
      <section
        v-for="group in groupedPermissions"
        :key="group.key"
        class="rounded-2xl border border-border/70 bg-surface shadow-sm"
      >
        <div class="border-b border-border/60 px-5 py-4">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <button
              type="button"
              class="flex min-w-0 flex-1 items-start justify-between gap-3 text-left"
              :aria-expanded="expandedGroups[group.key] ? 'true' : 'false'"
              @click="toggleGroup(group.key)"
            >
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <div class="text-sm font-semibold text-text-primary">{{ group.label }}</div>
                  <span class="inline-flex items-center rounded-full border border-border/70 bg-surface-muted/45 px-2.5 py-0.5 text-[11px] font-semibold text-text-muted">
                    {{ group.items.length }}
                  </span>
                </div>
                <div class="mt-1 text-xs text-text-muted">{{ group.description }}</div>
              </div>
              <ChevronDown
                class="mt-0.5 h-4 w-4 shrink-0 text-text-muted transition"
                :class="expandedGroups[group.key] ? 'rotate-180' : ''"
                aria-hidden="true"
              />
            </button>

            <div v-if="!readOnly" class="flex flex-wrap items-center gap-2 lg:justify-end">
              <button
                type="button"
                class="rounded-full border border-border/70 bg-surface px-3 py-1 text-xs font-semibold text-text-primary transition hover:border-border hover:bg-surface-muted"
                @click="selectAllInGroup(group.items)"
              >
                Select all
              </button>
              <button
                type="button"
                class="rounded-full border border-border/70 bg-surface px-3 py-1 text-xs font-semibold text-text-primary transition hover:border-border hover:bg-surface-muted"
                @click="clearGroup(group.items)"
              >
                Clear
              </button>
            </div>
          </div>
        </div>

        <div v-show="expandedGroups[group.key]" class="p-5">
          <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
            <template v-for="permission in group.items" :key="permission.name">
              <label
                v-if="!readOnly"
                :for="itemId(group.key, permission.name)"
                class="block min-w-0 cursor-pointer"
              >
                <input
                  :id="itemId(group.key, permission.name)"
                  type="checkbox"
                  class="peer sr-only"
                  :checked="selectedSet.has(permission.name)"
                  @change="togglePermission(permission.name)"
                />
                <span
                  class="block h-full rounded-2xl border px-4 py-3 transition peer-focus:ring-2 peer-focus:ring-brand/30"
                  :class="selectedSet.has(permission.name)
                    ? 'border-brand/25 bg-brand/10 shadow-[0_10px_28px_-22px_rgba(0,118,189,0.65)]'
                    : 'border-border/70 bg-surface-muted/25 hover:border-border hover:bg-surface-muted/45'"
                >
                  <span class="flex items-start justify-between gap-3">
                    <span class="min-w-0">
                      <span class="block break-words text-sm font-semibold text-text-primary">{{ permission.name }}</span>
                      <span class="mt-1 block text-xs text-text-muted">{{ permission.hint }}</span>
                    </span>
                    <span
                      class="inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold"
                      :class="selectedSet.has(permission.name)
                        ? 'border-brand/15 bg-brand/15 text-brand'
                        : 'border-border/70 bg-surface text-text-muted'"
                    >
                      {{ selectedSet.has(permission.name) ? 'On' : 'Off' }}
                    </span>
                  </span>
                </span>
              </label>

              <div
                v-else
                class="min-w-0 rounded-2xl border border-border/70 bg-surface-muted/25 px-4 py-3"
              >
                <div class="break-words text-sm font-semibold text-text-primary">{{ permission.name }}</div>
                <div class="mt-1 text-xs text-text-muted">{{ permission.hint }}</div>
              </div>
            </template>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
