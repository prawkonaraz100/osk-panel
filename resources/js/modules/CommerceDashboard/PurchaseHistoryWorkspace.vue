<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type Money = {
  amount_minor: number
  currency: string
}

type OrderItem = {
  id: string
  product_kind: string
  display_name_snapshot: string | null
  quantity: number
  line_total_minor: number
  currency: string
}

type PurchaseOrder = {
  id: string
  display_number: string
  status: string
  total: Money
  items: OrderItem[]
  ordered_at: string
  booked_at: string | null
}

type PaymentAttempt = {
  id: string
  order_id: string
  status: string
  provider: string
  public_payment_reference: string
}

type Paginated<T> = {
  data: T[]
  meta: { page: number; per_page: number; total: number; last_page: number }
}

const PAGE_SIZES = [10, 25, 50, 100] as const

const loading = ref(false)
const payingOrderId = ref('')
const error = ref('')
const notice = ref('')
const orders = ref<PurchaseOrder[]>([])
const meta = ref({ page: 1, per_page: 10, total: 0, last_page: 1 })
const page = ref(1)
const perPage = ref<(typeof PAGE_SIZES)[number]>(10)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams({
      page: String(page.value),
      per_page: String(perPage.value),
    })
    const result = await api<Paginated<PurchaseOrder>>(`/api/v1/purchase-history?${params.toString()}`)
    orders.value = result.data.data
    meta.value = result.data.meta
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function changePage(next: number): Promise<void> {
  if (next < 1 || next > meta.value.last_page || next === page.value) return
  page.value = next
  await load()
}

async function changePageSize(): Promise<void> {
  page.value = 1
  await load()
}

async function pay(order: PurchaseOrder): Promise<void> {
  payingOrderId.value = order.id
  error.value = ''
  notice.value = ''

  try {
    const result = await api<PaymentAttempt>(`/api/v1/orders/${order.id}/payments`, {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({
        method: 'bank_transfer',
        return_url: window.location.href,
      }),
    })

    notice.value = `Rozpoczęto płatność. Bezpieczna referencja: ${result.data.public_payment_reference}`
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    payingOrderId.value = ''
  }
}

function statusLabel(status: string): string {
  return {
    unpaid: 'Nieopłacone',
    payment_pending: 'Płatność rozpoczęta',
    paid_processing: 'Opłacone · realizacja',
    completed: 'Zrealizowane',
    requires_reconciliation: 'Wymaga wyjaśnienia',
  }[status] ?? status
}

function formatMoney(value: Money): string {
  return new Intl.NumberFormat('pl-PL', {
    style: 'currency',
    currency: value.currency,
  }).format(value.amount_minor / 100)
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('pl-PL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'Europe/Warsaw',
  }).format(new Date(value))
}

function itemLabel(item: OrderItem): string {
  return item.display_name_snapshot?.trim() || item.product_kind
}

function handleError(caught: unknown): void {
  error.value = caught instanceof ApiError
    ? caught.message
    : caught instanceof Error
      ? caught.message
      : 'Nie udało się pobrać historii zakupów.'
}
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="/">OSK <strong>Panel</strong></a>
      <nav class="main-nav" aria-label="Główna nawigacja">
        <a href="/">Panel główny</a>
        <a href="/kursanci">Kursanci</a>
        <a href="/kalendarz">Kalendarz</a>
        <a href="/licencje/panel">Licencje</a>
        <a href="/egzamin-wewnetrzny/panel">Egzaminy</a>
        <a class="active" href="/historia-zakupow">Historia zakupów</a>
        <a href="/lokalizacje">Lokalizacje</a>
        <a href="/pracownicy">Pracownicy</a>
        <a href="/pojazdy">Pojazdy</a>
      </nav>
      <div class="sidebar-foot">Stage 5 · Core v1</div>
    </aside>

    <main class="workspace">
      <header class="workspace-header">
        <div>
          <div class="eyebrow">PrawkoNaRaz · OSK</div>
          <h1>Historia zakupów</h1>
        </div>
      </header>

      <div v-if="notice" class="notice success">
        <span>{{ notice }}</span>
        <button type="button" aria-label="Zamknij" @click="notice = ''">×</button>
      </div>
      <div v-if="error" class="notice error">
        <span>{{ error }}</span>
        <button type="button" aria-label="Zamknij" @click="error = ''">×</button>
      </div>

      <section class="purchase-panel">
        <div class="purchase-toolbar">
          <div>
            <span class="section-kicker">Zakupy OSK</span>
            <h2>Wspólna historia zamówień</h2>
            <p>Licencje, egzaminy i kolejne usługi są widoczne w jednym miejscu.</p>
          </div>
          <label>
            <span>Pozycji na stronie</span>
            <select v-model.number="perPage" @change="changePageSize">
              <option v-for="size in PAGE_SIZES" :key="size" :value="size">
                {{ size }}
              </option>
            </select>
          </label>
        </div>

        <div v-if="loading" class="loading-card purchase-loading">
          Ładowanie historii…
        </div>

        <div v-else-if="orders.length" class="purchase-table-wrap">
          <table class="purchase-table">
            <thead>
              <tr>
                <th>Number</th>
                <th>Zamówienie</th>
                <th>Data</th>
                <th>Data księgowania</th>
                <th>Kwota</th>
                <th>Status</th>
                <th aria-label="Akcje" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in orders" :key="order.id">
                <td><strong>#{{ order.display_number }}</strong></td>
                <td>
                  <div class="purchase-items">
                    <span v-for="item in order.items" :key="item.id">
                      {{ itemLabel(item) }} <small>× {{ item.quantity }}</small>
                    </span>
                  </div>
                </td>
                <td>{{ formatDate(order.ordered_at) }}</td>
                <td>{{ formatDate(order.booked_at) }}</td>
                <td><strong>{{ formatMoney(order.total) }}</strong></td>
                <td>
                  <span class="purchase-status" :class="order.status">
                    {{ statusLabel(order.status) }}
                  </span>
                </td>
                <td class="purchase-action-cell">
                  <button
                    v-if="order.status === 'unpaid'"
                    class="button primary"
                    type="button"
                    :disabled="payingOrderId === order.id"
                    @click="pay(order)"
                  >
                    {{ payingOrderId === order.id ? 'Uruchamianie…' : 'Opłać' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-else-if="!loading" class="purchase-empty">
          <strong>Brak zakupów</strong>
          <span>Gdy pojawią się zamówienia, zobaczysz je tutaj.</span>
        </div>

        <div class="purchase-pagination">
          <span>Łącznie: {{ meta.total }}</span>
          <div>
            <button
              class="button ghost"
              type="button"
              :disabled="page <= 1"
              @click="changePage(page - 1)"
            >
              Poprzednia
            </button>
            <span>Strona {{ meta.page }} z {{ meta.last_page }}</span>
            <button
              class="button ghost"
              type="button"
              :disabled="page >= meta.last_page"
              @click="changePage(page + 1)"
            >
              Następna
            </button>
          </div>
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
.purchase-panel {
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: var(--surface);
}

.purchase-toolbar,
.purchase-pagination,
.purchase-pagination > div {
  display: flex;
  align-items: center;
  gap: 12px;
}

.purchase-toolbar,
.purchase-pagination {
  justify-content: space-between;
}

.purchase-toolbar {
  padding: 22px 24px;
  border-bottom: 1px solid var(--line);
}

.purchase-toolbar h2 {
  margin: 5px 0 4px;
  font-size: 22px;
  letter-spacing: -.03em;
}

.purchase-toolbar p {
  margin: 0;
  color: var(--muted);
  font-size: 13px;
}

.purchase-toolbar label {
  display: grid;
  gap: 6px;
  color: var(--muted);
  font-size: 11px;
}

.purchase-toolbar select {
  min-width: 110px;
  min-height: 40px;
  padding: 8px 10px;
  border: 1px solid var(--line-strong);
  border-radius: 9px;
  background: #fff;
}

.purchase-loading {
  border: 0;
  border-radius: 0;
}

.purchase-table-wrap {
  overflow-x: auto;
}

.purchase-table {
  width: 100%;
  min-width: 1060px;
  border-collapse: collapse;
}

.purchase-table th,
.purchase-table td {
  padding: 14px 16px;
  border-bottom: 1px solid var(--line);
  text-align: left;
  vertical-align: top;
}

.purchase-table th {
  background: #fbfbfa;
  color: var(--muted);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .04em;
}

.purchase-table td {
  font-size: 13px;
}

.purchase-items {
  min-width: 230px;
  display: grid;
  gap: 5px;
}

.purchase-items small {
  color: var(--muted);
}

.purchase-status {
  width: fit-content;
  padding: 5px 8px;
  border-radius: 999px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 11px;
  font-weight: 700;
}

.purchase-status.unpaid,
.purchase-status.requires_reconciliation {
  background: #fff5df;
  color: #7a5913;
}

.purchase-status.completed {
  background: var(--success-soft);
  color: var(--success);
}

.purchase-action-cell {
  width: 120px;
  text-align: right !important;
}

.purchase-pagination {
  min-height: 68px;
  padding: 12px 24px;
  color: var(--muted);
  font-size: 12px;
}

.purchase-empty {
  min-height: 240px;
  display: grid;
  place-content: center;
  gap: 6px;
  text-align: center;
}

.purchase-empty span {
  color: var(--muted);
  font-size: 12px;
}

@media (max-width: 760px) {
  .purchase-toolbar,
  .purchase-pagination {
    align-items: stretch;
    flex-direction: column;
  }

  .purchase-pagination > div {
    justify-content: space-between;
  }
}
</style>
