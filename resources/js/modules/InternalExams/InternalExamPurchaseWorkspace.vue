<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type Money = {
  amount_minor: number
  currency: string
}

type PurchaseOffer = {
  display_name: string
  unit_price: Money
  list_unit_price: Money
  pricing_revision: string
  sample_data: boolean
}

type CreatedOrder = {
  id: string
  display_number: string
  status: string
  total: Money
  items: Array<{
    product_kind: string
    quantity: number
    unit_amount_minor: number
    line_total_minor: number
  }>
  created_at: string
}

const loading = ref(true)
const pending = ref(false)
const error = ref('')
const notice = ref('')
const offer = ref<PurchaseOffer | null>(null)
const quantity = ref(1)
const paymentMethod = ref<'bank_transfer' | 'online_payment'>('bank_transfer')
const createdOrder = ref<CreatedOrder | null>(null)

onMounted(loadOffer)

async function loadOffer(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const result = await api<PurchaseOffer>('/api/v1/internal-exam/purchase-offer')
    offer.value = result.data
  } catch (caught: unknown) {
    offer.value = null
    error.value = messageFor(caught, 'Nie udało się pobrać aktualnej oferty egzaminów wewnętrznych.')
  } finally {
    loading.value = false
  }
}

const normalizedQuantity = computed(() => {
  const value = Number(quantity.value)
  if (!Number.isFinite(value) || value < 1) return 0
  return Math.floor(value)
})

const previewTotalMinor = computed(() => {
  if (!offer.value || normalizedQuantity.value < 1) return null

  const total = offer.value.unit_price.amount_minor * normalizedQuantity.value
  return Number.isSafeInteger(total) ? total : null
})

const canSubmit = computed(() => (
  !loading.value
  && !pending.value
  && offer.value !== null
  && normalizedQuantity.value >= 1
  && previewTotalMinor.value !== null
))

function normalizeQuantity(): void {
  quantity.value = Math.max(1, normalizedQuantity.value || 1)
  clearCreatedOrder()
}

function changeQuantity(delta: number): void {
  const next = normalizedQuantity.value + delta
  quantity.value = Math.max(1, next)
  clearCreatedOrder()
}

function clearCreatedOrder(): void {
  createdOrder.value = null
  notice.value = ''
}

async function submitOrder(): Promise<void> {
  error.value = ''
  notice.value = ''
  createdOrder.value = null

  if (!offer.value || normalizedQuantity.value < 1) {
    error.value = 'Podaj liczbę egzaminów większą od zera.'
    return
  }

  if (previewTotalMinor.value === null) {
    error.value = 'Wybrana ilość przekracza bezpieczny zakres podglądu ceny.'
    return
  }

  pending.value = true

  try {
    const result = await api<CreatedOrder>('/api/v1/internal-exam/orders', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({
        quantity: normalizedQuantity.value,
        payment_method: paymentMethod.value,
      }),
    })

    createdOrder.value = result.data
    notice.value = `Zamówienie nr ${result.data.display_number} zostało utworzone. Ostateczna kwota zaakceptowana przez serwer: ${money(result.data.total.amount_minor, result.data.total.currency)}.`
  } catch (caught: unknown) {
    error.value = messageFor(caught, 'Nie udało się utworzyć zamówienia egzaminów wewnętrznych.')
  } finally {
    pending.value = false
  }
}

function money(amountMinor: number, currency: string): string {
  return new Intl.NumberFormat('pl-PL', {
    style: 'currency',
    currency,
  }).format(amountMinor / 100)
}

function messageFor(caught: unknown, fallback: string): string {
  if (caught instanceof ApiError) {
    return Object.values(caught.fields).flat().join(' ') || caught.message
  }

  if (caught instanceof Error) {
    return caught.message
  }

  return fallback
}
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="/">
        OSK
        <strong>Panel</strong>
      </a>

      <nav class="main-nav" aria-label="Główna nawigacja">
        <a href="/kursanci">Kursanci</a>
        <a href="/lokalizacje">Lokalizacje</a>
        <a href="/pracownicy">Pracownicy</a>
        <a href="/pojazdy">Pojazdy</a>
        <a href="/kalendarz">Kalendarz</a>
        <a href="/licencje/panel">Licencje</a>
        <a class="active" href="/egzamin-wewnetrzny/panel">Egzaminy</a>
        <a href="/ustawienia">Ustawienia</a>
      </nav>

      <div class="sidebar-foot">
        Stage 5 · Core v1
      </div>
    </aside>

    <main class="workspace exam-purchase-workspace">
      <header class="workspace-header">
        <div>
          <div class="eyebrow">PrawkoNaRaz · OSK</div>
          <h1>Wykup egzaminy wewnętrzne</h1>
          <p class="purchase-copy">
            Wybierz liczbę jednostek egzaminowych. Podgląd ceny pochodzi z aktualnej oferty serwera, a backend ponownie rozstrzyga cenę przy tworzeniu zamówienia.
          </p>
        </div>

        <div class="header-actions">
          <a class="button ghost" href="/egzamin-wewnetrzny/panel">Panel egzaminów</a>
          <a class="button ghost" href="/historia-zakupow">Historia zakupów</a>
        </div>
      </header>

      <div v-if="notice" class="notice success" role="status">
        {{ notice }}
      </div>

      <div v-if="error" class="notice error" role="alert">
        {{ error }}
      </div>

      <div
        v-if="offer?.sample_data"
        class="notice warning purchase-warning"
        role="status"
      >
        To środowisko korzysta z przykładowej ceny developerskiej egzaminów. Nie jest to cena produkcyjna.
      </div>

      <div v-if="loading" class="loading-card">
        Ładowanie aktualnej oferty…
      </div>

      <div v-else class="exam-purchase-layout">
        <section class="exam-purchase-card">
          <span class="section-kicker">Pula egzaminów</span>
          <h2>{{ offer?.display_name ?? 'Oferta niedostępna' }}</h2>

          <template v-if="offer">
            <div class="unit-price">
              <span>Cena za 1 egzamin</span>
              <strong>{{ money(offer.unit_price.amount_minor, offer.unit_price.currency) }}</strong>
              <small
                v-if="offer.list_unit_price.amount_minor !== offer.unit_price.amount_minor"
              >
                Cena katalogowa: {{ money(offer.list_unit_price.amount_minor, offer.list_unit_price.currency) }}
              </small>
            </div>

            <div class="quantity-block">
              <div>
                <strong>Ile egzaminów potrzebujesz?</strong>
                <p>
                  Dokładny limit self-service nie ma jeszcze zatwierdzonej reguły biznesowej, dlatego ekran nie hardkoduje slidera ani maksymalnej ilości.
                </p>
              </div>

              <div class="quantity-stepper">
                <button
                  type="button"
                  :disabled="normalizedQuantity <= 1"
                  aria-label="Zmniejsz liczbę egzaminów"
                  @click="changeQuantity(-1)"
                >
                  −
                </button>
                <input
                  v-model.number="quantity"
                  type="number"
                  inputmode="numeric"
                  min="1"
                  step="1"
                  aria-label="Liczba egzaminów"
                  @change="normalizeQuantity"
                  @input="clearCreatedOrder"
                >
                <button
                  type="button"
                  aria-label="Zwiększ liczbę egzaminów"
                  @click="changeQuantity(1)"
                >
                  +
                </button>
              </div>
            </div>

            <div class="volume-note">
              <div>
                <span class="section-kicker">Większy wolumen</span>
                <strong>Potrzebujesz większej puli?</strong>
              </div>
              <p>
                Oferta indywidualna może zostać obsłużona poza standardowym checkoutem. Publicznego progu nie ustalamy bez osobnej authority biznesowej.
              </p>
            </div>
          </template>

          <div v-else class="empty-inline">
            <strong>Brak aktualnej oferty zakupu.</strong>
            <span>Frontend nie tworzy ceny zastępczej. Sprawdź konfigurację katalogu i cennika po stronie serwera.</span>
          </div>
        </section>

        <aside class="purchase-summary-card">
          <span class="section-kicker">Podsumowanie</span>
          <h2>Zamówienie</h2>

          <dl class="summary-list">
            <div>
              <dt>Liczba egzaminów</dt>
              <dd>{{ normalizedQuantity }}</dd>
            </div>
            <div>
              <dt>Cena jednostkowa</dt>
              <dd>
                {{ offer ? money(offer.unit_price.amount_minor, offer.unit_price.currency) : '—' }}
              </dd>
            </div>
            <div class="summary-total">
              <dt>Do zapłaty</dt>
              <dd>
                {{ offer && previewTotalMinor !== null ? money(previewTotalMinor, offer.unit_price.currency) : '—' }}
              </dd>
            </div>
          </dl>

          <p class="server-authority-note">
            Podgląd jest wyliczony wyłącznie z ceny zwróconej przez serwer. Przeglądarka nie wysyła ceny, VAT, rabatu ani sumy w żądaniu zamówienia.
          </p>

          <fieldset class="payment-fieldset">
            <legend>Metoda płatności</legend>

            <label>
              <input
                v-model="paymentMethod"
                type="radio"
                value="bank_transfer"
              >
              <span>
                <strong>Przelew bankowy</strong>
                <small>Zamówienie pozostaje oczekujące do wiarygodnego potwierdzenia płatności.</small>
              </span>
            </label>

            <label>
              <input
                v-model="paymentMethod"
                type="radio"
                value="online_payment"
              >
              <span>
                <strong>Płatność online</strong>
                <small>Provider-neutral intent; ekran nie deklaruje aktywnej integracji konkretnego operatora.</small>
              </span>
            </label>
          </fieldset>

          <button
            class="button primary purchase-submit"
            type="button"
            :disabled="!canSubmit"
            @click="submitOrder"
          >
            {{ pending ? 'Tworzenie zamówienia…' : 'Kup teraz' }}
          </button>

          <div v-if="createdOrder" class="created-order-card">
            <strong>Zamówienie nr {{ createdOrder.display_number }}</strong>
            <span>Status: {{ createdOrder.status }}</span>
            <span>Kwota serwera: {{ money(createdOrder.total.amount_minor, createdOrder.total.currency) }}</span>
            <a href="/historia-zakupow">Przejdź do historii zakupów</a>
          </div>
        </aside>
      </div>
    </main>
  </div>
</template>

<style scoped>
.exam-purchase-workspace {
  width: min(1400px, 100%);
}

.purchase-copy {
  max-width: 760px;
  margin: 8px 0 0;
  color: var(--muted);
  line-height: 1.6;
}

.purchase-warning {
  color: #68571f;
  border-color: #e5cf8c;
  background: #fffaf0;
}

.exam-purchase-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(300px, 390px);
  gap: 24px;
  align-items: start;
}

.exam-purchase-card,
.purchase-summary-card {
  border: 1px solid var(--line);
  border-radius: 16px;
  background: #fff;
}

.exam-purchase-card {
  padding: 24px;
}

.exam-purchase-card h2,
.purchase-summary-card h2 {
  margin: 6px 0 0;
  font-size: 22px;
}

.unit-price {
  margin: 24px 0;
  padding: 18px 0;
  display: grid;
  gap: 4px;
  border-top: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
}

.unit-price span,
.unit-price small {
  color: var(--muted);
  font-size: 12px;
}

.unit-price strong {
  font-size: 28px;
}

.quantity-block {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 28px;
}

.quantity-block > div:first-child {
  max-width: 650px;
}

.quantity-block p {
  margin: 6px 0 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.55;
}

.quantity-stepper {
  display: inline-grid;
  grid-template-columns: 42px 100px 42px;
  align-items: center;
  flex: 0 0 auto;
}

.quantity-stepper button,
.quantity-stepper input {
  min-height: 42px;
  border: 1px solid var(--line-strong);
  background: #fff;
}

.quantity-stepper button {
  cursor: pointer;
  font-size: 18px;
}

.quantity-stepper button:first-child {
  border-radius: 9px 0 0 9px;
}

.quantity-stepper button:last-child {
  border-radius: 0 9px 9px 0;
}

.quantity-stepper button:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.quantity-stepper input {
  width: 100px;
  margin: 0 -1px;
  padding: 7px;
  text-align: center;
  appearance: textfield;
}

.quantity-stepper input::-webkit-inner-spin-button,
.quantity-stepper input::-webkit-outer-spin-button {
  margin: 0;
  appearance: none;
}

.volume-note {
  margin-top: 26px;
  padding: 18px;
  display: flex;
  justify-content: space-between;
  gap: 24px;
  border-radius: 12px;
  background: var(--surface-soft, #f7f6f2);
}

.volume-note > div {
  display: grid;
  gap: 5px;
}

.volume-note p {
  max-width: 620px;
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.55;
}

.purchase-summary-card {
  position: sticky;
  top: 20px;
  padding: 22px;
}

.summary-list {
  margin: 20px 0 0;
  display: grid;
}

.summary-list > div {
  padding: 11px 0;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  border-bottom: 1px solid var(--line);
}

.summary-list dt {
  color: var(--muted);
  font-size: 12px;
}

.summary-list dd {
  margin: 0;
  font-size: 13px;
  font-weight: 700;
  text-align: right;
}

.summary-list .summary-total {
  padding-top: 16px;
  border-bottom: 0;
}

.summary-total dt,
.summary-total dd {
  color: var(--text);
  font-size: 17px;
  font-weight: 800;
}

.server-authority-note {
  margin: 4px 0 18px;
  padding: 12px;
  border-radius: 10px;
  background: var(--surface-soft, #f7f6f2);
  color: var(--muted);
  font-size: 11px;
  line-height: 1.5;
}

.payment-fieldset {
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
  border: 0;
}

.payment-fieldset legend {
  margin-bottom: 8px;
  font-size: 13px;
  font-weight: 700;
}

.payment-fieldset label {
  padding: 11px 12px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  border: 1px solid var(--line);
  border-radius: 10px;
  cursor: pointer;
}

.payment-fieldset input {
  margin-top: 2px;
}

.payment-fieldset span {
  display: grid;
  gap: 3px;
}

.payment-fieldset strong {
  font-size: 12px;
}

.payment-fieldset small {
  color: var(--muted);
  font-size: 10px;
  line-height: 1.45;
}

.purchase-submit {
  width: 100%;
  margin-top: 18px;
}

.created-order-card {
  margin-top: 16px;
  padding: 14px;
  display: grid;
  gap: 5px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: var(--success-soft);
  font-size: 12px;
}

.created-order-card span {
  color: var(--muted);
}

.created-order-card a {
  margin-top: 4px;
  font-weight: 700;
  text-decoration: underline;
}

@media (max-width: 1000px) {
  .exam-purchase-layout {
    grid-template-columns: 1fr;
  }

  .purchase-summary-card {
    position: static;
  }
}

@media (max-width: 720px) {
  .quantity-block,
  .volume-note {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
