<script setup lang="ts">
type CourseForm = {
  id: string | null
  version: number | null
  training_type: 'basic' | 'supplementary'
  driving_category_code: string
  pkk_number: string
  pkk_masked: string
  started_at: string
  cost: string
  theory_hours_current: string
  theory_hours_previous: string
  practice_hours_current: string
  practice_hours_previous: string
  lead_instructor_id: string
  location_id: string
}

type Category = { id: string; code: string; label: string; active: boolean }
type Instructor = { id: string; first_name: string; last_name: string }
type Location = { id: string; name: string }

const props = defineProps<{
  modelValue: CourseForm
  categories: Category[]
  instructors: Instructor[]
  locations: Location[]
  editing: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: CourseForm]
}>()

function set<K extends keyof CourseForm>(key: K, value: CourseForm[K]): void {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

function inputValue(event: Event): string {
  return (event.target as HTMLInputElement).value
}

function selectValue(event: Event): string {
  return (event.target as HTMLSelectElement).value
}
</script>

<template>
  <label>Rodzaj *
    <select
      :value="modelValue.training_type"
      required
      @change="set('training_type', selectValue($event) as CourseForm['training_type'])"
    >
      <option value="basic">
        Szkolenie podstawowe
      </option>
      <option value="supplementary">
        Szkolenie uzupełniające
      </option>
    </select>
  </label>

  <label>Kategoria *
    <select
      :value="modelValue.driving_category_code"
      required
      @change="set('driving_category_code', selectValue($event))"
    >
      <option
        v-for="item in categories"
        :key="item.id"
        :value="item.code"
      >
        {{ item.code }}
      </option>
    </select>
  </label>

  <label class="full">PKK *
    <input
      :value="modelValue.pkk_number"
      :required="!editing"
      maxlength="128"
      :placeholder="editing && modelValue.pkk_masked ? 'Obecny: ' + modelValue.pkk_masked + ' · wpisz tylko przy zmianie' : 'Numer PKK'"
      @input="set('pkk_number', inputValue($event))"
    >
    <small v-if="editing">
      Pełny numer nie jest odczytywany z bazy. Puste pole pozostawia bieżący PKK bez zmian.
    </small>
  </label>

  <label>Data i godzina rozpoczęcia *
    <input
      :value="modelValue.started_at"
      type="datetime-local"
      required
      @input="set('started_at', inputValue($event))"
    >
  </label>

  <label>Koszt
    <div class="money-input">
      <input
        :value="modelValue.cost"
        inputmode="decimal"
        :disabled="editing"
        :placeholder="editing ? 'Koszt początkowy kursu' : 'np. 3500,00'"
        @input="set('cost', inputValue($event))"
      >
      <span>zł</span>
    </div>
    <small v-if="!editing">Wpisanie kwoty utworzy jedną należność w finansach kursanta przy zapisie kursu.</small>
    <small v-else>Koszt początkowy jest tylko projekcją należności i nie jest edytowany z poziomu kursu.</small>
  </label>

  <label>Godzin teorii
    <input
      :value="modelValue.theory_hours_current"
      type="number"
      min="0"
      step="0.25"
      @input="set('theory_hours_current', inputValue($event))"
    >
    <small>1 godzina teorii = 45 min. To plan/deklaracja, nie zaliczony czas.</small>
  </label>

  <label v-if="!editing">Teoria odbyta w innej szkole
    <input
      :value="modelValue.theory_hours_previous"
      type="number"
      min="0"
      step="0.25"
      @input="set('theory_hours_previous', inputValue($event))"
    >
  </label>

  <label>Godzin praktyki *
    <input
      :value="modelValue.practice_hours_current"
      type="number"
      min="0"
      step="0.25"
      required
      @input="set('practice_hours_current', inputValue($event))"
    >
    <small>1 godzina praktyki = 60 min. To plan/deklaracja, nie zaliczony czas.</small>
  </label>

  <label v-if="!editing">Praktyka odbyta w innej szkole
    <input
      :value="modelValue.practice_hours_previous"
      type="number"
      min="0"
      step="0.25"
      @input="set('practice_hours_previous', inputValue($event))"
    >
  </label>

  <label>Instruktor *
    <select
      :value="modelValue.lead_instructor_id"
      required
      @change="set('lead_instructor_id', selectValue($event))"
    >
      <option value="">
        Wybierz instruktora
      </option>
      <option
        v-for="item in instructors"
        :key="item.id"
        :value="item.id"
      >
        {{ item.first_name }} {{ item.last_name }}
      </option>
    </select>
  </label>

  <label>Lokalizacja
    <select
      :value="modelValue.location_id"
      @change="set('location_id', selectValue($event))"
    >
      <option value="">
        Brak
      </option>
      <option
        v-for="item in locations"
        :key="item.id"
        :value="item.id"
      >
        {{ item.name }}
      </option>
    </select>
  </label>
</template>
