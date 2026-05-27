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
  Eye,
  FileText,
  LayoutDashboard,
  Send,
  Sparkles,
} from 'lucide-vue-next'

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
}>()

const recentApplications = computed(() => (props.applications ?? []).slice(0, 5))
const hasApplications = computed(() => recentApplications.value.length > 0)
const latestApplication = computed(() => recentApplications.value[0] ?? null)
const firstReturnedQualification = computed(() => ((props.returnedQualifications ?? [])[0] as any) ?? null)

function formatWhen(iso: string | undefined) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(d)
}

function badgeClass(status: string) {
  const s = (status ?? '').toString()
  if (s === 'draft') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'sent_back') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'submitted' || s === 'resubmitted' || s === 'in_progress') return 'zaqa-badge zaqa-badge-info'
  if (s === 'approved') return 'zaqa-badge zaqa-badge-success'
  if (s === 'rejected') return 'zaqa-badge zaqa-badge-danger'
  return 'zaqa-badge'
}

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
    if (s === 'approved') {
      return {
        title: 'View outcome',
        badge: 'Approved',
        icon: BadgeCheck,
        tone: 'success' as const,
        href: `/applicant/applications/${latestApplication.value.id}`,
        subtitle: latestApplication.value.application_number ?? 'Open latest application',
      }
    }

    return {
      title: 'Track status',
      badge: latestApplication.value.status_label ?? 'In progress',
      icon: Eye,
      tone: 'surface' as const,
      href: `/applicant/applications/${latestApplication.value.id}/track`,
      subtitle: latestApplication.value.application_number ?? 'Open latest application',
    }
  }

  return {
    title: 'Start new application',
    badge: 'New',
    icon: Sparkles,
    tone: 'brand' as const,
    href: '/applicant/applications/new',
    subtitle: 'Create your first verification request',
  }
})

const actionCards = computed(() => {
  const latestId = latestApplication.value?.id
  return [
    { label: 'New application', href: '/applicant/applications/new', icon: ClipboardList, tone: 'primary' as const },
    { label: 'Track status', href: latestId ? `/applicant/applications/${latestId}/track` : '/applicant/applications', icon: Eye, tone: 'surface' as const },
    { label: 'My applications', href: '/applicant/applications', icon: FileText, tone: 'surface' as const },
    { label: 'Invoices', href: '/applicant/invoices', icon: CreditCard, tone: 'surface' as const },
  ]
})

const statusCards = computed(() => [
  { key: 'total', label: 'Total', icon: LayoutDashboard, count: props.counts?.total ?? 0, tone: 'surface' as const },
  { key: 'draft', label: 'Draft', icon: ClipboardList, count: props.counts?.draft ?? 0, tone: 'warning' as const },
  { key: 'submitted', label: 'Submitted', icon: Send, count: props.counts?.submitted ?? 0, tone: 'info' as const },
  { key: 'sent_back', label: 'Sent back', icon: CircleAlert, count: props.counts?.sent_back ?? 0, tone: 'warning' as const },
  { key: 'approved', label: 'Approved', icon: BadgeCheck, count: props.counts?.approved ?? 0, tone: 'success' as const },
])
</script>

<template>
  <ApplicantLayout>
    <div class="mx-auto w-full max-w-7xl">
      <!-- Greeting -->
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
          <div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
            <LayoutDashboard class="h-4 w-4" aria-hidden="true" />
            Dashboard
          </div>
          <h2 class="mt-2 text-3xl font-semibold tracking-tight text-text-primary sm:text-4xl">
            {{ greeting }}{{ authUserName ? ` ${authUserName}` : '' }}.
          </h2>
          <p class="mt-2 text-sm text-text-muted">Track your applications, payments, and verification status.</p>
        </div>
      </div>

      <!-- Primary actions -->
      <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Link
          v-for="a in actionCards"
          :key="a.label"
          :href="a.href"
          class="group relative overflow-hidden rounded-2xl border border-border bg-surface p-4 shadow-sm ring-1 ring-black/[0.04] transition hover:-translate-y-[1px] hover:border-brand/25 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 sm:p-5"
          :class="a.tone === 'primary' ? 'border-brand/20 bg-gradient-to-br from-brand/[0.10] via-surface to-surface' : ''"
        >
          <div class="flex items-center justify-between gap-3">
            <span
              class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border"
              :class="a.tone === 'primary' ? 'border-brand/20 bg-brand/10 text-brand' : 'border-border bg-surface-muted text-text-muted'"
              aria-hidden="true"
            >
              <component :is="a.icon" class="h-5 w-5" />
            </span>
            <ChevronRight class="h-5 w-5 text-text-muted transition group-hover:translate-x-0.5" aria-hidden="true" />
          </div>
          <div class="mt-3 text-sm font-semibold text-text-primary">{{ a.label }}</div>
        </Link>
      </div>

      <!-- Status summary -->
      <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <Link
          v-for="c in statusCards"
          :key="c.key"
          href="/applicant/applications"
          class="group flex items-center gap-3 rounded-2xl border border-border bg-surface p-4 shadow-sm ring-1 ring-black/[0.04] transition hover:border-brand/25 hover:bg-surface-muted hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
        >
          <span
            class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border"
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
            <component :is="c.icon" class="h-5 w-5" />
          </span>
          <div class="min-w-0">
            <div class="text-lg font-semibold tabular-nums text-text-primary">{{ c.count }}</div>
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ c.label }}</div>
          </div>
        </Link>
      </div>

      <!-- Main grid -->
      <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
        <!-- Recent applications -->
        <section class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04] lg:col-span-8">
          <div class="flex items-center justify-between gap-3 border-b border-border bg-surface-muted px-5 py-4 sm:px-6">
            <div class="text-sm font-semibold text-text-primary">Recent applications</div>
            <Link href="/applicant/applications" class="zaqa-link text-sm font-semibold">View all</Link>
          </div>

          <div v-if="!hasApplications" class="px-5 py-8 sm:px-6">
            <div class="flex items-start gap-4 rounded-2xl border border-dashed border-border bg-surface-muted/40 p-5">
              <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10 text-brand" aria-hidden="true">
                <ClipboardList class="h-5 w-5" />
              </span>
              <div class="min-w-0">
                <div class="text-sm font-semibold text-text-primary">No applications yet</div>
                <div class="mt-1 text-sm text-text-muted">Start your first verification request.</div>
                <div class="mt-4">
                  <Link href="/applicant/applications/new" class="zaqa-btn zaqa-btn-primary h-11 px-6">New application</Link>
                </div>
              </div>
            </div>
          </div>

          <ul v-else class="divide-y divide-border/60">
            <li v-for="app in recentApplications" :key="app.id">
              <Link
                :href="app.primary_action?.href ?? `/applicant/applications/${app.id}`"
                class="group flex items-center justify-between gap-3 px-5 py-4 transition hover:bg-surface-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 sm:px-6"
              >
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <div class="text-sm font-semibold text-text-primary">{{ app.application_number }}</div>
                    <span :class="badgeClass(app.current_status)">{{ app.status_label }}</span>
                    <span v-if="Number(app.qualification_count ?? app.qualifications?.length ?? 0) > 0" class="zaqa-badge">
                      {{ Number(app.qualification_count ?? app.qualifications?.length ?? 0) }}
                    </span>
                    <span v-if="app.amend_action" class="zaqa-badge zaqa-badge-warning">Action</span>
                  </div>
                  <div class="mt-1 text-xs text-text-muted">Updated {{ formatWhen(app.updated_at ?? app.created_at) }}</div>
                </div>

                <div class="flex items-center gap-2">
                  <CreditCard v-if="isPaymentPending(app)" class="h-4 w-4 text-warning" aria-hidden="true" />
                  <ChevronRight class="h-5 w-5 text-text-muted transition group-hover:translate-x-0.5" aria-hidden="true" />
                </div>
              </Link>
            </li>
          </ul>
        </section>

        <!-- Next step -->
        <aside class="space-y-4 lg:col-span-4">
          <Link
            :href="nextStep.href"
            class="group block overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04] transition hover:-translate-y-[1px] hover:border-brand/25 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
          >
            <div class="border-b border-border bg-surface-muted px-5 py-4">
              <div class="flex items-center justify-between gap-3">
                <div class="text-sm font-semibold text-text-primary">Next step</div>
                <ChevronRight class="h-5 w-5 text-text-muted transition group-hover:translate-x-0.5" aria-hidden="true" />
              </div>
            </div>
            <div class="px-5 py-5">
              <div class="flex items-start gap-4">
                <span
                  class="inline-flex h-12 w-12 items-center justify-center rounded-2xl border"
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
                  <component :is="nextStep.icon" class="h-6 w-6" />
                </span>
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <div class="text-base font-semibold text-text-primary">{{ nextStep.title }}</div>
                    <span class="zaqa-badge">{{ nextStep.badge }}</span>
                  </div>
                  <div class="mt-2 text-sm text-text-muted">{{ nextStep.subtitle }}</div>
                </div>
              </div>
            </div>
          </Link>

          <div v-if="(alerts?.length ?? 0) > 0" class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04]">
            <div class="border-b border-border bg-surface-muted px-5 py-4">
              <div class="text-sm font-semibold text-text-primary">Alerts</div>
            </div>
            <div class="divide-y divide-border/60">
              <Link
                v-for="a in (alerts ?? []).slice(0, 2)"
                :key="a.title + a.message"
                :href="a.href || '/applicant/applications'"
                class="group flex items-start justify-between gap-3 px-5 py-4 transition hover:bg-surface-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
              >
                <div class="min-w-0">
                  <div class="text-sm font-semibold text-text-primary">{{ a.title }}</div>
                  <div class="mt-1 text-xs text-text-muted line-clamp-2">{{ a.message }}</div>
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
                <div class="mt-1 text-sm text-text-muted">No urgent actions right now.</div>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </ApplicantLayout>
</template>
