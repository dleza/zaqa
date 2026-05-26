<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Globe, Save, TestTube2 } from 'lucide-vue-next'

const props = defineProps<{
  institution: { id: number; name: string }
  integration: any
  save_url: string
  test_url: string
}>()

const form = useForm({
  is_active: !!props.integration.is_active,
  supports_push: !!props.integration.supports_push,
  supports_pull: !!props.integration.supports_pull,
  lookup_url: props.integration.lookup_url || '',
  auth_type: props.integration.auth_type || 'none',
  bearer_token: '',
  basic_username: '',
  basic_password: '',
  request_method: props.integration.request_method || 'POST',
  timeout_seconds: props.integration.timeout_seconds ?? 15,
  retry_attempts: props.integration.retry_attempts ?? 2,
  rate_limit_per_minute: props.integration.rate_limit_per_minute ?? '',
  driver: props.integration.driver || 'generic_rest',
})

function save() {
  form.post(props.save_url, { preserveScroll: true })
}

function testConnection() {
  form.post(props.test_url, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Globe class="h-4 w-4" aria-hidden="true" />
          Integrations
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Configure Pull Integration</h1>
        <p class="mt-1 text-sm text-text-muted">{{ institution.name }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/integrations/institution-integrations" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
        <Link :href="`/admin/integrations/institution-pull-lookup-logs?awarding_institution_id=${institution.id}`" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
          View pull logs
        </Link>
      </div>
    </div>

    <div class="mt-6 max-w-4xl rounded-2xl border border-border bg-surface p-6">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="inline-flex items-center gap-2 text-sm text-text-primary">
            <input type="checkbox" v-model="form.is_active" />
            Active
          </label>
          <p v-if="form.errors.is_active" class="mt-2 text-xs text-danger">{{ form.errors.is_active }}</p>
        </div>

        <div>
          <label class="inline-flex items-center gap-2 text-sm text-text-primary">
            <input type="checkbox" v-model="form.supports_push" />
            Supports push (institution → ZAQA)
          </label>
        </div>
        <div>
          <label class="inline-flex items-center gap-2 text-sm text-text-primary">
            <input type="checkbox" v-model="form.supports_pull" />
            Supports pull lookup (ZAQA → institution)
          </label>
        </div>

        <div class="sm:col-span-2">
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Lookup URL</label>
          <input v-model="form.lookup_url" class="zaqa-input mt-2 h-10" placeholder="https://institution.example/api/learner-records/lookup" />
          <p v-if="form.errors.lookup_url" class="mt-2 text-xs text-danger">{{ form.errors.lookup_url }}</p>
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Auth type</label>
          <select v-model="form.auth_type" class="zaqa-input mt-2 h-10">
            <option value="none">none</option>
            <option value="bearer_token">bearer_token</option>
            <option value="basic">basic</option>
          </select>
          <p v-if="form.errors.auth_type" class="mt-2 text-xs text-danger">{{ form.errors.auth_type }}</p>
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Request method</label>
          <select v-model="form.request_method" class="zaqa-input mt-2 h-10">
            <option value="POST">POST</option>
            <option value="GET">GET</option>
          </select>
          <p v-if="form.errors.request_method" class="mt-2 text-xs text-danger">{{ form.errors.request_method }}</p>
        </div>

        <div v-if="form.auth_type === 'bearer_token'" class="sm:col-span-2">
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Bearer token</label>
          <input v-model="form.bearer_token" class="zaqa-input mt-2 h-10" placeholder="Set a new token (not displayed again)" />
          <div class="mt-1 text-xs text-text-muted">Existing token is not shown. Leave blank to keep existing.</div>
        </div>

        <template v-if="form.auth_type === 'basic'">
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Basic username</label>
            <input v-model="form.basic_username" class="zaqa-input mt-2 h-10" placeholder="Leave blank to keep existing" />
          </div>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Basic password</label>
            <input v-model="form.basic_password" type="password" class="zaqa-input mt-2 h-10" placeholder="Leave blank to keep existing" />
          </div>
        </template>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Timeout seconds</label>
          <input v-model.number="form.timeout_seconds" type="number" min="3" max="60" class="zaqa-input mt-2 h-10" />
        </div>
        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Retry attempts</label>
          <input v-model.number="form.retry_attempts" type="number" min="0" max="5" class="zaqa-input mt-2 h-10" />
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Rate limit / minute (optional)</label>
          <input v-model="form.rate_limit_per_minute" type="number" min="1" max="1000" class="zaqa-input mt-2 h-10" />
        </div>

        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Driver</label>
          <select v-model="form.driver" class="zaqa-input mt-2 h-10">
            <option value="generic_rest">generic_rest</option>
          </select>
        </div>
      </div>

      <div class="mt-6 flex flex-wrap items-center gap-2">
        <button type="button" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50" :disabled="form.processing" @click="save">
          <Save class="h-4 w-4" aria-hidden="true" />
          {{ form.processing ? 'Saving…' : 'Save settings' }}
        </button>
        <button type="button" class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50" :disabled="form.processing" @click="testConnection">
          <TestTube2 class="h-4 w-4" aria-hidden="true" />
          Test connection
        </button>
      </div>
    </div>
  </AdminLayout>
</template>
