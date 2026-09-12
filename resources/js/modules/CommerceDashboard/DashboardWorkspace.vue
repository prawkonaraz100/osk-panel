<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type ActivityItem = {
  id: string
  event_type: string
  timestamp: string
  actor_display_name: string | null
  related_entity_type: string | null
  related_entity_id: string | null
  description: string
  safe_details: Record<string, unknown> | null
}

type CalendarItem = {
  id: string
  event_type: string
  name: string | null
  starts_at: string
  ends_at: string
  status: string
}

type DashboardPayload = {
  licenses: {
    active_count: number
    available_count: number
  }
  internal_exams: {
    available_count: number
  }
  activity: ActivityItem[]
  calendar: {
    period_start: string
    period_end: string
    events: CalendarItem[]
  }
}

type CalendarView = 'month' | 'week' | 'day'

const PRODUCT_TIME_ZONE = 'Europe/Warsaw'
const loading = ref(true)
const calendarLoading = ref(false)
const error = ref('')
const data = ref<DashboardPayload | null>(null)
const calendarView = ref<CalendarView>('month')
const calendarCursor = ref(new Date())
const calendarEvents = ref<CalendarItem[]>([])

const period = computed(() => calendarPeriod(calendarCursor.value, calendarView.value))
const periodLabel = computed(() => {
  const formatter = new Intl.DateTimeFormat('pl-PL', {
    month: calendarView.value === 'day' ? 'long' : 'long',
    year: 'numeric',
    day: calendarView.value === 'day' ? 'numeric' : undefined,
    timeZone: PRODUCT_TIME_ZONE,
  })

  if (calendarView.value === 'week') {
    const end = new Date(period.value.end.getTime() - 1)
    return `${formatShortDate(period.value.start)} – ${formatShortDate(end)}`
  }

  return formatter.format(calendarCursor.value)
})

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const result = await api<DashboardPayload>('/api/v1/dashboard')
    data.value = result.data
    calendarEvents.value = result.data.calendar.events
  } catch (caught: unknown) {
    error.value = messageFor(caught, 'Nie udało się pobrać panelu głównego.')
  } finally {
    loading.value = false
  }
}

async function loadCalendar(): Promise<void> {
  calendarLoading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams({
      from: period.value.start.toISOString(),
      to: period.value.end.toISOString(),
    })
    calendarEvents.value = (await api<CalendarItem[]>(`/api/v1/calendar/events?${params.toString()}`)).data
  } catch (caught: unknown) {
    error.value = messageFor(caught, 'Nie udało się odświeżyć kalendarza.')
  } finally {
    calendarLoading.value = false
  }
}

function navigateCalendar(direction: -1 | 1): void {
  const next = new Date(calendarCursor.value)
  if (calendarView.value === 'month') {
    next.setMonth(next.getMonth() + direction)
  } else if (calendarView.value === 'week') {
    next.setDate(next.getDate() + (7 * direction))
  } else {
    next.setDate(next.getDate() + direction)
  }
  calendarCursor.value = next
  void loadCalendar()
}

function calendarToday(): void {
  calendarCursor.value = new Date()
  void loadCalendar()
}

function setCalendarView(view: CalendarView): void {
  calendarView.value = view
  void loadCalendar()
}

function calendarPeriod(cursor: Date, view: CalendarView): { start: Date; end: Date } {
  const start = new Date(cursor)

  if (view === 'month') {
    start.setDate(1)
    start.setHours(0, 0, 0, 0)
    const end = new Date(start)
    end.setMonth(end.getMonth() + 1)
    return { start, end }
  }

  if (view === 'week') {
    const weekday = start.getDay()
    const mondayOffset = weekday === 0 ? -6 : 1 - weekday
    start.setDate(start.getDate() + mondayOffset)
    start.setHours(0, 0, 0, 0)
    const end = new Date(start)
    end.setDate(end.getDate() + 7)
    return { start, end }
  }

  start.setHours(0, 0, 0, 0)
  const end = new Date(start)
  end.setDate(end.getDate() + 1)
  return { start, end }
}

function eventTitle(item: CalendarItem): string {
  if (item.name?.trim()) return item.name
  return item.event_type === 'driving_lesson' ? 'Jazda' : 'Wydarzenie'
}

function eventDate(item: CalendarItem): string {
  return new Intl.DateTimeFormat('pl-PL', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(new Date(item.starts_at))
}

function activityDate(value: string): string {
  return new Intl.DateTimeFormat('pl-PL', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(new Date(value))
}

function formatShortDate(value: Date): string {
  return new Intl.DateTimeFormat('pl-PL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(value)
}

function messageFor(caught: unknown, fallback: string): string {
  if (caught instanceof ApiError) return caught.message
  if (caught instanceof Error) return caught.message
  return fallback
}
</script>

<template>
  <div
    class="app-shell dashboard-shell"
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
          class="active"
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
      </nav>

      <div
        class="sidebar-foot"
      >
        Stage 5 · Core v1
      </div>
    </aside>

    <main
      class="workspace dashboard-workspace"
    >
      <header
        class="workspace-header dashboard-header"
      >
        <div>
          <div
            class="eyebrow"
          >
            PrawkoNaRaz · OSK
          </div>
          <h1>
            Panel główny
          </h1>
          <p>
            Najważniejsze informacje z Twojej szkoły w jednym miejscu.
          </p>
        </div>
      </header>

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

      <div
        v-if="loading"
        class="loading-card"
      >
        Ładowanie panelu głównego…
      </div>

      <template
        v-else-if="data"
      >
        <section
          class="dashboard-top-grid"
          aria-label="Podsumowanie"
        >
          <article
            class="dashboard-card metric-card"
          >
            <div
              class="dashboard-card-head"
            >
              <div>
                <span
                  class="section-kicker"
                >
                  Dostępy do nauki
                </span>
                <h2>
                  Licencje
                </h2>
              </div>
              <a
                class="text-link strong"
                href="/licencje/panel"
              >
                Więcej
              </a>
            </div>

            <div
              class="metric-pair"
            >
              <div>
                <strong>
                  {{ data.licenses.active_count }}
                </strong>
                <span>
                  Aktywne licencje
                </span>
              </div>
              <div>
                <strong>
                  {{ data.licenses.available_count }}
                </strong>
                <span>
                  Dostępne licencje
                </span>
              </div>
            </div>

            <div
              class="dashboard-actions"
            >
              <a
                class="button primary"
                href="/licencje/panel"
              >
                Przydziel licencję
              </a>
              <a
                class="button ghost"
                href="/licencje/panel"
              >
                Zarządzaj licencjami
              </a>
            </div>
          </article>

          <article
            class="dashboard-card metric-card"
          >
            <div
              class="dashboard-card-head"
            >
              <div>
                <span
                  class="section-kicker"
                >
                  Egzaminy wewnętrzne
                </span>
                <h2>
                  Egzaminy
                </h2>
              </div>
              <a
                class="text-link strong"
                href="/egzamin-wewnetrzny/panel"
              >
                Więcej
              </a>
            </div>

            <div
              class="exam-metric"
            >
              <strong>
                {{ data.internal_exams.available_count }}
              </strong>
              <span>
                Dostępne egzaminy
              </span>
            </div>

            <div
              class="dashboard-actions"
            >
              <a
                class="button primary"
                href="/egzamin-wewnetrzny/panel"
              >
                Przeprowadź egzamin
              </a>
              <a
                class="button ghost"
                href="/egzamin-wewnetrzny/panel"
              >
                Panel egzaminów
              </a>
            </div>
          </article>
        </section>

        <section
          class="dashboard-bottom-grid"
        >
          <article
            class="dashboard-card activity-card"
          >
            <div
              class="dashboard-card-head"
            >
              <div>
                <span
                  class="section-kicker"
                >
                  Aktywność OSK
                </span>
                <h2>
                  Powiadomienia
                </h2>
              </div>
            </div>

            <div
              v-if="data.activity.length"
              class="activity-feed"
            >
              <article
                v-for="item in data.activity"
                :key="item.id"
                class="activity-item"
              >
                <span
                  class="activity-dot"
                  aria-hidden="true"
                />
                <div>
                  <strong>
                    {{ item.description }}
                  </strong>
                  <p>
                    <span>
                      {{ activityDate(item.timestamp) }}
                    </span>
                    <span
                      v-if="item.actor_display_name"
                    >
                      · przez {{ item.actor_display_name }}
                    </span>
                  </p>
                </div>
              </article>
            </div>

            <div
              v-else
              class="dashboard-empty"
            >
              Brak nowych zdarzeń w aktywności organizacji.
            </div>
          </article>

          <article
            class="dashboard-card calendar-card"
          >
            <div
              class="dashboard-card-head calendar-card-head"
            >
              <div>
                <span
                  class="section-kicker"
                >
                  Plan pracy
                </span>
                <h2>
                  Kalendarz
                </h2>
              </div>
              <div
                class="dashboard-actions compact"
              >
                <a
                  class="button ghost"
                  href="/kalendarz"
                >
                  Pełny kalendarz
                </a>
                <a
                  class="button primary"
                  href="/kalendarz?action=create"
                >
                  Dodaj wydarzenie
                </a>
              </div>
            </div>

            <div
              class="dashboard-calendar-toolbar"
            >
              <div
                class="period-nav"
              >
                <button
                  type="button"
                  aria-label="Poprzedni okres"
                  @click="navigateCalendar(-1)"
                >
                  ←
                </button>
                <button
                  type="button"
                  @click="calendarToday"
                >
                  Dzisiaj
                </button>
                <button
                  type="button"
                  aria-label="Następny okres"
                  @click="navigateCalendar(1)"
                >
                  →
                </button>
              </div>

              <strong>
                {{ periodLabel }}
              </strong>

              <div
                class="view-switch"
              >
                <button
                  type="button"
                  :class="{ active: calendarView === 'month' }"
                  @click="setCalendarView('month')"
                >
                  Miesiąc
                </button>
                <button
                  type="button"
                  :class="{ active: calendarView === 'week' }"
                  @click="setCalendarView('week')"
                >
                  Tydzień
                </button>
                <button
                  type="button"
                  :class="{ active: calendarView === 'day' }"
                  @click="setCalendarView('day')"
                >
                  Dzień
                </button>
              </div>
            </div>

            <div
              v-if="calendarLoading"
              class="dashboard-empty"
            >
              Odświeżanie kalendarza…
            </div>

            <div
              v-else-if="calendarEvents.length"
              class="dashboard-agenda"
            >
              <article
                v-for="item in calendarEvents.slice(0, 12)"
                :key="item.id"
                class="dashboard-event"
              >
                <time>
                  {{ eventDate(item) }}
                </time>
                <div>
                  <strong>
                    {{ eventTitle(item) }}
                  </strong>
                  <span>
                    {{ item.event_type }}
                  </span>
                </div>
              </article>
            </div>

            <div
              v-else
              class="dashboard-empty"
            >
              Brak zaplanowanych wydarzeń w tym okresie.
            </div>
          </article>
        </section>
      </template>
    </main>
  </div>
</template>
    </main>
  </div>
</template>

<style scoped>
.dashboard-workspace {
  width: min(1540px, 100%);
}

.dashboard-header {
  align-items: flex-end;
}

.dashboard-header p {
  margin: 8px 0 0;
  color: var(--muted);
}

.dashboard-top-grid,
.dashboard-bottom-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
}

.dashboard-bottom-grid {
  margin-top: 18px;
  align-items: start;
}

.dashboard-card {
  min-width: 0;
  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--surface);
  box-shadow: 0 8px 26px rgb(23 23 23 / 4%);
}

.metric-card {
  padding: 24px;
}

.dashboard-card-head,
.dashboard-calendar-toolbar,
.dashboard-actions,
.metric-pair,
.exam-metric {
  display: flex;
  align-items: center;
}

.dashboard-card-head {
  justify-content: space-between;
  gap: 18px;
}

.dashboard-card-head h2 {
  margin: 5px 0 0;
  font-size: 23px;
  letter-spacing: -0.03em;
}

.metric-pair {
  margin: 30px 0;
  gap: 14px;
}

.metric-pair > div,
.exam-metric {
  flex: 1;
  min-height: 118px;
  padding: 20px;
  border: 1px solid #eceae5;
  border-radius: 14px;
  background: #fbfaf7;
  display: grid;
  align-content: center;
  gap: 5px;
}

.metric-pair strong,
.exam-metric strong {
  font-size: clamp(32px, 4vw, 48px);
  line-height: 1;
  letter-spacing: -0.05em;
}

.metric-pair span,
.exam-metric span {
  color: var(--muted);
  font-size: 13px;
}

.exam-metric {
  margin: 30px 0;
  max-width: 300px;
}

.dashboard-actions {
  gap: 9px;
  flex-wrap: wrap;
}

.dashboard-actions.compact {
  justify-content: flex-end;
}

.activity-card,
.calendar-card {
  min-height: 470px;
  padding: 24px;
}

.activity-feed {
  max-height: 386px;
  margin-top: 20px;
  padding-right: 5px;
  overflow-y: auto;
}

.activity-item {
  position: relative;
  min-height: 72px;
  padding: 13px 0 13px 25px;
  border-bottom: 1px solid #efeeeb;
}

.activity-item:last-child {
  border-bottom: 0;
}

.activity-item strong {
  display: block;
  font-size: 14px;
  line-height: 1.45;
}

.activity-item p {
  margin: 5px 0 0;
  color: var(--muted);
  font-size: 12px;
}

.activity-dot {
  position: absolute;
  top: 20px;
  left: 2px;
  width: 9px;
  height: 9px;
  border-radius: 999px;
  background: var(--accent);
  box-shadow: 0 0 0 4px rgb(239 197 79 / 18%);
}

.dashboard-calendar-toolbar {
  margin: 20px 0 14px;
  padding: 12px 0;
  border-top: 1px solid #efeeeb;
  border-bottom: 1px solid #efeeeb;
  justify-content: space-between;
  gap: 12px;
}

.period-nav,
.view-switch {
  display: inline-flex;
  gap: 5px;
}

.period-nav button,
.view-switch button {
  min-height: 33px;
  padding: 6px 10px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fff;
  color: #4b4944;
  cursor: pointer;
  font-size: 12px;
}

.view-switch button.active {
  border-color: #232323;
  background: #232323;
  color: #fff;
}

.dashboard-agenda {
  display: grid;
  gap: 8px;
}

.dashboard-event {
  padding: 11px 12px;
  border: 1px solid #eceae5;
  border-radius: 10px;
  background: #fbfbfa;
  display: grid;
  grid-template-columns: 145px minmax(0, 1fr);
  gap: 12px;
  align-items: center;
}

.dashboard-event time {
  color: var(--muted);
  font-size: 12px;
}

.dashboard-event div {
  min-width: 0;
  display: grid;
  gap: 3px;
}

.dashboard-event strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
}

.dashboard-event span {
  color: var(--muted);
  font-size: 11px;
}

.dashboard-empty {
  margin-top: 18px;
  padding: 28px 18px;
  border: 1px dashed var(--line-strong);
  border-radius: 12px;
  background: #fbfaf7;
  color: var(--muted);
  text-align: center;
  font-size: 13px;
}

@media (max-width: 1100px) {
  .dashboard-top-grid,
  .dashboard-bottom-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .dashboard-card-head,
  .dashboard-calendar-toolbar {
    align-items: stretch;
    flex-direction: column;
  }

  .metric-pair {
    align-items: stretch;
    flex-direction: column;
  }

  .dashboard-actions.compact {
    justify-content: flex-start;
  }

  .dashboard-event {
    grid-template-columns: 1fr;
    gap: 4px;
  }

  .view-switch,
  .period-nav {
    flex-wrap: wrap;
  }
}
</style>
