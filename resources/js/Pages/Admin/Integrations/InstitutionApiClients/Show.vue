<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { Link, useForm } from '@inertiajs/vue3'
import {
  AlertTriangle,
  ArrowLeftRight,
  ArrowRight,
  Globe,
  KeyRound,
  List,
  RefreshCw,
  Save,
  ShieldCheck,
  TestTube2,
  Trash2,
  Upload,
} from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  client: any
  tokens: any[]
  abilities: string[]
  flash_token?: string | null
  flash_token_abilities?: string[] | null
  pull_integration: any
  pull_integration_urls: {
    save: string
    generate_token: string
    test: string
    email_token: string
  }
  flash_pull_lookup_token?: string | null
}>()

const pushGenerateModalOpen = ref(false)
const pushTokensModalOpen = ref(false)
const pullConfigModalOpen = ref(false)

const tokenForm = useForm<{ token_name: string; abilities: string[]; expires_in_days: number | null }>({
  token_name: 'integration-token',
  abilities: [...props.client.scopes],
  expires_in_days: 365,
})

const pullForm = useForm({
  is_active: !!props.pull_integration.is_active,
  supports_pull: !!props.pull_integration.supports_pull,
  lookup_url: props.pull_integration.lookup_url || '',
  auth_type: props.pull_integration.auth_type || 'bearer_token',
  bearer_token: '',
  request_method: props.pull_integration.request_method || 'POST',
  timeout_seconds: props.pull_integration.timeout_seconds ?? 15,
  retry_attempts: props.pull_integration.retry_attempts ?? 2,
  rate_limit_per_minute: props.pull_integration.rate_limit_per_minute ?? '',
  driver: props.pull_integration.driver || 'generic_rest',
})

const pullEmailForm = useForm<{ token: string }>({ token: '' })
const emailForm = useForm<{ token: string; abilities: string[] }>({ token: '', abilities: [] })
const actionForm = useForm({})

const activePushTokens = computed(() => props.tokens.length)

const pullReady = computed(() => {
  return (
    !!props.pull_integration.supports_pull &&
    !!props.pull_integration.is_active &&
    !!props.pull_integration.lookup_url &&
    !!props.pull_integration.has_credentials
  )
})

const pullStatusLabel = computed(() => {
  if (pullReady.value) return 'Ready'
  if (props.pull_integration.supports_pull && props.pull_integration.lookup_url) return 'Incomplete'
  return 'Not configured'
})

const pullStatusClass = computed(() => {
  if (pullReady.value) return 'zaqa-badge-success'
  if (props.pull_integration.supports_pull) return 'zaqa-badge-warning'
  return 'zaqa-badge-secondary'
})

function toggleAbility(a: string) {
  const idx = tokenForm.abilities.indexOf(a)
  if (idx >= 0) tokenForm.abilities.splice(idx, 1)
  else tokenForm.abilities.push(a)
}

function openPushGenerateModal() {
  tokenForm.token_name = 'integration-token'
  tokenForm.abilities = [...props.client.scopes]
  tokenForm.expires_in_days = 365
  pushGenerateModalOpen.value = true
}

function issueToken() {
  tokenForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/tokens`, {
    preserveScroll: true,
    onSuccess: () => {
      pushGenerateModalOpen.value = false
    },
  })
}

function revokeToken(tokenId: number) {
  if (!confirm('Revoke this token? This cannot be undone.')) return
  actionForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/tokens/${tokenId}/revoke`, {
    preserveScroll: true,
  })
}

function disableClient() {
  if (!confirm('Disable this client and revoke all push tokens?')) return
  actionForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/disable`, { preserveScroll: true })
}

function enableClient() {
  actionForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/enable`, { preserveScroll: true })
}

function emailPushToken() {
  if (!props.flash_token) return
  emailForm.token = props.flash_token
  emailForm.abilities = props.flash_token_abilities || tokenForm.abilities
  emailForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/tokens/email-latest`, { preserveScroll: true })
}

function rotatePushToken() {
  if (!confirm('Rotate push token? This revokes all existing push tokens for this client.')) return
  tokenForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/tokens/rotate`, {
    preserveScroll: true,
    onSuccess: () => {
      pushTokensModalOpen.value = false
    },
  })
}

function openPullConfigModal() {
  pullForm.is_active = !!props.pull_integration.is_active
  pullForm.supports_pull = !!props.pull_integration.supports_pull
  pullForm.lookup_url = props.pull_integration.lookup_url || ''
  pullForm.auth_type = props.pull_integration.auth_type || 'bearer_token'
  pullForm.bearer_token = ''
  pullForm.request_method = props.pull_integration.request_method || 'POST'
  pullForm.timeout_seconds = props.pull_integration.timeout_seconds ?? 15
  pullForm.retry_attempts = props.pull_integration.retry_attempts ?? 2
  pullForm.rate_limit_per_minute = props.pull_integration.rate_limit_per_minute ?? ''
  pullForm.driver = props.pull_integration.driver || 'generic_rest'
  pullConfigModalOpen.value = true
}

function savePullIntegration() {
  pullForm.post(props.pull_integration_urls.save, {
    preserveScroll: true,
    onSuccess: () => {
      pullConfigModalOpen.value = false
    },
  })
}

function generatePullLookupToken() {
  if (!confirm('Generate a new pull lookup token? This replaces any existing lookup bearer token.')) return
  actionForm.post(props.pull_integration_urls.generate_token, { preserveScroll: true })
}

function testPullConnection() {
  actionForm.post(props.pull_integration_urls.test, { preserveScroll: true })
}

function emailPullLookupToken() {
  if (!props.flash_pull_lookup_token) return
  pullEmailForm.token = props.flash_pull_lookup_token
  pullEmailForm.post(props.pull_integration_urls.email_token, { preserveScroll: true })
}

function copyText(value: string | null | undefined) {
  if (!value) return
  navigator.clipboard?.writeText(value)
}
</script>

<template>
  <AdminLayout>
    <!-- Header -->
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <KeyRound class="h-4 w-4" aria-hidden="true" />
          Integrations / Institution client
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">{{ client.name }}</h1>
        <p class="mt-1 text-sm text-text-muted">{{ client.awarding_institution?.name }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="zaqa-badge" :class="client.is_active ? 'zaqa-badge-success' : 'zaqa-badge-danger'">
          {{ client.is_active ? 'Client active' : 'Client disabled' }}
        </span>
        <Link href="/admin/integrations/institution-api-clients" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
      </div>
    </div>

    <!-- One-time token alerts -->
    <div v-if="flash_token" class="mt-6 rounded-2xl border border-warning/40 bg-warning/10 p-5">
      <div class="flex items-start gap-3">
        <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
        <div class="min-w-0 flex-1">
          <div class="text-sm font-semibold text-text-primary">Push token — copy now</div>
          <p class="mt-1 text-xs text-text-muted">
            Step 2 of push setup: share this with the institution so they can call ZAQA’s API. It will not be shown again.
          </p>
          <pre class="mt-3 overflow-x-auto rounded-xl border border-border bg-surface p-3 text-xs text-text-primary">{{ flash_token }}</pre>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="zaqa-btn zaqa-btn-primary px-3 py-1.5 text-xs font-semibold" @click="copyText(flash_token)">Copy</button>
            <button v-if="client.contact_email" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs font-semibold" @click="emailPushToken">Email to contact</button>
            <a href="/docs/institution-api" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs font-semibold">API docs</a>
          </div>
        </div>
      </div>
    </div>

    <div v-if="flash_pull_lookup_token" class="mt-6 rounded-2xl border border-warning/40 bg-warning/10 p-5">
      <div class="flex items-start gap-3">
        <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
        <div class="min-w-0 flex-1">
          <div class="text-sm font-semibold text-text-primary">Pull lookup token — copy now</div>
          <p class="mt-1 text-xs text-text-muted">
            Step 3 of pull setup: institution IT must set <code class="rounded bg-surface px-1">ZAQA_LOOKUP_TOKEN</code> to this value on their lookup endpoint.
          </p>
          <pre class="mt-3 overflow-x-auto rounded-xl border border-border bg-surface p-3 text-xs text-text-primary">{{ flash_pull_lookup_token }}</pre>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="zaqa-btn zaqa-btn-primary px-3 py-1.5 text-xs font-semibold" @click="copyText(flash_pull_lookup_token)">Copy</button>
            <button v-if="client.contact_email" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs font-semibold" @click="emailPullLookupToken">Email to contact</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Client details (compact) -->
    <div class="mt-6 rounded-2xl border border-border bg-surface p-5">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Contact</div>
          <div class="mt-1 text-sm text-text-primary">{{ client.contact_name || '—' }}</div>
          <div class="text-xs text-text-muted">{{ client.contact_email || '—' }}</div>
        </div>
        <div>
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Last API use</div>
          <div class="mt-1 text-sm text-text-primary">{{ client.last_used_at || 'Never' }}</div>
        </div>
        <div class="sm:col-span-2">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Allowed scopes</div>
          <div class="mt-2 flex flex-wrap gap-1.5">
            <span v-for="s in client.scopes" :key="s" class="zaqa-badge zaqa-badge-secondary text-xs">{{ s }}</span>
          </div>
        </div>
      </div>
      <div v-if="client.notes" class="mt-4 border-t border-border pt-4 text-sm text-text-primary">
        <span class="text-xs font-semibold uppercase tracking-wider text-text-muted">Notes</span>
        <p class="mt-1 whitespace-pre-wrap">{{ client.notes }}</p>
      </div>
      <div class="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
        <button v-if="client.is_active" type="button" class="zaqa-btn zaqa-btn-danger px-3 py-1.5 text-xs" @click="disableClient">Disable client</button>
        <button v-else type="button" class="zaqa-btn zaqa-btn-primary px-3 py-1.5 text-xs" @click="enableClient">Enable client</button>
      </div>
    </div>

    <!-- Two integration flows -->
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
      <!-- Push -->
      <section class="flex flex-col overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="flex items-center gap-2">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
              <Upload class="h-4 w-4" aria-hidden="true" />
            </div>
            <div>
              <div class="text-sm font-semibold text-text-primary">Push integration</div>
              <div class="flex items-center gap-1 text-xs text-text-muted">
                Institution <ArrowRight class="h-3 w-3" aria-hidden="true" /> ZAQA
              </div>
            </div>
          </div>
        </div>

        <div class="flex flex-1 flex-col p-5">
          <p class="text-sm text-text-muted">
            The institution sends learner records <strong class="font-medium text-text-primary">to ZAQA</strong> using a bearer token you generate here.
          </p>

          <ol class="mt-4 space-y-2 text-sm text-text-primary">
            <li class="flex gap-2"><span class="font-semibold text-text-muted">1.</span> Generate a push token</li>
            <li class="flex gap-2"><span class="font-semibold text-text-muted">2.</span> Share it securely with the institution</li>
            <li class="flex gap-2"><span class="font-semibold text-text-muted">3.</span> They call <code class="text-xs">/api/institution/v1</code> with that token</li>
          </ol>

          <div class="mt-5 grid grid-cols-2 gap-3 rounded-xl border border-border bg-surface-muted p-4 text-sm">
            <div>
              <div class="text-xs text-text-muted">Active tokens</div>
              <div class="mt-0.5 font-semibold text-text-primary">{{ activePushTokens }}</div>
            </div>
            <div>
              <div class="text-xs text-text-muted">Client</div>
              <div class="mt-0.5 font-semibold" :class="client.is_active ? 'text-success' : 'text-danger'">
                {{ client.is_active ? 'Enabled' : 'Disabled' }}
              </div>
            </div>
          </div>

          <div class="mt-auto flex flex-wrap gap-2 pt-6">
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50"
              :disabled="!client.is_active"
              @click="openPushGenerateModal"
            >
              <ShieldCheck class="h-4 w-4" aria-hidden="true" />
              Generate push token
            </button>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
              @click="pushTokensModalOpen = true"
            >
              <List class="h-4 w-4" aria-hidden="true" />
              Manage tokens ({{ activePushTokens }})
            </button>
            <Link
              :href="`/admin/integrations/institution-api-logs?awarding_institution_id=${client.awarding_institution?.id || ''}`"
              class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
            >
              Push logs
            </Link>
          </div>
        </div>
      </section>

      <!-- Pull -->
      <section class="flex flex-col overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-info/10 text-info">
                <Globe class="h-4 w-4" aria-hidden="true" />
              </div>
              <div>
                <div class="text-sm font-semibold text-text-primary">Pull lookup integration</div>
                <div class="flex items-center gap-1 text-xs text-text-muted">
                  ZAQA <ArrowRight class="h-3 w-3" aria-hidden="true" /> Institution
                </div>
              </div>
            </div>
            <span class="zaqa-badge" :class="pullStatusClass">{{ pullStatusLabel }}</span>
          </div>
        </div>

        <div class="flex flex-1 flex-col p-5">
          <p class="text-sm text-text-muted">
            During auto-verification, ZAQA calls the institution’s lookup URL when internal records do not match. Uses a <strong class="font-medium text-text-primary">separate</strong> shared token (not the push token).
          </p>

          <ol class="mt-4 space-y-2 text-sm text-text-primary">
            <li class="flex gap-2"><span class="font-semibold text-text-muted">1.</span> Configure the institution lookup URL</li>
            <li class="flex gap-2"><span class="font-semibold text-text-muted">2.</span> Generate a lookup token and share it</li>
            <li class="flex gap-2"><span class="font-semibold text-text-muted">3.</span> Institution sets <code class="text-xs">ZAQA_LOOKUP_TOKEN</code> on their system</li>
            <li class="flex gap-2"><span class="font-semibold text-text-muted">4.</span> Test the connection</li>
          </ol>

          <div class="mt-5 space-y-2 rounded-xl border border-border bg-surface-muted p-4 text-sm">
            <div class="flex justify-between gap-2">
              <span class="text-text-muted">Pull enabled</span>
              <span class="font-medium text-text-primary">{{ pull_integration.supports_pull ? 'Yes' : 'No' }}</span>
            </div>
            <div class="flex justify-between gap-2">
              <span class="text-text-muted">Lookup URL</span>
              <span class="truncate font-medium text-text-primary">{{ pull_integration.lookup_url || 'Not set' }}</span>
            </div>
            <div class="flex justify-between gap-2">
              <span class="text-text-muted">Lookup token</span>
              <span class="font-medium text-text-primary">{{ pull_integration.has_credentials ? 'Configured' : 'Not set' }}</span>
            </div>
          </div>

          <div class="mt-auto flex flex-wrap gap-2 pt-6">
            <button type="button" class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm" @click="openPullConfigModal">
              <ArrowLeftRight class="h-4 w-4" aria-hidden="true" />
              Configure lookup
            </button>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm font-semibold disabled:opacity-50"
              :disabled="actionForm.processing"
              @click="generatePullLookupToken"
            >
              Generate lookup token
            </button>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm disabled:opacity-50"
              :disabled="!pull_integration.lookup_url || actionForm.processing"
              @click="testPullConnection"
            >
              <TestTube2 class="h-4 w-4" aria-hidden="true" />
              Test
            </button>
            <Link
              :href="`/admin/integrations/institution-pull-lookup-logs?awarding_institution_id=${client.awarding_institution?.id || ''}`"
              class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
            >
              Pull logs
            </Link>
          </div>
        </div>
      </section>
    </div>

    <!-- Push: generate token modal -->
    <AdminActionModal
      v-model="pushGenerateModalOpen"
      title="Generate push token"
      description="Institution → ZAQA. The institution uses this token to submit learner records to ZAQA’s API."
    >
      <div class="grid gap-4">
        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Token name</label>
          <input v-model="tokenForm.token_name" class="zaqa-input mt-2 h-10 w-full" :disabled="!client.is_active" />
          <p v-if="tokenForm.errors.token_name" class="mt-2 text-xs text-danger">{{ tokenForm.errors.token_name }}</p>
        </div>
        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Expires in (days)</label>
          <input v-model.number="tokenForm.expires_in_days" type="number" min="1" max="3650" class="zaqa-input mt-2 h-10 w-full" />
        </div>
        <div>
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Abilities</div>
          <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <label v-for="a in client.scopes" :key="a" class="inline-flex items-center gap-2 rounded-xl border border-border bg-surface-muted px-3 py-2 text-sm">
              <input type="checkbox" :checked="tokenForm.abilities.includes(a)" @change="toggleAbility(a)" />
              <span class="font-medium text-text-primary">{{ a }}</span>
            </label>
          </div>
          <p v-if="tokenForm.errors.abilities" class="mt-2 text-xs text-danger">{{ tokenForm.errors.abilities }}</p>
        </div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="pushGenerateModalOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50"
          :disabled="!client.is_active || tokenForm.processing"
          @click="issueToken"
        >
          <ShieldCheck class="h-4 w-4" aria-hidden="true" />
          {{ tokenForm.processing ? 'Generating…' : 'Generate token' }}
        </button>
      </template>
    </AdminActionModal>

    <!-- Push: manage tokens modal -->
    <AdminActionModal
      v-model="pushTokensModalOpen"
      title="Push tokens"
      description="Active bearer tokens for institution → ZAQA API access."
      max-width-class="max-w-3xl"
    >
      <div v-if="tokens.length === 0" class="rounded-xl border border-border bg-surface-muted p-8 text-center">
        <p class="text-sm font-medium text-text-primary">No push tokens yet</p>
        <p class="mt-1 text-xs text-text-muted">Generate a token to give the institution API access.</p>
        <button type="button" class="zaqa-btn zaqa-btn-primary mt-4 px-4 py-2 text-sm" @click="pushTokensModalOpen = false; openPushGenerateModal()">
          Generate push token
        </button>
      </div>
      <div v-else class="overflow-x-auto rounded-xl border border-border">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-4 py-3 text-left">Name</th>
              <th class="px-4 py-3 text-left">Abilities</th>
              <th class="px-4 py-3 text-left">Expires</th>
              <th class="px-4 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="t in tokens" :key="t.id">
              <td class="px-4 py-3">
                <div class="font-medium text-text-primary">{{ t.name }}</div>
                <div class="text-xs text-text-muted">{{ t.created_at }}</div>
              </td>
              <td class="px-4 py-3">
                <span v-for="a in t.abilities" :key="a" class="zaqa-badge zaqa-badge-secondary mr-1 text-xs">{{ a }}</span>
              </td>
              <td class="px-4 py-3 text-text-primary">{{ t.expires_at || '—' }}</td>
              <td class="px-4 py-3 text-right">
                <button type="button" class="zaqa-btn zaqa-btn-danger inline-flex items-center gap-1 px-2 py-1 text-xs" @click="revokeToken(t.id)">
                  <Trash2 class="h-3.5 w-3.5" aria-hidden="true" />
                  Revoke
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="pushTokensModalOpen = false">Close</button>
        <button
          v-if="tokens.length > 0"
          type="button"
          class="zaqa-btn zaqa-btn-danger inline-flex items-center gap-2 px-4 py-2 text-sm disabled:opacity-50"
          :disabled="!client.is_active || tokenForm.processing"
          @click="rotatePushToken"
        >
          <RefreshCw class="h-4 w-4" aria-hidden="true" />
          Rotate all tokens
        </button>
      </template>
    </AdminActionModal>

    <!-- Pull: configure modal -->
    <AdminActionModal
      v-model="pullConfigModalOpen"
      title="Configure pull lookup"
      description="ZAQA → Institution. Set the URL ZAQA calls during auto-verification."
      max-width-class="max-w-3xl"
    >
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2 rounded-xl border border-border bg-surface-muted p-4 text-xs text-text-muted">
          Step 1 of pull setup. After saving, generate a lookup token and ask the institution to set
          <code class="rounded bg-surface px-1">ZAQA_LOOKUP_TOKEN</code> on their endpoint.
        </div>
        <label class="sm:col-span-2 inline-flex items-center gap-2 text-sm">
          <input v-model="pullForm.is_active" type="checkbox" />
          Integration active
        </label>
        <label class="sm:col-span-2 inline-flex items-center gap-2 text-sm">
          <input v-model="pullForm.supports_pull" type="checkbox" />
          Enable pull lookup
        </label>
        <div class="sm:col-span-2">
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Lookup URL</label>
          <input v-model="pullForm.lookup_url" class="zaqa-input mt-2 h-10 w-full" placeholder="https://sis.example/api/zaqa/v1/learner-lookup" />
          <p v-if="pullForm.errors.lookup_url" class="mt-2 text-xs text-danger">{{ pullForm.errors.lookup_url }}</p>
        </div>
        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Auth</label>
          <select v-model="pullForm.auth_type" class="zaqa-input mt-2 h-10 w-full">
            <option value="bearer_token">Bearer token</option>
            <option value="none">None</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Method</label>
          <select v-model="pullForm.request_method" class="zaqa-input mt-2 h-10 w-full">
            <option value="POST">POST</option>
            <option value="GET">GET</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Timeout (s)</label>
          <input v-model.number="pullForm.timeout_seconds" type="number" min="3" max="60" class="zaqa-input mt-2 h-10 w-full" />
        </div>
        <div>
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Retries</label>
          <input v-model.number="pullForm.retry_attempts" type="number" min="0" max="5" class="zaqa-input mt-2 h-10 w-full" />
        </div>
        <div v-if="pullForm.auth_type === 'bearer_token' && pull_integration.has_credentials" class="sm:col-span-2 text-xs text-text-muted">
          A lookup token is already saved. Use “Generate lookup token” on the main page to create a new one, or paste a manual token below to replace it.
        </div>
        <div v-if="pullForm.auth_type === 'bearer_token'" class="sm:col-span-2">
          <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Manual token (optional)</label>
          <input v-model="pullForm.bearer_token" class="zaqa-input mt-2 h-10 w-full" placeholder="Leave blank to keep existing" />
        </div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="pullConfigModalOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50"
          :disabled="pullForm.processing"
          @click="savePullIntegration"
        >
          <Save class="h-4 w-4" aria-hidden="true" />
          {{ pullForm.processing ? 'Saving…' : 'Save' }}
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
