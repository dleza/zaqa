<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import type { ApplicantNavSection } from '@/Layouts/applicantNav'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import { ChevronDown } from 'lucide-vue-next'

const props = defineProps<{
  sections: ApplicantNavSection[]
  isMobile?: boolean
  badges?: Partial<Record<string, number>>
}>()

const emit = defineEmits<{
  navigate: []
}>()

const page = usePage()
const currentPath = computed(() => {
  const raw = (page.url ?? '/').split('?')[0]
  return raw.length === 0 ? '/' : raw
})

function isActive(href: string) {
  const path = currentPath.value
  if (href === '/applicant/dashboard') return path === href

  // Avoid overlapping matches under /applicant/applications:
  // - Submit Application should only be active on /applicant/applications/new
  // - My Applications should be active on list/detail/edit routes, but NOT /new
  if (href === '/applicant/applications/new') {
    return path === '/applicant/applications/new'
  }
  if (href === '/applicant/applications/multiple/new') {
    return path === '/applicant/applications/multiple/new' || path.startsWith('/applicant/applications/multiple/')
  }
  if (href === '/applicant/applications') {
    return path === '/applicant/applications' || (path.startsWith('/applicant/applications/') && !path.startsWith('/applicant/applications/new') && !path.startsWith('/applicant/applications/multiple/'))
  }

  return path === href || path.startsWith(`${href}/`)
}

function sectionHasActiveRoute(section: ApplicantNavSection): boolean {
  return section.items.some((item) => isActive(item.href))
}

const expanded = ref<Record<string, boolean>>({})

function ensureActiveExpanded() {
  for (const section of props.sections) {
    if (sectionHasActiveRoute(section)) {
      expanded.value[section.key] = true
    }
  }
}

function toggleSection(sectionKey: string) {
  expanded.value[sectionKey] = !expanded.value[sectionKey]
}

onMounted(() => {
  // Default: expanded on desktop for scanability; on mobile, also expanded unless user collapses.
  expanded.value = Object.fromEntries(props.sections.map((s) => [s.key, true]))
  ensureActiveExpanded()
})

watch(currentPath, () => {
  ensureActiveExpanded()
})

const user = computed(() => (page.props as any).auth?.user)
const applicantTypeLabel = computed(() => {
  const t = user.value?.applicant_type
  if (t === 'individual') return 'Individual'
  if (t === 'institution') return 'Institution'
  return null
})

const initials = computed(() => {
  const name = (user.value?.name ?? '').trim()
  if (!name) return 'A'
  const parts = name.split(/\s+/).filter(Boolean)
  const a = parts[0]?.[0] ?? 'A'
  const b = parts.length > 1 ? parts[parts.length - 1]?.[0] : ''
  return `${a}${b}`.toUpperCase()
})
</script>

<template>
  <aside
    class="flex h-full w-[16.5rem] flex-col border-r border-border bg-surface"
    aria-label="Applicant sidebar"
  >
    <div class="px-4 py-4">
      <Link href="/applicant/dashboard" class="flex items-center gap-3 no-underline" @click="emit('navigate')">
        <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-9 w-auto object-contain" />
        <div class="min-w-0">
          <div class="truncate text-sm font-semibold text-text-primary">ZAQA Portal</div>
          <div class="truncate text-xs text-text-muted">Applicant Services</div>
        </div>
      </Link>
    </div>

    <nav class="flex-1 overflow-auto px-3 pb-4" aria-label="Applicant navigation">
      <div v-for="section in sections" :key="section.key" class="mt-4 first:mt-0">
        <button
          type="button"
          class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-xs font-semibold tracking-wider text-text-muted uppercase hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
          :aria-expanded="expanded[section.key] ? 'true' : 'false'"
          :aria-controls="`section-${section.key}`"
          @click="toggleSection(section.key)"
        >
          <span class="flex items-center gap-2">
            <span>{{ section.label }}</span>
          </span>
          <ChevronDown
            class="h-4 w-4 transition-transform"
            :class="expanded[section.key] ? 'rotate-0' : '-rotate-90'"
            aria-hidden="true"
          />
        </button>

        <ul v-show="expanded[section.key]" :id="`section-${section.key}`" class="mt-2 space-y-1">
          <li v-for="item in section.items" :key="item.key">
            <Link
              :href="item.href"
              class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
              :class="
                isActive(item.href)
                  ? 'bg-brand/10 text-brand ring-1 ring-brand/15'
                  : 'text-text-primary hover:bg-surface-muted'
              "
              @click="emit('navigate')"
            >
              <span
                class="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r"
                :class="isActive(item.href) ? 'bg-accent' : 'bg-transparent group-hover:bg-border'"
                aria-hidden="true"
              />

              <component
                :is="item.icon"
                class="h-4 w-4 shrink-0"
                :class="isActive(item.href) ? 'text-brand' : 'text-text-muted group-hover:text-brand'"
                aria-hidden="true"
              />
              <span class="truncate">{{ item.label }}</span>

              <span
                v-if="item.badgeKey && (badges?.[item.badgeKey] ?? 0) > 0"
                class="ml-auto inline-flex items-center rounded-full border border-brand/15 bg-brand/10 px-2 py-0.5 text-[11px] font-semibold text-brand"
                aria-label="Count badge"
              >
                {{ badges?.[item.badgeKey] }}
              </span>

              <span
                v-else-if="isActive(item.href)"
                class="ml-auto h-2 w-2 rounded-full bg-accent"
                aria-hidden="true"
              />
            </Link>
          </li>
        </ul>
      </div>
    </nav>

    <div class="border-t border-border bg-surface px-4 py-4">
      <div class="flex items-center gap-3">
        <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand/10 text-sm font-semibold text-brand ring-1 ring-brand/15">
          {{ initials }}
        </div>

        <div class="min-w-0 flex-1">
          <div class="truncate text-sm font-semibold text-text-primary">
            {{ user?.name ?? 'Applicant' }}
          </div>
          <div class="truncate text-xs text-text-muted">
            <span v-if="applicantTypeLabel">{{ applicantTypeLabel }}</span>
            <span v-if="applicantTypeLabel && user?.email"> • </span>
            <span>{{ user?.email }}</span>
          </div>
        </div>

   
      </div>
    </div>
  </aside>
</template>

