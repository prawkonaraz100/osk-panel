<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type OrderItem = {
  id: string
  product_kind: string
  display_name_snapshot: string | null
  quantity: number
  currency: string
  unit_amount_minor: number
  line_total_minor: number
}

type PurchaseOrder = {
  id: string
  display_number: string
  status: string
  total: {
    amount_minor: number
    currency: string
  }
  items: OrderItem[]
  ordered_at: string
  booked_at: string | null
}

type PageMeta = {
  page: number
  per_page: number
  total: number
  last_page: number
}

type PurchaseHistoryResponse = {
  data: PurchaseOrder[]
  meta: PageMeta
}

type PaymentAttempt = {
  id: string
  order_id: string
  status: string
  public_payment_reference: string
}

const loading = ref(true)
const payingOrderId = ref<string | null>(null)
const error = ref('')
const notice = ref('')
const rows = ref<PurchaseOrder[]>([])
const page = ref(1)
const perPage = ref(25)
const meta = ref<PageMeta>({
  page: 1,
  per_page: 25,
  total: 0,
  last_page: 1,
})

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams({
      page: String(page.value),
      per_page: String(perPage.value),
    })
    const result = await api<PurchaseHistoryResponse>('/api/v1/purchase-history?' + params.toString())
    rows.value = result.data.data
    meta.value = result.data.meta
  } catch (caught: unknown) {
    error.value = messageFor(caught, 'Nie udało się pobrać historii zakupów.')
  } finally {
    loading.value = false
  }
}

async function changePerPage(): Promise<void> {
  page.value = 1
  await load()
}

async function previousPage(): Promise<void> {
  if (page.value <= 1) return
  page.value -= 1
  await load()
}

async function nextPage(): Promise<void> {
  if (page.value >= meta.value.last_page) return
  page.value += 1
  await load()
}

async function pay(order: PurchaseOrder): Promise<void> {
  if (order.status !== 'unpaid' || payingOrderId.value !== null) return

  payingOrderId.value = order.id
  error.value = ''
  notice.value = ''

  try {
    const result = await api<PaymentAttempt>('/api/v1/orders/' + order.id + '/payments', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({
        method: 'bank_transfer',
      }),
    })

    if (result.data.status !== 'pending') {
      throw new Error('Nowa próba płatności nie ma oczekiwanego stanu pending.')
    }

    notice.value = 'Próba płatności została utworzona. Zamówienie pozostaje nieopłacone do czasu wiarygodnego potwierdzenia płatności.'
    await load()
  } catch (caught: unknown) {
    error.value = messageFor(caught, 'Nie udało się rozpocząć płatności.')
  } finally {
    payingOrderId.value = null
  }
}

function itemName(item: OrderItem): string {
  if (item.display_name_snapshot?.trim()) return item.display_name_snapshot
  if (item.product_kind === 'internal_exam') return 'Egzaminy wewnętrzne'
  if (item.product_kind === 'license') return 'Licencje'
  return 'Pozycja zamówienia'
}

function money(amountMinor: number, currency: string): string {
  return new Intl.NumberFormat('pl-PL', {
    style: 'currency',
    currency,
  }).format(amountMinor / 100)
}

function date(value: string | null): string {
  if (!value) return '—'

  return new Intl.DateTimeFormat('pl-PL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value))
}

function statusLabel(status: string): string {
  return {
    unpaid: 'Nieopłacone',
    payment_pending: 'Płatność w toku',
    paid_processing: 'Opłacone · realizacja',
    completed: 'Zrealizowane',
    requires_reconciliation: 'Wymaga weryfikacji',
  }[status] ?? status
}

function statusClass(status: string): string {
  if (status === 'completed') return 'success'
  if (status === 'unpaid') return 'warning'
  if (status === 'requires_reconciliation') return 'danger'
  return 'muted'
}

function messageFor(caught: unknown, fallback: string): string {
  if (caught instanceof ApiError) return caught.message
  if (caught instanceof Error) return caught.message
  return fallback
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
          href="/"
        >
          Panel główny
        </a>
        <a
          href="/kursanci"
        >
          Kursanci
        </a>
        <a
          href="/licencje/panel"
        >
          Licencje
        </a>
        <a
          href="/egzamin-wewnetrzny/panel"
        >
          Egzaminy
        </a>
        <a
          href="/kalendarz"
        >
          Kalendarz
        </a>
        <a
          class="active"
          href="/historia-zakupow"
        >
          Historia zakupów
        </a>
      </nav>

      <div
        class="sidebar-foot"
      >
        Stage 5 · Core v1
      </div>
    </aside>

    <main
      class="workspace purchase-workspace"
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
            Historia zakupów
          </h1>
          <p
            class="purchase-intro"
          >
            Wspólna historia zamówień licencji i egzaminów wewnętrznych.
          </p>
        </div>
      </header>

      <div
        v-if="notice"
        class="notice success"
        role="status"
      >
        <span>
          {{ notice }}
        </span>
        <button
          type="button"
          aria-label="Zamknij komunikat"
          @click="notice = ''"
        >
          ×
        </button>
      </div>

      <div
        v-if="error"
        class="notice error"
        role="alert"
      >
        <span>
          {{ error }}
        </span>
        <button
          type="button"
          aria-label="Zamknij komunikat"
          @click="error = ''"
        >
          ×
        </button>
      </div>

      <section
        class="purchase-toolbar"
      >
        <div>
          <span
            class="section-kicker"
          >
            Zamówienia OSK
          </span>
          <strong>
            {{ meta.total }} rekordów
          </strong>
        </div>

        <label
          class="page-size"
        >
          Pokaż
          <select
            v-model.number="perPage"
            @change="changePerPage"
          >
            <option
              :value="10"
            >
              10
            </option>
            <option
              :value="25"
            >
              25
            </option>
            <option
              :value="50"
            >
              50
            </option>
            <option
              :value="100"
            >
              100
            </option>
          </select>
          pozycji
        </label>
      </section>

      <div
        v-if="loading"
        class="loading-card"
      >
        Ładowanie historii zakupów…
      </div>

      <section
        v-else
        class="table-card purchase-table-card"
      >
        <table
          class="purchase-table"
        >
          <thead>
            <tr>
              <th>
                Number
              </th>
              <th>
                Zamówienie
              </th>
              <th>
                Data
              </th>
              <th>
                Data księgowania
              </th>
              <th>
                Kwota
              </th>
              <th>
                Status
              </th>
              <th
                class="actions-column"
              />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="order in rows"
              :key="order.id"
            >
              <td>
                <strong>
                  {{ order.display_number }}
                </strong>
              </td>
              <td>
                <div
                  class="order-items"
                >
                  <div
                    v-for="item in order.items"
                    :key="item.id"
                    class="order-item"
                  >
                    <span>
                      {{ itemName(item) }}
                    </span>
                    <small>
                      ilość: {{ item.quantity }}
                    </small>
                  </div>
                </div>
              </td>
              <td>
                {{ date(order.ordered_at) }}
              </td>
              <td>
                {{ date(order.booked_at) }}
              </td>
              <td>
                <strong>
                  {{ money(order.total.amount_minor, order.total.currency) }}
                </strong>
              </td>
              <td>
                <span
                  class="purchase-status"
                  :class="statusClass(order.status)"
                >
                  {{ statusLabel(order.status) }}
                </span>
              </td>
              <td
                class="actions-column"
              >
                <button
                  v-if="order.status === 'unpaid'"
                  class="button primary compact-button"
                  type="button"
                  :disabled="payingOrderId !== null"
                  @click="pay(order)"
                >
                  {{ payingOrderId === order.id ? 'Uruchamianie…' : 'Opłać' }}
                </button>
              </td>
            </tr>

            <tr
              v-if="rows.length === 0"
            >
              <td
                colspan="7"
                class="empty-cell"
              >
                Brak zamówień w historii.
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <div
        v-if="!loading"
        class="pagination-row purchase-pagination"
      >
        <span>
          Strona {{ meta.page }} z {{ meta.last_page }}
        </span>
        <div
          class="row-actions"
        >
          <button
            class="button ghost"
            type="button"
            :disabled="page <= 1"
            @click="previousPage"
          >
            Wstecz
          </button>
          <button
            class="button ghost"
            type="button"
            :disabled="page >= meta.last_page"
            @click="nextPage"
          >
            Dalej
          </button>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.purchase-workspace {
  width: min(1540px, 100%);
}

.purchase-intro {
  margin: 8px 0 0;
  color: var(--muted);
}

.purchase-toolbar {
  margin-bottom: 14px;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 18px;
}

.purchase-toolbar > div {
  display: grid;
  gap: 5px;
}

.purchase-toolbar > div > strong {
  font-size: 14px;
}

.page-size {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--muted);
  font-size: 12px;
}

.page-size select {
  min-height: 38px;
  padding: 7px 28px 7px 10px;
  border: 1px solid var(--line-strong);
  border-radius: 9px;
  background: #fff;
  color: var(--text);
}

.purchase-table-card {
  overflow-x: auto;
}

.purchase-table {
  min-width: 980px;
}

.purchase-table td {
  vertical-align: top;
}

.purchase-table th:nth-child(1) {
  width: 90px;
}

.purchase-table th:nth-child(2) {
  width: 34%;
}

.order-items {
  display: grid;
  gap: 7px;
}

.order-item {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 14px;
}

.order-item span {
  font-weight: 620;
}

.order-item small {
  flex: 0 0 auto;
  color: var(--muted);
}

.purchase-status {
  display: inline-flex;
  align-items: center;
  min-height: 26px;
  padding: 5px 9px;
  border-radius: 999px;
  background: #efefed;
  color: #5d5a54;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}

.purchase-status.success {
  background: var(--success-soft);
  color: var(--success);
}

.purchase-status.warning {
  background: #fff7df;
  color: #7a5a00;
}

.purchase-status.danger {
  background: var(--danger-soft);
  color: var(--danger);
}

.compact-button {
  min-height: 34px;
  padding: 7px 12px;
}

.purchase-pagination {
  padding-top: 18px;
}

@media (max-width: 760px) {
  .purchase-toolbar {
    align-items: stretch;
    flex-direction: column;
  }

  .page-size {
    justify-content: space-between;
  }
}
</style>
