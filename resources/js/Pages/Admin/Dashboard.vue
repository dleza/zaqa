<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
  Activity,
  AlertCircle,
  Award,
  Banknote,
  Book,
  Building,
  Building2,
  Check,
  CheckCircle2,
  ClipboardList,
  Coins,
  Files,
  Globe,
  Gavel,
  Inbox,
  Layers,
  LayoutDashboard,
  MessageSquare,
  Receipt,
  RefreshCw,
  Scale,
  ScrollText,
  Search,
  Shield,
  ShieldCheck,
  Sparkles,
  Timer,
  TrendingUp,
  Undo2,
  User,
  UserCheck,
  UserPlus,
  Users,
  FileText,
} from 'lucide-vue-next'
import type { Component } from 'vue'
import { formatMoneyFromCents } from '@/utils/money'

const props = defineProps<{
  meta: {
    greeting_line: string
    subtitle: string
    primary_role: string
    current_date_formatted: string
    timezone: string
    dashboard_scope?: 'level1_assigned' | 'level2_qualifications' | 'finance' | 'default'
    l2_metrics_explainer?: string | null
    date_range?: {
      selected: number
      from: string
      to: string
      label: string
      options: Array<{ label: string; value: number }>
    }
  }
  kpis: Array<{
    key: string
    label: string
    value: number
    icon?: string
    hint?: string
    href?: string | null
    value_format?: 'cents' | null
  }>
  charts: Array<{
    key: string
    title: string
    type: 'line' | 'bar' | 'doughnut'
    labels: string[]
    values: number[]
    value_format?: 'cents' | null
  }>
  queues: Array<{
    key: string
    title: string
    subtitle?: string | null
    items: Array<{ title: string; subtitle: string; href: string | null }>
  }>
  quick_actions: Array<{ label: string; href: string; icon: string; permission: string }>
  alerts: Array<{
    key: string
    severity: 'warning' | 'critical'
    title: string
    message: string
    href?: string | null
  }>
  empty: boolean
  finance_breakdowns?: {
    revenue_by_fee_structure?: Array<{
      fee_structure_id: number | null
      billing_category_id: number | null
      code: string | null
      label: string
      amount_cents: number
      count: number
    }>
  } | null
}>()

const kpiIcons: Record<string, Component> = {
  files: Files,
  inbox: Inbox,
  shield: Shield,
  timer: Timer,
  'user-plus': UserPlus,
  banknote: Banknote,
  receipt: Receipt,
  check: Check,
  coins: Coins,
  trending: TrendingUp,
  award: Award,
  users: Users,
  'user-check': UserCheck,
  alert: AlertCircle,
  undo: Undo2,
  'check-circle': CheckCircle2,
  layers: Layers,
  scale: Scale,
  refresh: RefreshCw,
  gavel: Gavel,
  scroll: ScrollText,
  'file-text': FileText,
  globe: Globe,
  building: Building,
  book: Book,
  'building-2': Building2,
  activity: Activity,
  'message-square': MessageSquare,
  sparkles: Sparkles,
}

function kpiIcon(name: string | undefined) {
  if (!name) return LayoutDashboard
  return kpiIcons[name] ?? LayoutDashboard
}

function formatKpiValue(row: (typeof props.kpis)[0]): string {
  if (row.value_format === 'cents') {
    return formatMoneyFromCents(row.value, 'ZMW')
  }
  return new Intl.NumberFormat().format(row.value)
}

const primaryQuick = computed(() => props.quick_actions.slice(0, 6))
const secondaryQuick = computed(() => props.quick_actions.slice(6))

const quickIconMap: Record<string, Component> = {
  layers: Layers,
  'user-check': UserCheck,
  clipboard: ClipboardList,
  search: Search,
  banknote: Banknote,
  users: Users,
  user: User,
  shield: ShieldCheck,
  activity: Activity,
  award: Award,
  globe: Globe,
  building: Building,
  book: Book,
  coins: Coins,
  'building-2': Building2,
}

function quickIcon(name: string) {
  return quickIconMap[name] ?? LayoutDashboard
}

const dateRange = computed(() => props.meta.date_range)
const isLevel2Scope = computed(() => props.meta.dashboard_scope === 'level2_qualifications')
const isFinanceScope = computed(() => props.meta.dashboard_scope === 'finance')
const feeStructureRows = computed(() => props.finance_breakdowns?.revenue_by_fee_structure ?? [])
const reportsOverviewHref = computed(() => (isFinanceScope.value ? '/admin/reports' : '/admin/reports/applications'))

function dashboardUrl(rangeDays: number) {
  return `/admin/dashboard?range=${rangeDays}`
}
</script>

<template>
  <AdminLayout>
    <!-- Welcome -->
    <div
      class="rounded-2xl border border-[#0B3A66]/15 bg-gradient-to-br from-[#0B3A66] via-[#0B3A66] to-[#0076BD] p-6 text-white shadow-lg sm:p-8"
    >
      <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
          <div class="inline-flex items-center gap-2 text-xs font-semibold text-white/70">
            <LayoutDashboard class="h-4 w-4" aria-hidden="true" />
            ZAQA Verification Portal
          </div>
          <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">{{ meta.greeting_line }}</h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/90 sm:text-base">{{ meta.subtitle }}</p>
          <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-white/80 sm:text-sm">
            <span class="rounded-full border border-white/25 bg-white/10 px-3 py-1 font-semibold">{{ meta.primary_role }}</span>
            <span>{{ meta.current_date_formatted }}</span>
            <span class="text-white/60">· {{ meta.timezone }}</span>
          </div>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
          <Link
            v-if="meta.dashboard_scope === 'level1_assigned'"
            href="/admin/verification/assigned-to-me"
            class="rounded-xl border border-white/30 bg-[#F18230] px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#e07828]"
          >
            Assigned to me
          </Link>
          <Link
            v-else-if="meta.dashboard_scope === 'level2_qualifications'"
            href="/admin/verification/assigned-to-me"
            class="rounded-xl border border-white/30 bg-[#F18230] px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#e07828]"
          >
            My Level 2 tasks
          </Link>
          <Link
            v-else-if="isFinanceScope"
            href="/admin/finance/payment-proofs"
            class="rounded-xl border border-white/30 bg-[#F18230] px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#e07828]"
          >
            Open payment proofs
          </Link>
          <Link
            v-else-if="quick_actions.some((a) => a.href === '/admin/verification/pool')"
            href="/admin/verification/pool"
            class="rounded-xl border border-white/30 bg-[#F18230] px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#e07828]"
          >
            Open pool
          </Link>
          <Link
            v-if="!isFinanceScope && quick_actions.some((a) => a.href === '/admin/finance/payment-proofs')"
            href="/admin/finance/payment-proofs"
            class="rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/20"
          >
            Finance queue
          </Link>
        </div>
      </div>
    </div>

    <div v-if="dateRange" class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="text-sm font-semibold uppercase tracking-wider text-text-muted">Dashboard overview</h2>
        <p class="mt-1 text-xs text-text-muted">
          Dashboard shows recent activity.
          <Link :href="reportsOverviewHref" class="font-semibold text-[#0076BD] underline-offset-2 hover:underline">Reports</Link>
          for custom date ranges.
        </p>
      </div>
      <div class="inline-flex rounded-xl border border-border bg-surface p-1 shadow-sm" role="group" aria-label="Dashboard date range">
        <Link
          v-for="opt in dateRange.options"
          :key="opt.value"
          :href="dashboardUrl(opt.value)"
          class="rounded-lg px-4 py-2 text-sm font-semibold transition"
          :class="
            dateRange.selected === opt.value
              ? 'bg-[#0076BD] text-white shadow-sm'
              : 'text-text-muted hover:bg-surface-muted hover:text-text-primary'
          "
        >
          {{ opt.label }}
        </Link>
      </div>
    </div>

    <div v-if="alerts.length" class="mt-6 space-y-3">
      <div
        v-for="alert in alerts"
        :key="alert.key"
        class="rounded-xl border px-4 py-3 text-sm"
        :class="
          alert.severity === 'critical'
            ? 'border-red-300 bg-red-50 text-red-900'
            : 'border-warning/30 bg-warning/10 text-warning'
        "
      >
        <div class="font-semibold">
          {{ alert.severity === 'critical' ? '🚨' : '⚠' }} {{ alert.title }}
        </div>
        <p class="mt-1">{{ alert.message }}</p>
        <Link
          v-if="alert.href"
          :href="alert.href"
          class="mt-2 inline-flex text-xs font-semibold underline underline-offset-2"
        >
          Manage SMS balance
        </Link>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-if="empty"
      class="mt-8 rounded-2xl border border-dashed border-border bg-surface-muted/50 px-6 py-16 text-center"
    >
      <ShieldCheck class="mx-auto h-10 w-10 text-[#0076BD]/60" aria-hidden="true" />
      <div class="mt-4 text-lg font-semibold text-text-primary">No dashboard widgets for your access level</div>
      <p class="mx-auto mt-2 max-w-md text-sm text-text-muted">
        Your account can open the admin portal, but no metric sections match your current permissions. Contact a Super Admin if you need additional access.
      </p>
    </div>

    <!-- KPIs -->
    <div v-if="kpis.length" class="mt-8">
      <h2 class="text-sm font-semibold uppercase tracking-wider text-text-muted">
        Key metrics
        <span v-if="dateRange && !isLevel2Scope" class="font-normal normal-case text-text-muted">· {{ dateRange.label }}</span>
      </h2>
      <p v-if="isLevel2Scope && meta.l2_metrics_explainer" class="mt-2 max-w-3xl text-sm text-text-muted">
        {{ meta.l2_metrics_explainer }}
        <span v-if="dateRange" class="font-medium text-text-primary"> Selected period: {{ dateRange.label }}.</span>
      </p>
      <div
        class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
        :class="isFinanceScope ? '2xl:grid-cols-4' : isLevel2Scope ? '2xl:grid-cols-5' : '2xl:grid-cols-4'"
      >
        <template v-for="card in kpis" :key="card.key">
          <Link
            v-if="card.href"
            :href="card.href"
            class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:border-[#0076BD]/40 hover:shadow-md"
          >
            <div class="flex items-start justify-between gap-3">
              <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#0076BD]/10 text-[#0076BD]"
                aria-hidden="true"
              >
                <component :is="kpiIcon(card.icon)" class="h-5 w-5" />
              </div>
              <div class="min-w-0 flex-1 text-right">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ card.label }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-text-primary">{{ formatKpiValue(card) }}</div>
                <div v-if="card.hint" class="mt-1 text-xs text-text-muted">{{ card.hint }}</div>
              </div>
            </div>
          </Link>
          <div v-else class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#0076BD]/10 text-[#0076BD]"
                aria-hidden="true"
              >
                <component :is="kpiIcon(card.icon)" class="h-5 w-5" />
              </div>
              <div class="min-w-0 flex-1 text-right">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ card.label }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-text-primary">{{ formatKpiValue(card) }}</div>
                <div v-if="card.hint" class="mt-1 text-xs text-text-muted">{{ card.hint }}</div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>

    <div
      v-if="isFinanceScope"
      class="mt-8 rounded-2xl border border-border bg-surface p-5 shadow-sm"
    >
      <h2 class="text-sm font-semibold uppercase tracking-wider text-text-muted">
        Revenue by fee structure
        <span v-if="dateRange" class="font-normal normal-case text-text-muted">· {{ dateRange.label }}</span>
      </h2>
      <p v-if="feeStructureRows.length === 0" class="mt-4 text-sm text-text-muted">
        No revenue collected by fee structure for this period.
      </p>
      <div v-else class="mt-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="text-xs font-semibold uppercase tracking-wider text-text-muted">
            <tr>
              <th class="py-2 pr-4 text-left">Fee structure</th>
              <th class="px-4 py-2 text-right">Count</th>
              <th class="py-2 pl-4 text-right">Revenue</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/70">
            <tr v-for="row in feeStructureRows" :key="`${row.fee_structure_id ?? 'x'}-${row.billing_category_id ?? 'x'}-${row.label}`">
              <td class="py-3 pr-4 font-medium text-text-primary">{{ row.label }}</td>
              <td class="px-4 py-3 text-right tabular-nums text-text-muted">{{ row.count }}</td>
              <td class="py-3 pl-4 text-right font-semibold tabular-nums text-text-primary">{{ formatMoneyFromCents(row.amount_cents, 'ZMW') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Charts -->
    <div v-if="charts.length" class="mt-10">
      <h2 class="text-sm font-semibold uppercase tracking-wider text-text-muted">Trends &amp; breakdowns</h2>
      <p class="mt-1 text-xs text-text-muted">Today / this week use the application timezone ({{ meta.timezone }}).</p>
      <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <DashboardChart
          v-for="c in charts"
          :key="c.key"
          :chart-key="c.key"
          :title="c.title"
          :type="c.type"
          :labels="c.labels"
          :values="c.values"
          :value-format="c.value_format ?? null"
        />
      </div>
    </div>

    <!-- Queues + quick actions -->
    <div class="mt-10 grid grid-cols-1 gap-6 xl:grid-cols-3">
      <div class="space-y-6 xl:col-span-2">
        <div v-for="q in queues" :key="q.key" class="rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border px-5 py-4">
            <h3 class="text-sm font-semibold text-text-primary">{{ q.title }}</h3>
            <p v-if="q.subtitle" class="mt-1 text-xs text-text-muted">{{ q.subtitle }}</p>
          </div>
          <div v-if="!q.items.length" class="px-5 py-8 text-center text-sm text-text-muted">Nothing in this queue right now.</div>
          <ul v-else class="divide-y divide-border/70">
            <li v-for="(it, idx) in q.items" :key="idx" class="px-5 py-3">
              <Link v-if="it.href" :href="it.href" class="group block">
                <div class="text-sm font-semibold text-text-primary group-hover:text-[#0076BD]">{{ it.title }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ it.subtitle }}</div>
              </Link>
              <div v-else>
                <div class="text-sm font-semibold text-text-primary">{{ it.title }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ it.subtitle }}</div>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <aside class="space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Quick actions</div>
          <p class="mt-1 text-xs text-text-muted">Only destinations you are allowed to open.</p>
          <div class="mt-4 flex flex-col gap-2">
            <Link
              v-for="a in primaryQuick"
              :key="a.label + a.href"
              :href="a.href"
              class="flex items-center gap-3 rounded-xl border border-border bg-surface-muted px-3 py-2.5 text-sm font-medium text-text-primary transition hover:border-[#0076BD]/35 hover:bg-surface"
            >
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#0076BD]/10 text-[#0076BD]">
                <component :is="quickIcon(a.icon)" class="h-4 w-4" />
              </span>
              {{ a.label }}
            </Link>
          </div>
        </div>

        <div v-if="secondaryQuick.length" class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">More</div>
          <div class="mt-3 flex flex-col gap-1.5">
            <Link
              v-for="a in secondaryQuick"
              :key="a.label + a.href"
              :href="a.href"
              class="text-sm font-medium text-[#0076BD] underline-offset-2 hover:underline"
            >
              {{ a.label }}
            </Link>
          </div>
        </div>
      </aside>
    </div>
  </AdminLayout>
</template>
