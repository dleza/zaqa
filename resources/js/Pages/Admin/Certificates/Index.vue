<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { BadgeCheck } from 'lucide-vue-next'
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <BadgeCheck class="h-4 w-4" aria-hidden="true" />
          Certificates
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Certificates</h1>
        <p class="mt-1 text-sm text-text-muted">Issue, manage, and verify certificates.</p>
      </div>
    </div>

    <div class="mt-6 rounded-2xl border border-border bg-surface p-6 shadow-sm">
      <div class="text-sm font-semibold text-text-primary">Planned</div>
      <div class="mt-1 text-xs text-text-muted">
        This module will manage certificate issuance, revocation, and public verification records with full audit logging.
      </div>
    </div>
  </AdminLayout>
</template>

