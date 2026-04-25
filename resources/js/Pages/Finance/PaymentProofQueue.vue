<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import FinanceLayout from '@/Layouts/FinanceLayout.vue'
import Swal from 'sweetalert2'
import { CheckCircle2, XCircle } from 'lucide-vue-next'

const props = defineProps<{
  payments: Array<any>
}>()

async function approve(p: any) {
  const result = await Swal.fire({
    icon: 'question',
    title: 'Approve payment proof?',
    text: 'This will confirm payment for the application.',
    showCancelButton: true,
    confirmButtonText: 'Approve',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#15803D',
  })
  if (!result.isConfirmed) return

  router.post(`/finance/payments/${p.id}/approve`, { comment: null }, { preserveScroll: true })
}

async function reject(p: any) {
  const result = await Swal.fire({
    icon: 'warning',
    title: 'Reject payment proof?',
    input: 'textarea',
    inputLabel: 'Rejection reason (required)',
    inputPlaceholder: 'Explain what is wrong with the proof and what the applicant should do next.',
    showCancelButton: true,
    confirmButtonText: 'Reject',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#B42318',
    preConfirm: (value) => {
      if (!value || value.toString().trim().length === 0) {
        Swal.showValidationMessage('Rejection reason is required.')
      }
      return value
    },
  })
  if (!result.isConfirmed) return

  router.post(`/finance/payments/${p.id}/reject`, { reason: result.value }, { preserveScroll: true })
}
</script>

<template>
  <FinanceLayout>
    <div class="flex items-center justify-between gap-4">
      <div>
        <h2 class="text-xl font-semibold">Payment proof review</h2>
        <p class="mt-1 text-sm text-text-muted">Approve or reject bank deposit/transfer proofs uploaded by applicants.</p>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl border border-border bg-surface">
      <div v-if="payments.length === 0" class="p-6 text-sm text-text-muted">No pending proofs.</div>
      <div v-else class="divide-y divide-border/60">
        <div v-for="p in payments" :key="p.id" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">{{ p.application_number ?? `Application #${p.application_id}` }}</div>
            <div class="mt-1 text-xs text-text-muted">
              Method: {{ p.method }} • Status: {{ p.status }}
            </div>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="zaqa-btn border border-success/20 bg-success/10 px-3 py-2 text-xs font-semibold text-success hover:bg-success/15" @click="approve(p)">
              <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
              Approve
            </button>
            <button type="button" class="zaqa-btn border border-danger/20 bg-danger/10 px-3 py-2 text-xs font-semibold text-danger hover:bg-danger/15" @click="reject(p)">
              <XCircle class="h-4 w-4" aria-hidden="true" />
              Reject
            </button>
          </div>
        </div>
      </div>
    </div>
  </FinanceLayout>
</template>

