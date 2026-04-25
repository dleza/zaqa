<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  ArcElement,
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  Filler,
  Legend,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Title,
  Tooltip,
  DoughnutController,
} from 'chart.js'

Chart.register(
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  BarController,
  BarElement,
  ArcElement,
  DoughnutController,
  Title,
  Tooltip,
  Legend,
  Filler,
)

const ZAQA = {
  primary: '#0076BD',
  dark: '#0B3A66',
  accent: '#F18230',
  muted: '#94a3b8',
}

const props = defineProps<{
  chartKey: string
  title: string
  type: 'line' | 'bar' | 'doughnut'
  labels: string[]
  values: number[]
  valueFormat?: 'cents' | null
}>()

const canvasRef = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null

function formatTooltip(value: number): string {
  if (props.valueFormat === 'cents') {
    const v = value / 100
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZMW' }).format(v)
  }
  return String(value)
}

function buildConfig() {
  const labels = props.labels ?? []
  const data = props.values ?? []
  const palette = [ZAQA.primary, ZAQA.dark, ZAQA.accent, '#22c55e', '#a855f7', '#64748b', '#0ea5e9', '#f97316']

  if (props.type === 'doughnut') {
    return {
      type: 'doughnut' as const,
      data: {
        labels,
        datasets: [
          {
            data,
            backgroundColor: labels.map((_, i) => palette[i % palette.length]),
            borderColor: '#ffffff',
            borderWidth: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' as const, labels: { boxWidth: 10, font: { size: 11 } } },
          tooltip: {
            callbacks: {
              label: (ctx: { parsed: number }) => formatTooltip(ctx.parsed),
            },
          },
        },
      },
    }
  }

  if (props.type === 'bar') {
    return {
      type: 'bar' as const,
      data: {
        labels,
        datasets: [
          {
            label: props.title,
            data,
            backgroundColor: ZAQA.primary + 'cc',
            borderColor: ZAQA.dark,
            borderWidth: 1,
            borderRadius: 6,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 } } },
          y: { beginAtZero: true, ticks: { precision: 0 } },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (ctx: { parsed: { y: number } }) => formatTooltip(ctx.parsed.y),
            },
          },
        },
      },
    }
  }

  // line
  return {
    type: 'line' as const,
    data: {
      labels,
      datasets: [
        {
          label: props.title,
          data,
          borderColor: ZAQA.primary,
          backgroundColor: ZAQA.primary + '22',
          fill: true,
          tension: 0.35,
          pointBackgroundColor: ZAQA.accent,
          pointBorderColor: ZAQA.dark,
          pointRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { beginAtZero: true, ticks: { precision: 0 } },
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx: { parsed: { y: number } }) => formatTooltip(ctx.parsed.y),
          },
        },
      },
    },
  }
}

function render() {
  if (!canvasRef.value) return
  chart?.destroy()
  chart = new Chart(canvasRef.value, buildConfig() as never)
}

onMounted(() => render())
watch(
  () => [props.labels, props.values, props.type, props.chartKey],
  () => render(),
  { deep: true },
)
onBeforeUnmount(() => {
  chart?.destroy()
  chart = null
})
</script>

<template>
  <div class="flex h-full min-h-[220px] flex-col rounded-2xl border border-border bg-surface p-4 shadow-sm sm:min-h-[240px]">
    <div class="text-sm font-semibold text-text-primary">{{ title }}</div>
    <div class="relative mt-3 min-h-0 flex-1">
      <canvas ref="canvasRef" />
    </div>
  </div>
</template>
