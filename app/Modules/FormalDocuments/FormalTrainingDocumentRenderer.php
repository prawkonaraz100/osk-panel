<?php

namespace App\Modules\FormalDocuments;

use InvalidArgumentException;

/**
 * Deterministic PDF renderer for approved formal training evidence.
 *
 * The renderer intentionally consumes only an already-canonical evidence payload.
 * Approval actor/time are not rendered so equal evidence + template + renderer
 * produces equal canonical bytes.
 */
final class FormalTrainingDocumentRenderer
{
    public const RENDERER_VERSION = 'formal-training-v1';

    /** @var array<string,int> */
    private const POLISH_BYTES = [
        'Ą' => 128, 'Ć' => 129, 'Ę' => 130, 'Ł' => 131, 'Ń' => 132,
        'Ó' => 133, 'Ś' => 134, 'Ź' => 135, 'Ż' => 136,
        'ą' => 137, 'ć' => 138, 'ę' => 139, 'ł' => 140, 'ń' => 141,
        'ó' => 142, 'ś' => 143, 'ź' => 144, 'ż' => 145,
    ];

    /** @param array<string,mixed> $payload */
    public function render(array $payload): string
    {
        foreach (['document_type', 'template_version', 'renderer_version', 'template_content_hash', 'evidence_bundle_hash'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key]) || $payload[$key] === '') {
                throw new InvalidArgumentException("Formal document render payload is missing {$key}.");
            }
        }
        if ($payload['renderer_version'] !== self::RENDERER_VERSION) {
            throw new InvalidArgumentException('Unsupported formal training document renderer version.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $payload['template_content_hash']) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $payload['evidence_bundle_hash']) !== 1) {
            throw new InvalidArgumentException('Formal document hashes must be lowercase SHA-256 values.');
        }
        if (! in_array($payload['document_type'], ['training_record_card', 'theory_delivery_journal'], true)) {
            throw new InvalidArgumentException('Unsupported formal training document type.');
        }

        $lines = $payload['document_type'] === 'training_record_card'
            ? $this->trainingRecordLines($payload)
            : $this->theoryJournalLines($payload);

        return $this->buildPdf(
            $this->pages($lines),
            $payload['template_content_hash'],
            $payload['evidence_bundle_hash'],
            $payload['template_version'],
            $payload['document_type'],
        );
    }

    /** @param array<string,mixed> $payload
     * @return list<array{text:string,bold:bool}>
     */
    private function trainingRecordLines(array $payload): array
    {
        $student = $this->map($payload, 'student');
        $course = $this->map($payload, 'course');
        $requirements = $this->map($payload, 'requirements');
        $instructor = $this->map($payload, 'lead_instructor');
        $totals = $this->map($payload, 'totals');

        $lines = [
            $this->line('KARTA EWIDENCJI SZKOLENIA', true),
            $this->line('PrawkoNaRaz — dokument wygenerowany z zatwierdzonych danych źródłowych'),
            $this->line(''),
            $this->line('KURSANT', true),
            $this->line('Imię i nazwisko: '.$this->value($student, 'first_name').' '.$this->value($student, 'last_name')),
            $this->line('Data urodzenia: '.$this->value($student, 'birth_date', '—')),
            $this->line(''),
            $this->line('KURS', true),
            $this->line('Kategoria: '.$this->value($course, 'driving_category_code')),
            $this->line('Rodzaj szkolenia: '.$this->value($course, 'training_type')),
            $this->line('Data rozpoczęcia: '.$this->value($course, 'started_at')),
            $this->line('Tryb dokumentacji: '.strtoupper($this->value($course, 'document_mode'))),
            $this->line('Instruktor prowadzący: '.$this->value($instructor, 'first_name').' '.$this->value($instructor, 'last_name')),
            $this->line('Nr uprawnienia: '.$this->value($instructor, 'authorization_number', '—')),
            $this->line(''),
            $this->line('WYMAGANY WYMIAR', true),
            $this->line('Teoria: '.$this->minutes($requirements['minimum_theory_minutes'] ?? 0)),
            $this->line('Praktyka: '.$this->minutes($requirements['minimum_practical_minutes'] ?? 0)),
            $this->line(''),
            $this->line('CZAS PRZYJĘTY DO EWIDENCJI', true),
            $this->line('OSK — teoria: '.$this->minutes($totals['osk_theory_minutes'] ?? 0)),
            $this->line('OSK — praktyka: '.$this->minutes($totals['osk_practical_minutes'] ?? 0)),
            $this->line('Inne OSK — teoria: '.$this->minutes($totals['external_theory_minutes'] ?? 0)),
            $this->line('Inne OSK — praktyka: '.$this->minutes($totals['external_practical_minutes'] ?? 0)),
            $this->line('Razem teoria: '.$this->minutes($totals['combined_theory_minutes'] ?? 0), true),
            $this->line('Razem praktyka: '.$this->minutes($totals['combined_practical_minutes'] ?? 0), true),
            $this->line(''),
            $this->line('WPISY EWIDENCJI', true),
        ];

        foreach ($this->list($payload, 'ledger') as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $lines[] = $this->line(
                $this->value($entry, 'date').' | '
                .strtoupper($this->value($entry, 'training_part')).' | '
                .$this->minutes($entry['minutes'] ?? 0).' | '
                .$this->value($entry, 'instructor_name', '—')
            );
        }

        $external = $this->list($payload, 'external_training');
        if ($external !== []) {
            $lines[] = $this->line('');
            $lines[] = $this->line('UZNANE SZKOLENIE ZEWNĘTRZNE', true);
            foreach ($external as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $lines[] = $this->line(
                    strtoupper($this->value($entry, 'training_part')).' | '
                    .$this->minutes($entry['recognized_minutes'] ?? 0).' | '
                    .$this->value($entry, 'source_school_reference', '—')
                );
            }
        }

        return $lines;
    }

    /** @param array<string,mixed> $payload
     * @return list<array{text:string,bold:bool}>
     */
    private function theoryJournalLines(array $payload): array
    {
        $student = $this->map($payload, 'student');
        $course = $this->map($payload, 'course');
        $totals = $this->map($payload, 'totals');

        $lines = [
            $this->line('DZIENNIK REALIZACJI TEORII', true),
            $this->line('PrawkoNaRaz — chronologiczny zapis canonical theory evidence'),
            $this->line(''),
            $this->line('Kursant: '.$this->value($student, 'first_name').' '.$this->value($student, 'last_name')),
            $this->line('Kategoria: '.$this->value($course, 'driving_category_code')),
            $this->line('Data rozpoczęcia kursu: '.$this->value($course, 'started_at')),
            $this->line(''),
            $this->line('WPISY TEORII', true),
        ];

        foreach ($this->list($payload, 'ledger') as $entry) {
            if (! is_array($entry) || ($entry['training_part'] ?? null) !== 'theory') {
                continue;
            }
            $lines[] = $this->line(
                $this->value($entry, 'date').' | '
                .$this->minutes($entry['minutes'] ?? 0).' | '
                .$this->value($entry, 'instructor_name', '—')
            );
        }

        $lines[] = $this->line('');
        $lines[] = $this->line('Teoria OSK: '.$this->minutes($totals['osk_theory_minutes'] ?? 0), true);
        $lines[] = $this->line('Teoria uznana z innych OSK: '.$this->minutes($totals['external_theory_minutes'] ?? 0));
        $lines[] = $this->line('Teoria razem: '.$this->minutes($totals['combined_theory_minutes'] ?? 0), true);
        $lines[] = $this->line('');
        $lines[] = $this->line('Nazw działów nie generuje się bez canonical module-level source.');

        return $lines;
    }

    /** @param list<array{text:string,bold:bool}> $lines
     * @return list<string>
     */
    private function pages(array $lines): array
    {
        $pages = [];
        foreach (array_chunk($lines, 52) as $chunk) {
            $commands = [];
            $y = 800.0;
            foreach ($chunk as $row) {
                foreach ($this->wrap($row['text'], 92) as $text) {
                    $this->text($commands, 42, $y, $row['bold'] ? 10.5 : 9.0, $text, $row['bold']);
                    $y -= $row['bold'] ? 16.0 : 13.0;
                }
            }
            $pages[] = implode("\n", $commands)."\n";
        }

        return $pages === [] ? ["\n"] : $pages;
    }

    /** @return array{text:string,bold:bool} */
    private function line(string $text, bool $bold = false): array
    {
        return ['text' => $text, 'bold' => $bold];
    }

    /** @return list<string> */
    private function wrap(string $value, int $limit): array
    {
        if ($value === '') {
            return [''];
        }
        $words = preg_split('/\s+/u', $value) ?: [$value];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (mb_strlen($candidate) <= $limit) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /** @param list<string> $commands */
    private function text(array &$commands, float $x, float $y, float $size, string $value, bool $bold = false): void
    {
        $commands[] = sprintf(
            'BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm <%s> Tj ET',
            $bold ? 'F2' : 'F1',
            $size,
            $x,
            $y,
            bin2hex($this->encodeText($value)),
        );
    }

    private function encodeText(string $value): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return '?';
        }

        $bytes = '';
        foreach ($chars as $char) {
            if (isset(self::POLISH_BYTES[$char])) {
                $bytes .= chr(self::POLISH_BYTES[$char]);
            } elseif (strlen($char) === 1 && ($ord = ord($char)) >= 32 && $ord <= 126) {
                $bytes .= $char;
            } else {
                $bytes .= match ($char) {
                    '–', '—' => '-',
                    '„', '”', '“' => '"',
                    '’', '‘' => "'",
                    default => '?',
                };
            }
        }

        return $bytes;
    }

    /** @param list<string> $streams */
    private function buildPdf(array $streams, string $templateHash, string $evidenceHash, string $templateVersion, string $documentType): string
    {
        $encoding = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ['
            .'128 /Aogonek /Cacute /Eogonek /Lslash /Nacute /Oacute /Sacute /Zacute /Zdotaccent '
            .'/aogonek /cacute /eogonek /lslash /nacute /oacute /sacute /zacute /zdotaccent] >>';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => $encoding,
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding 3 0 R >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding 3 0 R >>',
        ];

        $kids = [];
        foreach ($streams as $index => $stream) {
            $pageId = 6 + ($index * 2);
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Count '.count($streams).' /Kids ['.implode(' ', $kids).'] >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"
            .'% PNR-FORMAL-DOCUMENT '.preg_replace('/[^A-Za-z0-9_.-]/', '_', $documentType)."\n"
            .'% PNR-TEMPLATE '.preg_replace('/[^A-Za-z0-9_.-]/', '_', $templateVersion)." {$templateHash}\n"
            ."% PNR-EVIDENCE {$evidenceHash}\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxId + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".($maxId + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function map(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $payload
     * @return list<mixed>
     */
    private function list(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @param array<string,mixed> $row */
    private function value(array $row, string $key, string $fallback = ''): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $fallback;
    }

    private function minutes(mixed $value): string
    {
        return max(0, (int) $value).' min';
    }
}
