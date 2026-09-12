<?php

namespace App\Modules\InternalExams;

use InvalidArgumentException;

final class InternalExamAnswerSheetRenderer
{
    public const RENDERER_VERSION = 'answer-sheet-v1';

    /** @var array<string,int> */
    private const POLISH_BYTES = [
        'Ą' => 128, 'Ć' => 129, 'Ę' => 130, 'Ł' => 131, 'Ń' => 132,
        'Ó' => 133, 'Ś' => 134, 'Ź' => 135, 'Ż' => 136,
        'ą' => 137, 'ć' => 138, 'ę' => 139, 'ł' => 140, 'ń' => 141,
        'ó' => 142, 'ś' => 143, 'ź' => 144, 'ż' => 145,
    ];

    /**
     * @param  array{
     *   template_version:string,
     *   renderer_version:string,
     *   template_content_hash:string,
     *   evidence_bundle_hash:string,
     *   candidate_snapshot:array<string,mixed>,
     *   exam_date:string,
     *   driving_category_code:string,
     *   exam_part:string,
     *   questions:list<array{ordinal:int,group:string,identifier:?string,max_points:int,answer:mixed,awarded_points:int}>,
     *   score:int,
     *   max_score:int,
     *   passed:bool
     * } $payload
     */
    public function render(array $payload): string
    {
        if ($payload['renderer_version'] !== self::RENDERER_VERSION) {
            throw new InvalidArgumentException('Unsupported internal exam answer-sheet renderer version.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $payload['template_content_hash']) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $payload['evidence_bundle_hash']) !== 1) {
            throw new InvalidArgumentException('Answer-sheet hashes must be lowercase SHA-256 values.');
        }

        $questions = $payload['questions'];
        $chunks = [];
        $chunks[] = array_splice($questions, 0, 32);
        while ($questions !== []) {
            $chunks[] = array_splice($questions, 0, 45);
        }
        if ($chunks === []) {
            $chunks = [[]];
        }

        $streams = [];
        foreach ($chunks as $pageIndex => $rows) {
            $commands = [];
            if ($pageIndex === 0) {
                $this->text($commands, 182, 806, 17, 'ARKUSZ ODPOWIEDZI', true);
                $this->text($commands, 132, 786, 9, 'Arkusz przeprowadzonego egzaminu wewnętrznego');

                $this->text($commands, 42, 754, 10, 'OSOBA EGZAMINOWANA', true);
                $candidate = $payload['candidate_snapshot'];
                $this->text($commands, 42, 736, 9, 'Nazwisko: '.(string) ($candidate['last_name'] ?? '—'));
                $this->text($commands, 310, 736, 9, 'Imię: '.(string) ($candidate['first_name'] ?? '—'));
                $birthDate = $candidate['birth_date'] ?? null;
                $this->text($commands, 42, 719, 9, 'Data urodzenia: '.(is_string($birthDate) && $birthDate !== '' ? $birthDate : '—'));

                $this->text($commands, 42, 692, 10, 'EGZAMIN', true);
                $this->text($commands, 42, 674, 9, 'Data egzaminu: '.$payload['exam_date']);
                $this->text($commands, 310, 674, 9, 'Kategoria: '.$payload['driving_category_code']);

                $tableTop = 646.0;
            } else {
                $this->text($commands, 42, 806, 11, 'ARKUSZ ODPOWIEDZI — ciąg dalszy', true);
                $tableTop = 782.0;
            }

            $this->tableHeader($commands, $tableTop);
            $y = $tableTop - 18.0;
            foreach ($rows as $row) {
                $this->tableRow($commands, $y, $row);
                $y -= 11.5;
            }

            if ($pageIndex === count($chunks) - 1) {
                $footerY = max(74.0, $y - 18.0);
                $this->text($commands, 42, $footerY, 10, 'Suma punktów: '.$payload['score'].' / '.$payload['max_score'], true);
                $this->text($commands, 330, $footerY, 10, 'Próg zaliczenia: '.($payload['passed'] ? 'SPEŁNIONY' : 'NIESPEŁNIONY'), true);
                $this->line($commands, 58, $footerY - 42, 240, $footerY - 42);
                $this->line($commands, 355, $footerY - 42, 537, $footerY - 42);
                $this->text($commands, 82, $footerY - 56, 7.5, 'podpis osoby egzaminowanej');
                $this->text($commands, 370, $footerY - 56, 7.5, 'podpis i pieczątka osoby egzaminującej');
            }

            $streams[] = implode("\n", $commands)."\n";
        }

        return $this->buildPdf(
            $streams,
            $payload['template_content_hash'],
            $payload['evidence_bundle_hash'],
            $payload['template_version'],
        );
    }

    /** @param list<string> $commands */
    private function tableHeader(array &$commands, float $top): void
    {
        $edges = [42.0, 70.0, 120.0, 355.0, 405.0, 480.0, 552.0];
        $bottom = $top - 18.0;
        foreach ($edges as $x) {
            $this->line($commands, $x, $top, $x, $bottom);
        }
        $this->line($commands, 42, $top, 552, $top);
        $this->line($commands, 42, $bottom, 552, $bottom);

        $this->text($commands, 49, $top - 12, 7, 'Lp.', true);
        $this->text($commands, 80, $top - 12, 7, 'Sekcja', true);
        $this->text($commands, 127, $top - 12, 7, 'Identyfikator pytania', true);
        $this->text($commands, 365, $top - 12, 7, 'Max', true);
        $this->text($commands, 415, $top - 12, 7, 'Odpowiedź', true);
        $this->text($commands, 488, $top - 12, 7, 'Pkt.', true);
    }

    /**
     * @param list<string> $commands
     * @param array{ordinal:int,group:string,identifier:?string,max_points:int,answer:mixed,awarded_points:int} $row
     */
    private function tableRow(array &$commands, float $top, array $row): void
    {
        $edges = [42.0, 70.0, 120.0, 355.0, 405.0, 480.0, 552.0];
        $bottom = $top - 11.5;
        foreach ($edges as $x) {
            $this->line($commands, $x, $top, $x, $bottom);
        }
        $this->line($commands, 42, $bottom, 552, $bottom);

        $this->text($commands, 50, $top - 8.4, 6.8, (string) $row['ordinal']);
        $this->text($commands, 78, $top - 8.4, 6.8, $row['group'] === 'basic' ? 'PODST.' : 'SPEC.');
        $this->text($commands, 127, $top - 8.4, 6.8, $this->truncate((string) ($row['identifier'] ?? '—'), 42));
        $this->text($commands, 372, $top - 8.4, 6.8, (string) $row['max_points']);
        $this->text($commands, 414, $top - 8.4, 6.8, $this->truncate($this->answerLabel($row['answer']), 14));
        $this->text($commands, 495, $top - 8.4, 6.8, (string) $row['awarded_points']);
    }

    /** @param list<string> $commands */
    private function text(array &$commands, float $x, float $y, float $size, string $value, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $commands[] = sprintf(
            'BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm <%s> Tj ET',
            $font,
            $size,
            $x,
            $y,
            bin2hex($this->encodeText($value)),
        );
    }

    /** @param list<string> $commands */
    private function line(array &$commands, float $x1, float $y1, float $x2, float $y2): void
    {
        $commands[] = sprintf('0.45 w %.2F %.2F m %.2F %.2F l S', $x1, $y1, $x2, $y2);
    }

    private function answerLabel(mixed $answer): string
    {
        if ($answer === null) {
            return 'BRAK';
        }
        if (is_bool($answer)) {
            return $answer ? 'TAK' : 'NIE';
        }
        if (is_string($answer) || is_int($answer) || is_float($answer)) {
            return (string) $answer;
        }

        return json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function truncate(string $value, int $limit): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || count($chars) <= $limit) {
            return $value;
        }

        return implode('', array_slice($chars, 0, max(1, $limit - 3))).'...';
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

                continue;
            }
            if (strlen($char) === 1) {
                $ord = ord($char);
                if ($ord >= 32 && $ord <= 126) {
                    $bytes .= $char;

                    continue;
                }
            }
            $bytes .= match ($char) {
                '–', '—' => '-',
                '„', '”', '“' => '"',
                '’', '‘' => '\'',
                default => '?',
            };
        }

        return $bytes;
    }

    /** @param list<string> $streams */
    private function buildPdf(array $streams, string $templateHash, string $evidenceHash, string $templateVersion): string
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

        $safeTemplateVersion = preg_replace('/[^A-Za-z0-9_.-]/', '_', $templateVersion) ?? 'unknown';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"
            .'% PNR-TEMPLATE '.$safeTemplateVersion." {$templateHash}\n"
            ."% PNR-EVIDENCE {$evidenceHash}\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxId + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".($maxId + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
