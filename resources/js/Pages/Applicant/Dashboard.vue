<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { computed } from 'vue'
import {
  BadgeCheck,
  ChevronRight,
  CircleAlert,
  ClipboardList,
  CreditCard,
  LayoutDashboard,
  Loader2,
  Plus,
  Route,
  Sparkles,
} from 'lucide-vue-next'

const newApplicationHref = '/applicant/applications/new'

const page = usePage()
const authUserName = computed(() => ((page.props as any).auth?.user?.name ?? '').toString().trim())
const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
})

const props = defineProps<{
  counts: Record<string, number>
  continueDraft: { label: string; href: string; kind: string } | null
  applications: Array<any>
  activity: Array<any>
  alerts: Array<any>
  returnedQualifications: Array<any>
  returnedQualificationsCount: number
  trackingHref: string
}>()

const latestApplication = computed(() => ((props.applications ?? [])[0] as any) ?? null)
const firstReturnedQualification = computed(() => ((props.returnedQualifications ?? [])[0] as any) ?? null)

function isPaymentPending(app: any | null) {
  const status = (app?.invoice?.status ?? '').toString()
  return status !== '' && status !== 'paid'
}

const nextStep = computed(() => {
  if (firstReturnedQualification.value) {
    const count = Number(props.returnedQualificationsCount ?? 0)
    return {
      title: 'Action required',
      badge: 'Sent back',
      icon: CircleAlert,
      tone: 'warning' as const,
      href: firstReturnedQualification.value.href ?? '/applicant/applications',
      subtitle: count === 1 ? 'Update returned qualification' : `Update ${count} returned qualifications`,
    }
  }

  if (props.continueDraft) {
    return {
      title: 'Continue draft',
      badge: 'Draft',
      icon: ClipboardList,
      tone: 'brand' as const,
      href: props.continueDraft.href,
      subtitle: 'Complete details and proceed to payment',
    }
  }

  if (latestApplication.value && isPaymentPending(latestApplication.value)) {
    return {
      title: 'Complete payment',
      badge: 'Payment',
      icon: CreditCard,
      tone: 'warning' as const,
      href: `/applicant/applications/${latestApplication.value.id}`,
      subtitle: latestApplication.value.application_number ?? 'Open latest application',
    }
  }

  if (latestApplication.value) {
    const s = (latestApplication.value.current_status ?? '').toString()
    if (s === 'approved' || s === 'rejected' || s === 'completed' || s === 'certificate_ready') {
      return {
        title: 'View outcome',
        badge: 'Completed',
        icon: BadgeCheck,
        tone: 'success' as const,
        href: `/applicant/applications/${latestApplication.value.id}`,
        subtitle: latestApplication.value.application_number ?? 'Open latest application',
      }
    }

    return {
      title: 'Track application',
      badge: latestApplication.value.status_label ?? 'In progress',
      icon: Route,
      tone: 'surface' as const,
      href: props.trackingHref,
      subtitle: latestApplication.value.application_number ?? 'View progress',
    }
  }

  return {
    title: 'Start new application',
    badge: 'New',
    icon: Sparkles,
    tone: 'brand' as const,
    href: newApplicationHref,
    subtitle: 'Create your first verification request',
  }
})

const qualificationsBase = '/applicant/qualifications'

const statusCards = computed(() => [
  { key: 'total', label: 'Total', href: `${qualificationsBase}?filter=total`, icon: LayoutDashboard, count: props.counts?.total ?? 0, tone: 'surface' as const },
  { key: 'draft', label: 'Draft', href: `${qualificationsBase}?filter=draft`, icon: ClipboardList, count: props.counts?.draft ?? 0, tone: 'warning' as const },
  { key: 'processing', label: 'Processing', href: `${qualificationsBase}?filter=processing`, icon: Loader2, count: props.counts?.processing ?? 0, tone: 'info' as const },
  { key: 'sent_back', label: 'Sent back', href: `${qualificationsBase}?filter=sent_back`, icon: CircleAlert, count: props.counts?.sent_back ?? 0, tone: 'warning' as const },
  { key: 'completed', label: 'Completed', href: `${qualificationsBase}?filter=completed`, icon: BadgeCheck, count: props.counts?.completed ?? 0, tone: 'success' as const },
])
</script>

<template>
  <ApplicantLayout wide>
    <div class="w-full">
      <!-- Greeting + primary CTA -->
      <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
          <div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
            <LayoutDashboard class="h-4 w-4" aria-hidden="true" />
            Dashboard
          </div>
          <h2 class="mt-2 text-3xl font-semibold tracking-tight text-text-primary sm:text-4xl">
            {{ greeting }}{{ authUserName ? ` ${authUserName}` : '' }}.
          </h2>
        </div>

        <div class="w-full shrink-0 sm:w-auto">
          <Link
            :href="newApplicationHref"
            class="zaqa-btn zaqa-btn-primary inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl px-6 text-base font-semibold shadow-sm transition hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 sm:w-auto"
          >
            <Plus class="h-5 w-5 shrink-0" aria-hidden="true" />
            Start new application
          </Link>
        </div>
      </div>

      <!-- Primary tracking action -->
      <Link
        :href="trackingHref"
        class="group relative mt-6 block overflow-hidden rounded-2xl border border-[#0073BA]/25 bg-gradient-to-br from-[#0073BA]/[0.08] via-surface to-surface p-5 shadow-sm ring-1 ring-black/[0.04] transition hover:-translate-y-[1px] hover:border-[#0073BA]/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0073BA]/40 sm:p-6"
      >
        <div
          class="pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full bg-[#EF7D00]/10 blur-2xl"
          aria-hidden="true"
        />
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex min-w-0 items-start gap-4">
            <span
              class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-[#0073BA]/20 bg-[#0073BA]/10 text-[#0073BA]"
              aria-hidden="true"
            >
              <Route class="h-7 w-7" />
            </span>
            <div class="min-w-0">
              <div class="text-lg font-semibold text-text-primary sm:text-xl">Track application</div>
              <p class="mt-1 text-sm text-text-muted">
                Track applications and each qualification under them.
              </p>
            </div>
          </div>
          <span
            class="inline-flex h-11 shrink-0 items-center justify-center gap-2 self-start rounded-xl bg-[#0073BA] px-5 text-sm font-semibold text-white shadow-sm transition group-hover:bg-[#0066a5] sm:self-center"
          >
            View progress
            <ChevronRight class="h-4 w-4 transition group-hover:translate-x-0.5" aria-hidden="true" />
          </span>
        </div>
      </Link>

      <!-- Status summary -->
      <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 lg:gap-4">
        <Link
          v-for="c in statusCards"
          :key="c.key"
          :href="c.href"
          class="group flex items-center gap-2.5 rounded-xl border border-border bg-surface p-3 shadow-sm ring-1 ring-black/[0.04] transition hover:border-brand/25 hover:bg-surface-muted hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 sm:gap-3 sm:p-4"
        >
          <span
            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border sm:h-10 sm:w-10 sm:rounded-2xl"
            :class="
              c.tone === 'success'
                ? 'border-success/20 bg-success/10 text-success'
                : c.tone === 'warning'
                  ? 'border-warning/25 bg-warning/10 text-warning'
                  : c.tone === 'info'
                    ? 'border-brand/20 bg-brand/10 text-brand'
                    : 'border-border bg-surface-muted text-text-muted'
            "
            aria-hidden="true"
          >
            <component :is="c.icon" class="h-4 w-4 sm:h-5 sm:w-5" />
          </span>
          <div class="min-w-0">
            <div class="text-base font-semibold tabular-nums text-text-primary sm:text-lg">{{ c.count }}</div>
            <div class="truncate text-[11px] font-semibold uppercase tracking-wider text-text-muted sm:text-xs">{{ c.label }}</div>
          </div>
        </Link>
      </div>

      <!-- Next step + alerts -->
      <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Link
          :href="nextStep.href"
          class="group block overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04] transition hover:-translate-y-[1px] hover:border-brand/25 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
        >
          <div class="border-b border-border bg-surface-muted px-5 py-3.5">
            <div class="flex items-center justify-between gap-3">
              <div class="text-sm font-semibold text-text-primary">Next step</div>
              <ChevronRight class="h-5 w-5 text-text-muted transition group-hover:translate-x-0.5" aria-hidden="true" />
            </div>
          </div>
          <div class="px-5 py-4">
            <div class="flex items-start gap-3">
              <span
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border"
                :class="
                  nextStep.tone === 'success'
                    ? 'border-success/20 bg-success/10 text-success'
                    : nextStep.tone === 'warning'
                      ? 'border-warning/25 bg-warning/10 text-warning'
                      : nextStep.tone === 'brand'
                        ? 'border-brand/20 bg-brand/10 text-brand'
                        : 'border-border bg-surface-muted text-text-muted'
                "
                aria-hidden="true"
              >
                <component :is="nextStep.icon" class="h-5 w-5" />
              </span>
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <div class="text-sm font-semibold text-text-primary">{{ nextStep.title }}</div>
                  <span class="zaqa-badge">{{ nextStep.badge }}</span>
                </div>
                <div class="mt-1 text-sm text-text-muted">{{ nextStep.subtitle }}</div>
              </div>
            </div>
          </div>
        </Link>

        <div v-if="(alerts?.length ?? 0) > 0" class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04]">
          <div class="border-b border-border bg-surface-muted px-5 py-3.5">
            <div class="text-sm font-semibold text-text-primary">Alerts</div>
          </div>
          <div class="divide-y divide-border/60">
            <Link
              v-for="a in (alerts ?? []).slice(0, 2)"
              :key="a.title + a.message"
              :href="a.href || '/applicant/applications'"
              class="group flex items-start justify-between gap-3 px-5 py-3.5 transition hover:bg-surface-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
            >
              <div class="min-w-0">
                <div class="text-sm font-semibold text-text-primary">{{ a.title }}</div>
                <div class="mt-0.5 text-xs text-text-muted line-clamp-2">{{ a.message }}</div>
              </div>
              <ChevronRight class="h-5 w-5 shrink-0 text-text-muted transition group-hover:translate-x-0.5" aria-hidden="true" />
            </Link>
          </div>
        </div>

        <div v-else class="rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/[0.04]">
          <div class="flex items-start gap-3">
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-border bg-surface-muted text-text-muted" aria-hidden="true">
              <Sparkles class="h-5 w-5" />
            </span>
            <div class="min-w-0">
              <div class="text-sm font-semibold text-text-primary">All clear</div>
              <div class="mt-0.5 text-sm text-text-muted">No urgent actions.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
