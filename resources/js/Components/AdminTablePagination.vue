<script setup lang="ts">
import AdminPagination from '@/Components/AdminPagination.vue'
import { computed } from 'vue'

type Paginator = {
  data?: unknown[]
  links?: Array<{ url: string | null; label: string; active: boolean }>
  total?: number
  from?: number
  to?: number
}

const props = defineProps<{
  paginator: Paginator | null | undefined
  label?: string
}>()

const summary = computed(() => {
  if (!props.label) return null
  const total = Number(props.paginator?.total ?? 0)
  if (total <= 0) return null
  const from = Number(props.paginator?.from ?? 0)
  const to = Number(props.paginator?.to ?? 0)
  return `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${total.toLocaleString()} ${props.label}`
})
</script>

<template>
  <div v-if="(paginator?.data?.length ?? 0) > 0" class="border-t border-border bg-surface-muted px-5 py-4">
    <div v-if="summary" class="text-center text-xs text-text-muted">{{ summary }}</div>
    <AdminPagination :links="paginator?.links ?? []" />
  </div>
</template>
