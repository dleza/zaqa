<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { FileStack, Layers } from 'lucide-vue-next'

function startApplication() {
  router.post('/applicant/applications/multiple')
}
</script>

<template>
  <ApplicantLayout>
    <template #pageHeader>
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-text-primary">Multiple Applications</h1>
        <p class="mt-1 text-sm text-text-muted">
          Submit one application with multiple qualification records, each with its own qualification holder details.
        </p>
      </div>
    </template>

    <div class="mx-auto max-w-3xl rounded-2xl border border-border bg-surface p-8 shadow-sm">
      <div class="flex items-start gap-4">
        <div class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand/10 text-brand">
          <Layers class="h-6 w-6" aria-hidden="true" />
        </div>
        <div>
          <h2 class="text-lg font-semibold text-text-primary">Start a group submission</h2>
          <p class="mt-2 text-sm text-text-muted">
            You will add application information, then capture each qualification holder and qualification record with
            required documents before review, declaration, and payment.
          </p>
          <ul class="mt-4 space-y-2 text-sm text-text-muted">
            <li class="flex items-start gap-2">
              <FileStack class="mt-0.5 h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
              One application reference covers all qualification records in the submission.
            </li>
            <li class="flex items-start gap-2">
              <FileStack class="mt-0.5 h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
              Each qualification record requires its own holder identity and document uploads.
            </li>
          </ul>
          <div class="mt-6 flex flex-wrap gap-3">
            <button type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2" @click="startApplication">
              Start application
            </button>
            <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary px-4 py-2">Cancel</Link>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
