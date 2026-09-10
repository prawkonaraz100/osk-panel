<script setup lang="ts">
defineProps<{
  resourceQuery: string
}>()
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
    <div class="calendar-toolbar">
      <div class="period-actions">
        <button type="button">
          ‹
        </button>
        <button type="button">
          Dziś
        </button>
        <button type="button">
          ›
        </button>
      </div>
      <div class="view-switch">
        <button
          type="button"
          class="active"
        >
          Miesiąc
        </button>
        <button type="button">
          Tydzień
        </button>
        <button type="button">
          Dzień
        </button>
      </div>
    </div>
    <div class="calendar-placeholder">
      <strong>Punkt wejścia jest gotowy.</strong>
      <span>
        Dane zdarzeń, dostępności i konfliktów pojawią się wraz z modułem Calendar — bez tworzenia fikcyjnych
        wpisów w tym slice.
      </span>
    </div>
  </section>
</template>
