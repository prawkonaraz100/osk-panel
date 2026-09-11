<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, api } from './api'

type CalendarItem = {
  id: string
  source_kind: string
  source_id: string
  event_type: 'general_event' | 'driving_lesson' | 'important_date'
  name: string | null
  starts_at: string
  ends_at: string
  status: string
  all_day?: boolean
}

const props = defineProps<{
  resourceQuery: string
}>()

const PRODUCT_TIME_ZONE = 'Europe/Warsaw'
const loading = ref(false)
const error = ref('')
const items = ref<CalendarItem[]>([])

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const resource = new URLSearchParams(props.resourceQuery)
    const params = new URLSearchParams()
    const now = new Date()
    const end = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000)

    params.set('from', now.toISOString())
    params.set('to', end.toISOString())

    const staffId = resource.get('w')
    const vehicleId = resource.get('v')
    const locationId = resource.get('b')
    if (staffId) params.set('staff_id', staffId)
    if (vehicleId) params.set('vehicle_id', vehicleId)
    if (locationId) params.set('location_id', locationId)

    items.value = (await api<CalendarItem[]>(`/api/v1/calendar/events?${params.toString()}`)).data.slice(0, 6)
  } catch (caught: unknown) {
    error.value = caught instanceof ApiError ? caught.message : 'Nie udało się pobrać harmonogramu.'
  } finally {
    loading.value = false
  }
}

function label(item: CalendarItem): string {
  if (item.name?.trim()) return item.name
  if (item.event_type === 'driving_lesson') return 'Jazda'
  if (item.event_type === 'important_date') return 'Ważna data'
  return 'Wydarzenie'
}

function dateLabel(item: CalendarItem): string {
  const date = new Date(item.starts_at)
  const day = new Intl.DateTimeFormat('pl-PL', {
    day: '2-digit',
    month: '2-digit',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(date)

  if (item.all_day) return day

  const time = new Intl.DateTimeFormat('pl-PL', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(date)

  return `${day} · ${time}`
}
</script>

<template>
  <section class="detail-card calendar-shell">
    <div class="card-heading split">
      <div>
        <span class="section-kicker">Kalendarz</span>
        <h2>Harmonogram zasobu</h2>
      </div>
      <div class="calendar-actions">
        <a
          class="button ghost"
          :href="`/kalendarz?${resourceQuery}`"
        >Pełny kalendarz</a>
        <a
          class="button primary"
          :href="`/kalendarz?${resourceQuery}&action=create`"
        >Dodaj wydarzenie</a>
      </div>
    </div>

    <p
      v-if="error"
      class="calendar-error"
    >
      {{ error }}
    </p>
    <div
      v-else-if="loading"
      class="calendar-state"
    >
      Ładowanie harmonogramu…
    </div>
    <div
      v-else-if="items.length"
      class="resource-schedule"
    >
      <article
        v-for="item in items"
        :key="`${item.source_kind}-${item.source_id}`"
        class="resource-event"
        :class="item.event_type"
      >
        <time>{{ dateLabel(item) }}</time>
        <strong>{{ label(item) }}</strong>
      </article>
    </div>
    <div
      v-else
      class="calendar-state"
    >
      Brak wpisów dla tego zasobu w ciągu najbliższych 30 dni.
    </div>
  </section>
</template>

<style scoped>
.calendar-actions,
.resource-event {
  display: flex;
  gap: 10px;
}

.resource-schedule {
  display: grid;
  gap: 8px;
  margin-top: 14px;
}

.resource-event {
  padding: 10px 12px;
  align-items: center;
  border-left: 3px solid #8b8f97;
  border-radius: 8px;
  background: #fafafa;
}

.resource-event.driving_lesson {
  border-left-color: #d3a51f;
}

.resource-event.important_date {
  border-left-color: #b7791f;
}

.resource-event time {
  min-width: 105px;
  color: #6b7280;
  font-size: 12px;
}

.resource-event strong {
  font-size: 13px;
}

.calendar-state,
.calendar-error {
  margin: 14px 0 0;
  padding: 14px;
  border-radius: 9px;
  background: #fafafa;
  color: #6b7280;
  font-size: 13px;
}

.calendar-error {
  background: #fff1f1;
  color: #9b2c2c;
}

@media (max-width: 680px) {
  .calendar-actions {
    align-items: stretch;
    flex-direction: column;
  }

  .resource-event {
    align-items: flex-start;
    flex-direction: column;
    gap: 3px;
  }
}
</style>
