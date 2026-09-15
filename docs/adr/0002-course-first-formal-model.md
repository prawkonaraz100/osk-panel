# ADR-0002 — CourseEnrollment jako właściciel formalnego procesu

Status: Accepted  
Data: 2026-09-05

## Context

Jeden kursant może posiadać wiele kursów/kategorii/PKK w czasie. Student-centric model PKK/egzaminu prowadzi do niejednoznaczności.

## Decision

Canonical relacja formalna:

`Organization -> Student -> CourseEnrollment -> Training / PKK / InternalExam`

PKK i formalne próby egzaminacyjne odnoszą się do konkretnego `CourseEnrollment`.

## Consequences

- API PKK jest course-first,
- formalny egzamin wymaga `student_id` i `course_enrollment_id`,
- historia kilku kursów jednej osoby pozostaje rozdzielona,
- rule engine działa per enrollment.

## Rejected alternatives

- jedno globalne PKK na Student,
- egzamin formalny dla tymczasowego ad-hoc kandydata bez trwałego enrollmentu.
