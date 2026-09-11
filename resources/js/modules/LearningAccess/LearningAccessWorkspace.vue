<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type Product = {
  id: string
  code: string
  duration_days: number
  active: boolean
  languages: string[]
  available_count?: number
  active_count?: number
}

type Inventory = { id: string; product_id: string; status: string; granted_at: string }
type Student = { id: string; first_name: string; last_name: string; contact_email: string | null }
type LearningAccount = {
  id: string
  student_id: string
  login_identifier: string
  language_code: string
  status: string
  version: number
  credential_version: number
}
type ManagementRow = {
  learning_account_id: string
  student_id: string
  student_full_name: string
  learning_identifier: string
  learning_access_language: string
  assigned_license_count: number
  latest_license_status: string | null
  latest_license_generated_at: string | null
  current_learning_access_expiry: string | null
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
type Paginated<T> = { data: T[]; meta: { page: number; per_page: number; total: number; last_page: number } }

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const products = ref<Product[]>([])
const inventory = ref<Inventory[]>([])
const rows = ref<ManagementRow[]>([])
const meta = ref({ page: 1, per_page: 25, total: 0, last_page: 1 })
const page = ref(1)
const search = ref('')
const hideFinished = ref(true)
const sort = ref('latest_license_generated_at')
const direction = ref<'asc' | 'desc'>('desc')
const expanded = ref<string | null>(null)
const history = ref<Record<string, Assignment[]>>({})

const drawerOpen = ref(false)
const studentSearch = ref('')
const studentOptions = ref<Student[]>([])
const selectedStudentId = ref('')
const studentAccounts = ref<LearningAccount[]>([])
const selectedAccountId = ref('')
const targetMode = ref<'existing' | 'new'>('new')
const selectedProductId = ref('')
const newLogin = ref('')
const newLanguage = ref('pl')
const initialPassword = ref('')

const selectedProduct = computed(() => products.value.find((item) => item.id === selectedProductId.value) ?? null)
const selectedAccount = computed(() => studentAccounts.value.find((item) => item.id === selectedAccountId.value) ?? null)
const availableForSelectedProduct = computed(() =>
  inventory.value.filter((item) => item.status === 'available' && item.product_id === selectedProductId.value),
)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [productResult, inventoryResult] = await Promise.all([
      api<Product[]>('/api/v1/license-products'),
      api<Inventory[]>('/api/v1/license-inventory?status=available'),
    ])
    products.value = productResult.data
    inventory.value = inventoryResult.data
    selectedProductId.value ||= products.value[0]?.id ?? ''
    await loadRows()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function loadRows(): Promise<void> {
  const params = new URLSearchParams({
    page: String(page.value),
    per_page: '25',
    hide_finished: hideFinished.value ? '1' : '0',
    sort: sort.value,
    direction: direction.value,
  })
  if (search.value.trim()) params.set('search', search.value.trim())
  const result = await api<Paginated<ManagementRow>>(`/api/v1/license-assignments?${params.toString()}`)
  rows.value = result.data.data
  meta.value = result.data.meta
}

async function toggleExpanded(row: ManagementRow): Promise<void> {
  if (expanded.value === row.learning_account_id) {
    expanded.value = null
    return
  }
  expanded.value = row.learning_account_id
  if (!history.value[row.learning_account_id]) {
    const result = await api<Assignment[]>(
      `/api/v1/students/${row.student_id}/learning-accounts/${row.learning_account_id}/license-assignments`,
    )
    history.value[row.learning_account_id] = result.data
  }
}

function openGenerate(): void {
  error.value = ''
  notice.value = ''
  studentSearch.value = ''
  studentOptions.value = []
  selectedStudentId.value = ''
  studentAccounts.value = []
  selectedAccountId.value = ''
  targetMode.value = 'new'
  selectedProductId.value = products.value[0]?.id ?? ''
  newLogin.value = ''
  newLanguage.value = selectedProduct.value?.languages[0] ?? 'pl'
  initialPassword.value = ''
  drawerOpen.value = true
}

async function searchStudents(): Promise<void> {
  if (!studentSearch.value.trim()) {
    studentOptions.value = []
    return
  }
  const params = new URLSearchParams({ q: studentSearch.value.trim(), per_page: '10' })
  const result = await api<Paginated<Student>>(`/api/v1/students?${params.toString()}`)
  studentOptions.value = result.data.data
}

async function selectStudent(student: Student): Promise<void> {
  selectedStudentId.value = student.id
  studentSearch.value = `${student.first_name} ${student.last_name}`
  const result = await api<LearningAccount[]>(`/api/v1/students/${student.id}/learning-accounts`)
  studentAccounts.value = result.data
  selectedAccountId.value = result.data[0]?.id ?? ''
  targetMode.value = result.data.length ? 'existing' : 'new'
  if (targetMode.value === 'existing') {
    const language = result.data[0]?.language_code
    selectedProductId.value = products.value.find((product) => language && product.languages.includes(language))?.id
      ?? products.value[0]?.id
      ?? ''
  }
}

async function assign(): Promise<void> {
  const studentId = selectedStudentId.value
  const product = selectedProduct.value
  const unit = availableForSelectedProduct.value[0]
  if (!studentId) {
    error.value = 'Wybierz kursanta.'
    return
  }
  if (!product || !unit) {
    error.value = 'Brak dostępnej licencji wybranego rodzaju.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    let body: Record<string, unknown>
    if (targetMode.value === 'existing') {
      const account = selectedAccount.value
      if (!account) throw new Error('Wybierz istniejące konto do nauki.')
      if (!product.languages.includes(account.language_code)) {
        throw new Error('Wybrany produkt nie obsługuje języka istniejącego konta.')
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
        throw new Error('Wybrany produkt nie obsługuje tego języka.')
      }
      body = {
        license_inventory_entry_id: unit.id,
        target: {
          student_id: studentId,
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
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function activate(item: Assignment): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    await api<Record<string, unknown>>(`/api/v1/license-assignments/${item.id}/activate`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${item.version}"` },
      body: JSON.stringify({}),
    })
    notice.value = 'Licencja została aktywowana.'
    history.value = {}
    await loadRows()
    if (expanded.value) {
      const row = rows.value.find((candidate) => candidate.learning_account_id === expanded.value)
      if (row) await toggleExpanded(row).then(() => toggleExpanded(row))
    }
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function revoke(item: Assignment): Promise<void> {
  const reason = window.prompt('Podaj powód zwolnienia nieaktywowanej licencji:')
  if (!reason?.trim()) return
  saving.value = true
  error.value = ''
  try {
    await api<Assignment>(`/api/v1/license-assignments/${item.id}/revoke-unactivated`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${item.version}"` },
      body: JSON.stringify({ reason: reason.trim() }),
    })
    notice.value = 'Licencja wróciła do puli. Historia przypisania została zachowana.'
    history.value = {}
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

function statusLabel(value: string | null): string {
  if (!value) return '—'
  return { not_activated: 'Nie aktywowano', active: 'Aktywna', expired: 'Wygasła', revoked: 'Zwolniona' }[value] ?? value
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
  <div
    class="app-shell"
  >
    <aside
      class="sidebar"
    >
      <a
        class="brand"
        href="/"
      >
        OSK
        <strong>
          Panel
        </strong>
      </a>
      <nav
        class="main-nav"
        aria-label="Główna nawigacja"
      >
        <a
          href="/kursanci"
        >
          Kursanci
        </a>
        <a
          href="/lokalizacje"
        >
          Lokalizacje
        </a>
        <a
          href="/pracownicy"
        >
          Pracownicy
        </a>
        <a
          href="/pojazdy"
        >
          Pojazdy
        </a>
        <a
          href="/kalendarz"
        >
          Kalendarz
        </a>
        <a
          href="/licencje/panel"
          class="active"
        >
          Licencje
        </a>
      </nav>
      <div
        class="sidebar-foot"
      >
        Stage 5 · Core v1
      </div>
    </aside>
    <main
      class="workspace"
    >
      <header
        class="workspace-header"
      >
        <div>
          <div
            class="eyebrow"
          >
            PrawkoNaRaz · OSK
          </div>
          <h1>
            Generowanie dostępu
          </h1>
        </div>
        <div
          class="header-actions"
        >
          <a
            class="button ghost"
            href="/kursanci"
          >
            Dodaj kursanta
          </a>
          <button
            class="button primary"
            type="button"
            @click="openGenerate"
          >
            Przydziel licencję
          </button>
        </div>
      </header>
      <div
        v-if="notice"
        class="notice success"
        role="status"
      >
        {{ notice }}
      </div>
      <div
        v-if="error"
        class="notice error"
        role="alert"
      >
        {{ error }}
      </div>
      <div
        v-if="loading"
        class="loading-card"
      >
        Ładowanie licencji…
      </div>
      <template
        v-else
      >
        <section
          class="license-product-grid"
        >
          <article
            v-for="product in products"
            :key="product.id"
            class="license-product-card"
          >
            <span>
              Licencja
            </span>
            <strong>
              {{ product.duration_days }} dni
            </strong>
            <div>
              <b>
                {{ product.available_count ?? inventory.filter((row) => row.product_id === product.id && row.status === 'available').length }}
              </b>
              <small>
                dostępnych
              </small>
            </div>
            <div>
              <b>
                {{ product.active_count ?? 0 }}
              </b>
              <small>
                aktywnych
              </small>
            </div>
          </article>
          <div
            v-if="products.length === 0"
            class="empty-inline"
          >
            <strong>
              Brak aktywnych produktów licencyjnych.
            </strong>
            <span>
              Zakup i zasilanie puli należy do osobnego modułu Commerce.
            </span>
          </div>
        </section>
        <section
          class="toolbar license-toolbar"
        >
          <div>
            <span
              class="section-kicker"
            >
              Przydzielone licencje
            </span>
            <p
              class="section-description"
            >
              Jedno konto do nauki może mieć wiele historycznych przypisań.
            </p>
          </div>
          <div
            class="license-filters"
          >
            <input
              v-model="search"
              type="search"
              placeholder="Szukaj loginu lub kursanta…"
              @keyup.enter="page = 1; loadRows()"
            >
            <select
              v-model="sort"
              @change="page = 1; loadRows()"
            >
              <option
                value="latest_license_generated_at"
              >
                Najnowsza licencja
              </option>
              <option
                value="learning_identifier"
              >
                Login
              </option>
              <option
                value="student_full_name"
              >
                Kursant
              </option>
              <option
                value="learning_access_language"
              >
                Język
              </option>
              <option
                value="latest_license_status"
              >
                Status
              </option>
              <option
                value="assigned_license_count"
              >
                Liczba licencji
              </option>
            </select>
            <select
              v-model="direction"
              @change="page = 1; loadRows()"
            >
              <option
                value="desc"
              >
                Malejąco
              </option>
              <option
                value="asc"
              >
                Rosnąco
              </option>
            </select>
            <label
              class="check"
            >
              <input
                v-model="hideFinished"
                type="checkbox"
                @change="page = 1; loadRows()"
              >
              Ukryj zakończone
            </label>
            <button
              class="button ghost"
              type="button"
              @click="page = 1; loadRows()"
            >
              Szukaj
            </button>
          </div>
        </section>
        <section
          class="table-card"
        >
          <table
            class="license-table"
          >
            <thead>
              <tr>
                <th>
                  Dane do nauki
                </th>
                <th>
                  Kursant
                </th>
                <th>
                  Najnowsza licencja
                </th>
                <th>
                  Język
                </th>
                <th>
                  Status
                </th>
                <th>
                  Licencje
                </th>
                <th>
                </th>
              </tr>
            </thead>
            <tbody>
              <template
                v-for="row in rows"
                :key="row.learning_account_id"
              >
                <tr>
                  <td>
                    <strong>
                      {{ row.learning_identifier }}
                    </strong>
                  </td>
                  <td>
                    {{ row.student_full_name }}
                  </td>
                  <td>
                    {{ formatDate(row.latest_license_generated_at) }}
                  </td>
                  <td>
                    {{ row.learning_access_language.toUpperCase() }}
                  </td>
                  <td>
                    <span
                      class="status-pill"
                      :class="{ muted: row.latest_license_status !== 'active' }"
                    >
                      {{ statusLabel(row.latest_license_status) }}
                    </span>
                  </td>
                  <td>
                    {{ row.assigned_license_count }}
                  </td>
                  <td
                    class="actions-column"
                  >
                    <button
                      class="text-button strong"
                      type="button"
                      @click="toggleExpanded(row)"
                    >
                      {{ expanded === row.learning_account_id ? 'Zwiń' : 'Rozwiń' }}
                    </button>
                  </td>
                </tr>
                <tr
                  v-if="expanded === row.learning_account_id"
                  class="license-expanded-row"
                >
                  <td
                    colspan="7"
                  >
                    <div
                      class="license-history"
                    >
                      <div
                        v-for="item in history[row.learning_account_id] ?? []"
                        :key="item.id"
                        class="license-history-row"
                      >
                        <div>
                          <strong>
                            #{{ item.assignment_sequence }} · {{ statusLabel(item.presentation_status) }}
                          </strong>
                          <span>
                            Przypisano {{ formatDate(item.assigned_at) }}
                          </span>
                          <span>
                            Wygasa {{ formatDate(item.expires_at) }}
                          </span>
                        </div>
                        <div
                          v-if="item.status === 'assigned'"
                          class="row-actions"
                        >
                          <button
                            class="text-button strong"
                            type="button"
                            :disabled="saving"
                            @click="activate(item)"
                          >
                            Aktywuj
                          </button>
                          <button
                            class="text-button danger"
                            type="button"
                            :disabled="saving"
                            @click="revoke(item)"
                          >
                            Zwolnij
                          </button>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
              <tr
                v-if="rows.length === 0"
              >
                <td
                  colspan="7"
                  class="empty-cell"
                >
                  Brak dostępów spełniających filtry.
                </td>
              </tr>
            </tbody>
          </table>
        </section>
        <div
          class="pagination-row"
        >
          <span>
            Strona {{ meta.page }} z {{ meta.last_page }} · {{ meta.total }} kont
          </span>
          <div
            class="row-actions"
          >
            <button
              class="button ghost"
              type="button"
              :disabled="page <= 1"
              @click="page--; loadRows()"
            >
              Wstecz
            </button>
            <button
              class="button ghost"
              type="button"
              :disabled="page >= meta.last_page"
              @click="page++; loadRows()"
            >
              Dalej
            </button>
          </div>
        </div>
      </template>
      <div
        v-if="drawerOpen"
        class="drawer-backdrop"
        @click.self="drawerOpen = false"
      >
        <aside
          class="drawer"
        >
          <div
            class="drawer-header"
          >
            <div>
              <span
                class="section-kicker"
              >
                Licencje
              </span>
              <h2>
                Przydzielanie licencji
              </h2>
            </div>
            <button
              class="icon-button"
              type="button"
              aria-label="Zamknij"
              @click="drawerOpen = false"
            >
              ×
            </button>
          </div>
          <form
            class="form-grid"
            @submit.prevent="assign"
          >
            <label
              class="full"
            >
              Rodzaj licencji
              <select
                v-model="selectedProductId"
                @change="newLanguage = selectedProduct?.languages[0] ?? 'pl'"
              >
                <option
                  value=""
                >
                  Wybierz
                </option>
                <option
                  v-for="product in products"
                  :key="product.id"
                  :value="product.id"
                >
                  {{ product.duration_days }} dni · dostępne {{ inventory.filter((row) => row.product_id === product.id).length }}
                </option>
              </select>
            </label>
            <label
              class="full"
            >
              Wyszukaj kursanta
              <div
                class="student-search compact-search"
              >
                <input
                  v-model="studentSearch"
                  type="search"
                  placeholder="Imię, nazwisko lub e-mail"
                  @input="searchStudents"
                >
                <button
                  class="button ghost"
                  type="button"
                  @click="searchStudents"
                >
                  Szukaj
                </button>
              </div>
            </label>
            <div
              v-if="studentOptions.length && !selectedStudentId"
              class="full search-result-list"
            >
              <button
                v-for="student in studentOptions"
                :key="student.id"
                type="button"
                class="search-result"
                @click="selectStudent(student)"
              >
                <strong>
                  {{ student.first_name }} {{ student.last_name }}
                </strong>
                <span>
                  {{ student.contact_email ?? 'Brak e-maila kontaktowego' }}
                </span>
              </button>
            </div>
            <template
              v-if="selectedStudentId"
            >
              <fieldset
                class="full"
              >
                <legend>
                  Konto do nauki
                </legend>
                <div
                  class="permission-list"
                >
                  <label
                    v-for="account in studentAccounts"
                    :key="account.id"
                    class="check"
                  >
                    <input
                      v-model="selectedAccountId"
                      type="radio"
                      :value="account.id"
                      @change="targetMode = 'existing'"
                    >
                    {{ account.login_identifier }} · {{ account.language_code.toUpperCase() }}
                  </label>
                  <label
                    class="check"
                  >
                    <input
                      v-model="targetMode"
                      type="radio"
                      value="new"
                      @change="selectedAccountId = ''"
                    >
                    Dodaj nowy dostęp
                  </label>
                </div>
              </fieldset>
              <template
                v-if="targetMode === 'new'"
              >
                <label
                  class="full"
                >
                  Login lub e-mail
                  <input
                    v-model="newLogin"
                  >
                </label>
                <label>
                  Język
                  <select
                    v-model="newLanguage"
                  >
                    <option
                      v-for="language in selectedProduct?.languages ?? []"
                      :key="language"
                      :value="language"
                    >
                      {{ language.toUpperCase() }}
                    </option>
                  </select>
                </label>
                <label>
                  Hasło początkowe
                  <input
                    v-model="initialPassword"
                    type="password"
                    autocomplete="new-password"
                    placeholder="Opcjonalnie"
                  >
                </label>
              </template>
            </template>
            <div
              class="form-actions full"
            >
              <button
                class="button ghost"
                type="button"
                @click="drawerOpen = false"
              >
                Anuluj
              </button>
              <button
                class="button primary"
                type="submit"
                :disabled="saving || !selectedStudentId || availableForSelectedProduct.length === 0"
              >
                {{ saving ? 'Zapisywanie…' : 'Przydziel licencję' }}
              </button>
            </div>
          </form>
        </aside>
      </div>
    </main>
  </div>
</template>
