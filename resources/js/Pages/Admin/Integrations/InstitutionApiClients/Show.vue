<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { AlertTriangle, KeyRound, ShieldCheck, Trash2 } from 'lucide-vue-next'

const props = defineProps<{
  client: any
  tokens: any[]
  abilities: string[]
  flash_token?: string | null
  flash_token_abilities?: string[] | null
}>()

const tokenForm = useForm<{ token_name: string; abilities: string[]; expires_in_days: number | null }>({
  token_name: 'integration-token',
  abilities: [...props.client.scopes],
  expires_in_days: 365,
})

const emailForm = useForm<{ token: string; abilities: string[] }>({
  token: '',
  abilities: [],
})

function toggleAbility(a: string) {
  const idx = tokenForm.abilities.indexOf(a)
  if (idx >= 0) tokenForm.abilities.splice(idx, 1)
  else tokenForm.abilities.push(a)
}

function issueToken() {
  tokenForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/tokens`, { preserveScroll: true })
}

function revokeToken(tokenId: number) {
  if (!confirm('Revoke this token? This cannot be undone.')) return
  tokenForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/tokens/${tokenId}/revoke`, { preserveScroll: true })
}

function disableClient() {
  if (!confirm('Disable this client and revoke all tokens?')) return
  tokenForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/disable`, { preserveScroll: true })
}

function enableClient() {
  tokenForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/enable`, { preserveScroll: true })
}

function emailToken() {
  if (!props.flash_token) return
  emailForm.token = props.flash_token
  emailForm.abilities = props.flash_token_abilities || tokenForm.abilities
  emailForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/tokens/email-latest`, { preserveScroll: true })
}

function rotateToken() {
  if (!confirm('Rotate token? This will revoke existing tokens for this client.')) return
  tokenForm.post(`/admin/integrations/institution-api-clients/${props.client.id}/tokens/rotate`, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <KeyRound class="h-4 w-4" aria-hidden="true" />
          Integrations
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Institution API Client</h1>
        <p class="mt-1 text-sm text-text-muted">Issue and revoke bearer tokens for institution submissions.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/integrations/institution-api-clients" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
      </div>
    </div>

    <div v-if="flash_token" class="mt-6 rounded-2xl border border-warning/40 bg-warning/10 p-5">
      <div class="flex items-start gap-3">
        <AlertTriangle class="mt-0.5 h-5 w-5 text-warning" aria-hidden="true" />
        <div class="min-w-0">
          <div class="text-sm font-semibold text-text-primary">Token generated</div>
          <div class="mt-1 text-xs text-text-muted">Copy this token now. It will not be shown again.</div>
          <pre class="mt-3 overflow-x-auto rounded-xl border border-border bg-surface p-3 text-xs text-text-primary">{{ flash_token }}</pre>
          <div class="mt-3 flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary px-3 py-1.5 text-xs font-semibold"
              @click="navigator.clipboard?.writeText(flash_token)"
            >
              Copy token
            </button>
            <button
              v-if="client.contact_email"
              type="button"
              class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs font-semibold"
              @click="emailToken"
            >
              Email token
            </button>
            <a href="/docs/institution-api" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs font-semibold">View Swagger docs</a>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
      <div class="rounded-2xl border border-border bg-surface p-5 lg:col-span-1">
        <div class="text-sm font-semibold text-text-primary">Client</div>
        <div class="mt-3 space-y-2 text-sm">
          <div><span class="text-text-muted">Name:</span> <span class="font-semibold text-text-primary">{{ client.name }}</span></div>
          <div><span class="text-text-muted">Institution:</span> <span class="font-semibold text-text-primary">{{ client.awarding_institution?.name }}</span></div>
          <div v-if="client.contact_name"><span class="text-text-muted">Contact:</span> <span class="text-text-primary">{{ client.contact_name }}</span></div>
          <div v-if="client.contact_email"><span class="text-text-muted">Email:</span> <span class="text-text-primary">{{ client.contact_email }}</span></div>
          <div>
            <span class="text-text-muted">Status:</span>
            <span class="zaqa-badge ml-2" :class="client.is_active ? 'zaqa-badge-success' : 'zaqa-badge-danger'">{{ client.is_active ? 'active' : 'disabled' }}</span>
          </div>
          <div><span class="text-text-muted">Last used:</span> <span class="text-text-primary">{{ client.last_used_at || '—' }}</span></div>
          <div><span class="text-text-muted">Token emailed:</span> <span class="text-text-primary">{{ client.token_last_sent_at || '—' }}</span></div>
          <div><span class="text-text-muted">Token rotated:</span> <span class="text-text-primary">{{ client.token_rotated_at || '—' }}</span></div>
        </div>

        <div class="mt-5 border-t border-border pt-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Allowed scopes</div>
          <div class="mt-2 flex flex-wrap gap-2">
            <span v-for="s in client.scopes" :key="s" class="zaqa-badge zaqa-badge-secondary">{{ s }}</span>
          </div>
        </div>

        <div v-if="client.notes" class="mt-5 border-t border-border pt-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Notes</div>
          <div class="mt-2 whitespace-pre-wrap text-sm text-text-primary">{{ client.notes }}</div>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
          <button v-if="client.is_active" type="button" class="zaqa-btn zaqa-btn-danger px-4 py-2 text-sm" @click="disableClient">
            Disable & revoke tokens
          </button>
          <button v-else type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" @click="enableClient">Enable</button>
          <Link
            :href="`/admin/integrations/institution-api-logs?awarding_institution_id=${client.awarding_institution?.id || ''}`"
            class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
          >
            View logs
          </Link>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm lg:col-span-2">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Issue token</div>
          <div class="mt-1 text-xs text-text-muted">Tokens are bearer tokens scoped to this institution client.</div>
        </div>

        <div class="p-5">
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Token name</label>
              <input v-model="tokenForm.token_name" class="zaqa-input mt-2 h-10" :disabled="!client.is_active" />
              <p v-if="tokenForm.errors.token_name" class="mt-2 text-xs text-danger">{{ tokenForm.errors.token_name }}</p>
            </div>

            <div class="sm:col-span-2">
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Expires in (days)</label>
              <input v-model.number="tokenForm.expires_in_days" type="number" min="1" max="3650" class="zaqa-input mt-2 h-10" :disabled="!client.is_active" />
              <p v-if="tokenForm.errors.expires_in_days" class="mt-2 text-xs text-danger">{{ tokenForm.errors.expires_in_days }}</p>
            </div>

            <div class="sm:col-span-2">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Abilities</div>
              <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <label v-for="a in client.scopes" :key="a" class="inline-flex items-center gap-2 rounded-xl border border-border bg-surface-muted px-3 py-2 text-sm">
                  <input type="checkbox" :disabled="!client.is_active" :checked="tokenForm.abilities.includes(a)" @change="toggleAbility(a)" />
                  <span class="font-semibold text-text-primary">{{ a }}</span>
                </label>
              </div>
              <p v-if="tokenForm.errors.abilities" class="mt-2 text-xs text-danger">{{ tokenForm.errors.abilities }}</p>
            </div>
          </div>

          <div class="mt-5">
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50"
              :disabled="!client.is_active || tokenForm.processing"
              @click="issueToken"
            >
              <ShieldCheck class="h-4 w-4" aria-hidden="true" />
              {{ tokenForm.processing ? 'Generating…' : 'Generate token' }}
            </button>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-danger ml-2 inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold disabled:opacity-50"
              :disabled="!client.is_active || tokenForm.processing || tokens.length === 0"
              @click="rotateToken"
            >
              Rotate token
            </button>
          </div>
        </div>

        <div class="border-t border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Tokens</div>
          <div class="mt-1 text-xs text-text-muted">Revoking a token immediately blocks access.</div>
        </div>

        <div v-if="tokens.length === 0" class="px-5 py-6">
          <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
            <div class="text-sm font-semibold text-text-primary">No tokens</div>
            <div class="mt-1 text-xs text-text-muted">Generate a token to share with the institution.</div>
          </div>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
              <tr>
                <th class="px-5 py-3 text-left">Name</th>
                <th class="px-5 py-3 text-left">Abilities</th>
                <th class="px-5 py-3 text-left">Expires</th>
                <th class="px-5 py-3 text-left">Last used</th>
                <th class="px-5 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="t in tokens" :key="t.id" class="hover:bg-surface-muted/60">
                <td class="px-5 py-3">
                  <div class="font-semibold text-text-primary">{{ t.name }}</div>
                  <div class="mt-0.5 text-xs text-text-muted">Created: {{ t.created_at }}</div>
                </td>
                <td class="px-5 py-3">
                  <div class="flex flex-wrap gap-2">
                    <span v-for="a in t.abilities" :key="a" class="zaqa-badge zaqa-badge-secondary">{{ a }}</span>
                  </div>
                </td>
                <td class="px-5 py-3 text-text-primary">{{ t.expires_at || '—' }}</td>
                <td class="px-5 py-3 text-text-primary">{{ t.last_used_at || '—' }}</td>
                <td class="px-5 py-3 text-right">
                  <button type="button" class="zaqa-btn zaqa-btn-danger inline-flex items-center gap-2 px-3 py-1.5 text-xs" @click="revokeToken(t.id)">
                    <Trash2 class="h-4 w-4" aria-hidden="true" />
                    Revoke
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
