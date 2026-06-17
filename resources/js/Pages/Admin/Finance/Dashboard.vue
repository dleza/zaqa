<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Banknote, CheckCircle2, Coins, TrendingUp, XCircle, AlertTriangle, LayoutDashboard } from 'lucide-vue-next'
import type { Component } from 'vue'
import { formatMoneyFromCents } from '@/utils/money'

const props = defineProps<{
  meta: {
    current_date_formatted: string
    timezone: string
    date_range?: {
      selected: number
      from: string
      to: string
      label: string
      options: Array<{ label: string; value: number }>
    }
  }
  kpis: Array<{ key: string; label: string; value: number; href?: string | null; icon?: string; value_format?: 'cents' | null; hint?: string }>
  charts: Array<{ key: string; title: string; type: 'line' | 'bar' | 'doughnut'; labels: string[]; values: number[]; value_format?: 'cents' | null }>
}>()

const dateRange = computed(() => props.meta.date_range)

function financeDashboardUrl(rangeDays: number) {
  return `/admin/finance?range=${rangeDays}`
}

const iconMap: Record<string, Component> = {
  banknote: Banknote,
  check: CheckCircle2,
  x: XCircle,
  alert: AlertTriangle,
  coins: Coins,
  trending: TrendingUp,
}

function iconFor(k: string | undefined) {
  return (k && iconMap[k]) || LayoutDashboard
}

function formatValue(row: (typeof props.kpis)[0]) {
  if (row.value_format === 'cents') return formatMoneyFromCents(row.value, 'ZMW')
  return new Intl.NumberFormat().format(row.value)
}

const kpiRows = computed(() => props.kpis ?? [])
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Banknote class="h-4 w-4" aria-hidden="true" />
          Finance
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Finance dashboard</h1>
        <p class="mt-1 text-sm text-text-muted">Payment proof review, confirmations, and revenue monitoring.</p>
        <div class="mt-2 text-xs text-text-muted">{{ meta.current_date_formatted }} · {{ meta.timezone }}</div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/finance/payment-proofs" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Payment proofs</Link>
        <Link href="/admin/finance/payments" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Processed payments</Link>
      </div>
    </div>

    <div v-if="dateRange" class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <p class="text-xs text-text-muted">
        Dashboard shows recent activity.
        <Link href="/admin/reports" class="font-semibold text-[#0076BD] underline-offset-2 hover:underline">Reports</Link>
        for custom date ranges.
      </p>
      <div class="inline-flex rounded-xl border border-border bg-surface p-1 shadow-sm" role="group" aria-label="Finance dashboard date range">
        <Link
          v-for="opt in dateRange.options"
          :key="opt.value"
          :href="financeDashboardUrl(opt.value)"
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

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <template v-for="k in kpiRows" :key="k.key">
        <Link
          v-if="k.href"
          :href="k.href"
          class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:border-[#0076BD]/40 hover:shadow-md"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#0076BD]/10 text-[#0076BD]">
              <component :is="iconFor(k.icon)" class="h-5 w-5" />
            </div>
            <div class="min-w-0 flex-1 text-right">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ k.label }}</div>
              <div class="mt-1 text-2xl font-bold tabular-nums text-text-primary">{{ formatValue(k) }}</div>
            </div>
          </div>
        </Link>
        <div v-else class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#0076BD]/10 text-[#0076BD]">
              <component :is="iconFor(k.icon)" class="h-5 w-5" />
            </div>
            <div class="min-w-0 flex-1 text-right">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ k.label }}</div>
              <div class="mt-1 text-2xl font-bold tabular-nums text-text-primary">{{ formatValue(k) }}</div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <div class="mt-8 grid grid-cols-1 gap-4 lg:grid-cols-2">
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
  </AdminLayout>
</template>

