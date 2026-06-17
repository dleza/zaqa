<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { BarChart3, ChevronRight } from 'lucide-vue-next'

defineProps<{
  categories: Array<{
    key: string
    label: string
    description: string
    href: string
  }>
}>()
</script>

<template>
  <AdminLayout>
    <div>
      <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
        <BarChart3 class="h-4 w-4" aria-hidden="true" />
        Reports
      </div>
      <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Reports</h1>
      <p class="mt-1 text-sm text-text-muted">Choose a report category you are permitted to access.</p>
    </div>

    <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      <Link
        v-for="category in categories"
        :key="category.key"
        :href="category.href"
        class="group rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:border-brand/30 hover:bg-surface-muted"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold text-text-primary">{{ category.label }}</h2>
            <p class="mt-2 text-sm text-text-muted">{{ category.description }}</p>
          </div>
          <ChevronRight class="mt-0.5 h-5 w-5 shrink-0 text-text-muted transition group-hover:text-brand" aria-hidden="true" />
        </div>
      </Link>
    </div>
  </AdminLayout>
</template>
