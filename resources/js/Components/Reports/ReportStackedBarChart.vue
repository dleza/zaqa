<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  Legend,
  LinearScale,
  Tooltip,
} from 'chart.js'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

const ZAQA = ['#0076BD', '#0B3A66', '#F18230', '#22c55e', '#a855f7', '#64748b', '#0ea5e9', '#f97316']

const props = defineProps<{
  chartKey: string
  title: string
  labels: string[]
  datasets: { key: string; label: string; data: number[] }[]
}>()

const canvasRef = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null

function render() {
  if (!canvasRef.value) return
  chart?.destroy()
  const labels = props.labels ?? []
  const ds = (props.datasets ?? []).map((d, i) => ({
    label: d.label,
    data: d.data,
    backgroundColor: ZAQA[i % ZAQA.length] + 'cc',
    borderWidth: 1,
    borderColor: '#ffffff',
    stack: 'a',
  }))
  chart = new Chart(canvasRef.value, {
    type: 'bar',
    data: { labels, datasets: ds },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 } } },
        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
      },
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
      },
    },
  })
}

onMounted(() => render())
watch(
  () => [props.chartKey, props.labels, props.datasets],
  () => render(),
  { deep: true },
)
onBeforeUnmount(() => {
  chart?.destroy()
  chart = null
})
</script>

<template>
  <div class="flex h-full min-h-[260px] flex-col rounded-2xl border border-border bg-surface p-4 shadow-sm">
    <div class="text-sm font-semibold text-text-primary">{{ title }}</div>
    <div class="relative mt-3 min-h-0 flex-1">
      <canvas ref="canvasRef" />
    </div>
  </div>
</template>
