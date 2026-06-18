<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import Swal from 'sweetalert2'

defineProps<{
  applications: Array<any>
}>()

async function confirmDelete(app: any) {
  const result = await Swal.fire({
    icon: 'warning',
    title: 'Delete application?',
    text: 'This will permanently delete the draft application and its uploaded documents.',
    showCancelButton: true,
    confirmButtonText: 'Delete',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#B42318',
  })

  if (!result.isConfirmed) return

  router.delete(`/applicant/applications/${app.id}`, { preserveScroll: true })
}
</script>

<template>
  <ApplicantLayout>
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-xl font-semibold">My applications</h2>
        <p class="mt-1 text-sm text-text-muted">Track your applications and their statuses.</p>
      </div>

      <Link
        href="/applicant/applications/new"
        class="zaqa-btn zaqa-btn-primary"
      >
        New application
      </Link>
    </div>

    <div class="mt-6">
      <div v-if="applications.length === 0" class="rounded-xl border border-border bg-surface p-6 text-sm text-text-muted">
        No applications found.
      </div>

      <!-- Desktop table -->
      <div v-else class="hidden overflow-hidden rounded-xl border border-border bg-surface sm:block">
        <table class="w-full text-left text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted uppercase tracking-wider">
            <tr>
              <th class="px-4 py-3">Application</th>
              <th class="px-4 py-3">Service</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Current step</th>
              <th class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="app in applications" :key="app.id" class="hover:bg-surface-muted/60">
              <td class="px-4 py-4">
                <div class="font-semibold text-text-primary">{{ app.application_number }}</div>
                <div class="mt-1 text-xs text-text-muted">
                  {{ app.is_foreign ? 'Foreign' : 'Local' }} • Created {{ app.created_at?.slice(0, 10) ?? '' }}
                </div>
              </td>
              <td class="px-4 py-4">
                <span class="text-text-primary">{{ app.service_type }}</span>
              </td>
              <td class="px-4 py-4">
                <span class="zaqa-badge">{{ app.display_status_label ?? app.status_label }}</span>
              </td>
              <td class="px-4 py-4">
                <span
                  v-if="app.can_edit && app.wizard?.current_step"
                  class="zaqa-badge border-brand/15 bg-brand/10 text-brand"
                >
                  Step {{ app.wizard.current_step.index }}/{{ app.wizard.current_step.total }} •
                  {{ app.wizard.current_step.label }}
                </span>
                <span v-else class="text-sm text-text-muted">—</span>
              </td>
              <td class="px-4 py-4">
                <div class="flex justify-end gap-2">
                  <Link :href="`/applicant/applications/${app.id}`" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs">View</Link>
                  <Link
                    v-if="app.can_edit"
                    :href="app.wizard?.edit_href ?? `/applicant/applications/${app.id}/edit`"
                    class="zaqa-btn zaqa-btn-primary px-3 py-2 text-xs"
                  >
                    Edit
                  </Link>
                  <button
                    v-if="app.can_delete"
                    type="button"
                    class="zaqa-btn border border-danger/20 bg-danger/10 px-3 py-2 text-xs font-semibold text-danger hover:bg-danger/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/30"
                    @click="confirmDelete(app)"
                  >
                    Delete
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile “responsive table” cards -->
      <div class="space-y-3 sm:hidden">
        <div v-for="app in applications" :key="app.id" class="rounded-xl border border-border bg-surface p-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">{{ app.application_number }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ app.service_type }} • {{ app.is_foreign ? 'Foreign' : 'Local' }}</div>
            </div>
            <span class="zaqa-badge">{{ app.display_status_label ?? app.status_label }}</span>
          </div>

          <div class="mt-3 grid grid-cols-2 gap-3 text-xs">
            <div class="text-text-muted">Current step</div>
            <div class="text-right font-semibold" :class="app.can_edit && app.wizard?.current_step ? 'text-brand' : 'text-text-muted'">
              <template v-if="app.can_edit && app.wizard?.current_step">
                {{ app.wizard.current_step.index }}/{{ app.wizard.current_step.total }} • {{ app.wizard.current_step.label }}
              </template>
              <template v-else>—</template>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap gap-2">
            <Link :href="`/applicant/applications/${app.id}`" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs">View</Link>
            <Link
              v-if="app.can_edit"
              :href="app.wizard?.edit_href ?? `/applicant/applications/${app.id}/edit`"
              class="zaqa-btn zaqa-btn-primary px-3 py-2 text-xs"
            >
              Edit
            </Link>
            <button
              v-if="app.can_delete"
              type="button"
              class="zaqa-btn border border-danger/20 bg-danger/10 px-3 py-2 text-xs font-semibold text-danger hover:bg-danger/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/30"
              @click="confirmDelete(app)"
            >
              Delete
            </button>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>

