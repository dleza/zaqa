<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Settings } from 'lucide-vue-next'
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Settings class="h-4 w-4" aria-hidden="true" />
          Settings
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">System settings</h1>
        <p class="mt-1 text-sm text-text-muted">Configuration, reference data, and operational controls.</p>
      </div>
    </div>

    <div class="mt-6 rounded-2xl border border-border bg-surface p-6 shadow-sm">
      <div class="text-sm font-semibold text-text-primary">Coming next</div>
      <div class="mt-1 text-xs text-text-muted">
        Next phase will cover reference data management, notification templates, and environment-safe toggles (with audit trail).
      </div>
    </div>
  </AdminLayout>
</template>

