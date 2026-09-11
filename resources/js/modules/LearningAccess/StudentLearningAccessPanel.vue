<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type LearningAccount = {
  id: string
  student_id: string
  login_identifier: string
  language_code: string
  status: string
  version: number
  credential_version: number
  created_at: string
}

type LicenseProduct = {
  id: string
  code: string
  duration_days: number
  active: boolean
  languages: string[]
}

type Inventory = {
  id: string
  product_id: string
  status: string
  granted_at: string
}

type Assignment = {
  id: string
  inventory_entry_id: string
  student_id: string
  learning_account_id: string
  language_code: string
  assignment_sequence: number
  status: string
  presentation_status: string
  assigned_at: string
  revoked_at: string | null
  expires_at: string | null
  version: number
}

const props = defineProps<{
  studentId: string
  archived: boolean
}>()

const emit = defineEmits<{ changed: [] }>()

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const oneTimeSecret = ref('')
const accounts = ref<LearningAccount[]>([])
const products = ref<LicenseProduct[]>([])
const inventory = ref<Inventory[]>([])
const historyByAccount = ref<Record<string, Assignment[]>>({})
const drawerOpen = ref(false)
const targetMode = ref<'existing' | 'new'>('existing')
const selectedAccountId = ref('')
const selectedProductId = ref('')
const newLogin = ref('')
const newLanguage = ref('pl')
const initialPassword = ref('')

const availableInventory = computed(() =>
  inventory.value.filter((row) => row.status === 'available' && row.product_id === selectedProductId.value),
)
const selectedProduct = computed(() => products.value.find((row) => row.id === selectedProductId.value) ?? null)
const selectedAccount = computed(() => accounts.value.find((row) => row.id === selectedAccountId.value) ?? null)

onMounted(load)
watch(() => props.studentId, load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [accountResult, productResult, inventoryResult] = await Promise.all([
      api<LearningAccount[]>(`/api/v1/students/${props.studentId}/learning-accounts`),
      api<LicenseProduct[]>('/api/v1/license-products'),
      api<Inventory[]>('/api/v1/license-inventory?status=available'),
    ])
    accounts.value = accountResult.data
    products.value = productResult.data
    inventory.value = inventoryResult.data
    selectedAccountId.value ||= accounts.value[0]?.id ?? ''
    selectedProductId.value ||= products.value.find((product) =>
      selectedAccount.value ? product.languages.includes(selectedAccount.value.language_code) : product.languages.length > 0,
    )?.id ?? products.value[0]?.id ?? ''
    await loadHistories()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function loadHistories(): Promise<void> {
  const pairs = await Promise.all(accounts.value.map(async (account) => {
    const result = await api<Assignment[]>(
      `/api/v1/students/${props.studentId}/learning-accounts/${account.id}/license-assignments`,
    )
    return [account.id, result.data] as const
  }))
  historyByAccount.value = Object.fromEntries(pairs)
}

function openAssign(accountId?: string): void {
  oneTimeSecret.value = ''
  notice.value = ''
  error.value = ''
  if (accountId) {
    targetMode.value = 'existing'
    selectedAccountId.value = accountId
  } else {
    targetMode.value = accounts.value.length > 0 ? 'existing' : 'new'
    selectedAccountId.value ||= accounts.value[0]?.id ?? ''
  }
  const language = targetMode.value === 'existing' ? selectedAccount.value?.language_code : newLanguage.value
  selectedProductId.value = products.value.find((product) =>
    language ? product.languages.includes(language) : product.languages.length > 0,
  )?.id ?? products.value[0]?.id ?? ''
  drawerOpen.value = true
}

async function assign(): Promise<void> {
  const product = selectedProduct.value
  const unit = availableInventory.value[0]
  if (!product || !unit) {
    error.value = 'Brak dostępnej licencji wybranego rodzaju.'
    return
  }

  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    let body: Record<string, unknown>
    if (targetMode.value === 'existing') {
      const account = selectedAccount.value
      if (!account) throw new Error('Wybierz konto kursanta.')
      if (!product.languages.includes(account.language_code)) {
        throw new Error('Ten rodzaj licencji nie obsługuje języka wybranego konta.')
      }
      body = {
        license_inventory_entry_id: unit.id,
        target: { existing_learning_account_id: account.id },
        language_code: account.language_code,
      }
    } else {
      const login = newLogin.value.trim()
      if (!login) throw new Error('Podaj login lub e-mail do nauki.')
      if (!product.languages.includes(newLanguage.value)) {
        throw new Error('Ten rodzaj licencji nie obsługuje wybranego języka.')
      }
      body = {
        license_inventory_entry_id: unit.id,
        target: {
          student_id: props.studentId,
          new_learning_account: {
            login_identifier: login,
            language_code: newLanguage.value,
            initial_password: initialPassword.value.trim() || null,
          },
        },
        language_code: newLanguage.value,
      }
    }

    await api<Assignment>('/api/v1/license-assignments', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify(body),
    })
    notice.value = 'Licencja została przypisana.'
    drawerOpen.value = false
    newLogin.value = ''
    initialPassword.value = ''
    await load()
    emit('changed')
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function activate(assignment: Assignment): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    await api<Record<string, unknown>>(`/api/v1/license-assignments/${assignment.id}/activate`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${assignment.version}"` },
      body: JSON.stringify({}),
    })
    notice.value = 'Licencja została aktywowana. Okres dostępu został dopisany do historii.'
    await loadHistories()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function revoke(assignment: Assignment): Promise<void> {
  const reason = window.prompt('Podaj powód zwolnienia nieaktywowanej licencji:')
  if (!reason?.trim()) return

  saving.value = true
  error.value = ''
  try {
    await api<Assignment>(`/api/v1/license-assignments/${assignment.id}/revoke-unactivated`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${assignment.version}"` },
      body: JSON.stringify({ reason: reason.trim() }),
    })
    notice.value = 'Nieaktywowana licencja została zwolniona do puli bez usuwania historii.'
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function resetPassword(account: LearningAccount): Promise<void> {
  if (!window.confirm('Wygenerować nowe hasło? Poprzednie przestanie działać, a nowe będzie widoczne tylko teraz.')) return
  saving.value = true
  error.value = ''
  oneTimeSecret.value = ''
  try {
    const result = await api<{
      credential_version: number
      one_time_plaintext_password: string | null
    }>(`/api/v1/students/${props.studentId}/learning-accounts/${account.id}/password-reset`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${account.credential_version}"` },
      body: JSON.stringify({}),
    })
    oneTimeSecret.value = result.data.one_time_plaintext_password ?? ''
    notice.value = oneTimeSecret.value
      ? 'Nowe hasło zostało wygenerowane. Skopiuj je teraz — system nie potrafi go później odtworzyć.'
      : 'Hasło zostało już zmienione w tej operacji; sekret nie jest ponownie ujawniany.'
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

function statusLabel(value: string): string {
  return {
    not_activated: 'Nie aktywowano',
    active: 'Aktywna',
    expired: 'Wygasła',
    revoked: 'Zwolniona',
  }[value] ?? value
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('pl-PL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function handleError(caught: unknown): void {
  if (caught instanceof ApiError) {
    error.value = Object.values(caught.fields).flat().join(' ') || caught.message
  } else if (caught instanceof Error) {
    error.value = caught.message
  } else {
    error.value = 'Nie udało się wykonać operacji.'
  }
}
</script>

<template>
  <section class="detail-card learning-access-panel">
    <div class="card-heading split">
      <div>
        <span class="section-kicker">Dostęp do nauki</span>
        <h2>Konta i licencje</h2>
      </div>
      <button
        class="button primary"
        type="button"
        :disabled="archived || loading"
        @click="openAssign()"
      >
        Przydziel licencję
      </button>
    </div>

    <div v-if="notice" class="notice success" role="status">{{ notice }}</div>
    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div v-if="oneTimeSecret" class="secret-handoff">
      <span>Hasło jednorazowo widoczne</span>
      <strong>{{ oneTimeSecret }}</strong>
      <small>Skopiuj je przed opuszczeniem strony. Nie jest zapisywane w postaci możliwej do odtworzenia.</small>
    </div>

    <div v-if="loading" class="loading-card">Ładowanie dostępów…</div>
    <div v-else-if="accounts.length === 0" class="empty-inline">
      <strong>Brak konta do nauki.</strong>
      <span>Utwórz je podczas przypisywania pierwszej licencji.</span>
    </div>
    <div v-else class="learning-account-list">
      <article v-for="account in accounts" :key="account.id" class="learning-account-card">
        <div class="learning-account-head">
          <div>
            <strong>{{ account.login_identifier }}</strong>
            <span>{{ account.language_code.toUpperCase() }} · {{ account.status === 'active' ? 'Aktywne konto' : 'Konto wstrzymane' }}</span>
          </div>
          <div class="row-actions">
            <button class="text-button" type="button" :disabled="archived || saving" @click="openAssign(account.id)">
              Przydziel licencję
            </button>
            <button class="text-button" type="button" :disabled="archived || saving" @click="resetPassword(account)">
              Nowe hasło
            </button>
          </div>
        </div>

        <div v-if="(historyByAccount[account.id] ?? []).length === 0" class="empty-inline compact-empty">
          <span>Brak historii licencji.</span>
        </div>
        <div v-else class="license-history">
          <div v-for="assignment in historyByAccount[account.id]" :key="assignment.id" class="license-history-row">
            <div>
              <strong>#{{ assignment.assignment_sequence }} · {{ statusLabel(assignment.presentation_status) }}</strong>
              <span>Przypisano {{ formatDate(assignment.assigned_at) }}</span>
              <span v-if="assignment.expires_at">Ważna do {{ formatDate(assignment.expires_at) }}</span>
            </div>
            <div v-if="assignment.status === 'assigned'" class="row-actions">
              <button class="text-button strong" type="button" :disabled="archived || saving" @click="activate(assignment)">
                Aktywuj
              </button>
              <button class="text-button danger" type="button" :disabled="saving" @click="revoke(assignment)">
                Zwolnij
              </button>
            </div>
          </div>
        </div>
      </article>
    </div>

    <div v-if="drawerOpen" class="drawer-backdrop finance-drawer-backdrop" @click.self="drawerOpen = false">
      <aside class="drawer compact">
        <div class="drawer-header">
          <div>
            <span class="section-kicker">Dostęp do nauki</span>
            <h2>Przydzielanie licencji</h2>
          </div>
          <button class="icon-button" type="button" aria-label="Zamknij" @click="drawerOpen = false">×</button>
        </div>

        <form class="form-grid" @submit.prevent="assign">
          <label class="full">
            Rodzaj licencji
            <select v-model="selectedProductId">
              <option value="">Wybierz</option>
              <option v-for="product in products" :key="product.id" :value="product.id">
                {{ product.duration_days }} dni · dostępne {{ inventory.filter((row) => row.product_id === product.id && row.status === 'available').length }}
              </option>
            </select>
          </label>

          <fieldset class="full">
            <legend>Konto do nauki</legend>
            <div class="permission-list">
              <label v-for="account in accounts" :key="account.id" class="check">
                <input v-model="targetMode" type="radio" value="existing" @change="selectedAccountId = account.id">
                <span>{{ account.login_identifier }} · {{ account.language_code.toUpperCase() }}</span>
              </label>
              <label class="check">
                <input v-model="targetMode" type="radio" value="new">
                <span>Dodaj nowy dostęp</span>
              </label>
            </div>
          </fieldset>

          <label v-if="targetMode === 'existing'" class="full">
            Istniejące konto
            <select v-model="selectedAccountId">
              <option v-for="account in accounts" :key="account.id" :value="account.id">
                {{ account.login_identifier }} · {{ account.language_code.toUpperCase() }}
              </option>
            </select>
            <small>Język istniejącego konta nie jest zmieniany przez przypisanie licencji.</small>
          </label>

          <template v-else>
            <label class="full">
              Login lub e-mail
              <input v-model="newLogin" autocomplete="off">
            </label>
            <label>
              Język
              <select v-model="newLanguage">
                <option v-for="language in selectedProduct?.languages ?? []" :key="language" :value="language">
                  {{ language.toUpperCase() }}
                </option>
              </select>
            </label>
            <label>
              Hasło początkowe
              <input v-model="initialPassword" type="password" autocomplete="new-password" placeholder="Opcjonalnie">
            </label>
          </template>

          <div class="form-actions full">
            <button class="button ghost" type="button" @click="drawerOpen = false">Anuluj</button>
            <button class="button primary" type="submit" :disabled="saving || availableInventory.length === 0">
              {{ saving ? 'Zapisywanie…' : 'Przydziel licencję' }}
            </button>
          </div>
        </form>
      </aside>
    </div>
  </section>
</template>
