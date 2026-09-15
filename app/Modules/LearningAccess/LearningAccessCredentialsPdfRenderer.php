<?php

namespace App\Modules\LearningAccess;

use InvalidArgumentException;

/**
 * @phpstan-type CredentialsPdfPayload array{student_name:string,login_identifier:string,language_code:string,password_is_set:bool,login_url:string}
 */
final class LearningAccessCredentialsPdfRenderer
{
    public const RENDERER_VERSION = 'student-access-nonsecret-v1';

    /** @var array<string,int> */
    private const POLISH_BYTES = [
        'Ą' => 128, 'Ć' => 129, 'Ę' => 130, 'Ł' => 131, 'Ń' => 132,
        'Ó' => 133, 'Ś' => 134, 'Ź' => 135, 'Ż' => 136,
        'ą' => 137, 'ć' => 138, 'ę' => 139, 'ł' => 140, 'ń' => 141,
        'ó' => 142, 'ś' => 143, 'ź' => 144, 'ż' => 145,
    ];

    /** @param  CredentialsPdfPayload  $payload */
    public function render(array $payload): string
    {
        if ($payload['language_code'] !== 'pl') {
            throw new InvalidArgumentException('No materialized credentials PDF renderer exists for this learning-access language.');
        }
        if (trim($payload['login_identifier']) === '' || trim($payload['login_url']) === '') {
            throw new InvalidArgumentException('Credentials PDF requires a login identifier and application URL.');
        }

        $commands = [];
        $this->text($commands, 42, 798, 18, 'DANE DO LOGOWANIA', true);
        $this->text($commands, 42, 768, 10, 'Dokument bezpiecznego ponownego wydruku — bez hasła.');

        $this->text($commands, 42, 724, 10, 'Kursant', true);
        $this->text($commands, 42, 705, 10, $this->truncate($payload['student_name'], 75));

        $this->text($commands, 42, 660, 10, 'Login', true);
        $this->text($commands, 42, 641, 10, $this->truncate($payload['login_identifier'], 88));

        $this->text($commands, 42, 596, 10, 'Hasło', true);
        $passwordState = $payload['password_is_set']
            ? 'Hasło jest ustawione. System nie przechowuje starego hasła w postaci możliwej do odczytu.'
            : 'Hasło nie jest obecnie ustawione dla tego konta.';
        $this->text($commands, 42, 577, 9, $this->truncate($passwordState, 104));

        $this->text($commands, 42, 532, 10, 'Strona', true);
        $this->text($commands, 42, 513, 10, $this->truncate($payload['login_url'], 88));

        $this->text($commands, 42, 456, 9, 'Jeżeli potrzebne jest nowe hasło, uprawniony pracownik OSK musi wykonać osobny reset.');
        $this->text($commands, 42, 438, 9, 'Pobranie tego dokumentu nie zmienia hasła ani wersji poświadczeń.');

        return $this->buildPdf(implode("\n", $commands)."\n");
    }

    /** @param  list<string>  $commands */
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
                '’', '‘' => "'",
                default => '?',
            };
        }

        return $bytes;
    }

    private function buildPdf(string $stream): string
    {
        $encoding = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ['
            .'128 /Aogonek /Cacute /Eogonek /Lslash /Nacute /Oacute /Sacute /Zacute /Zdotaccent '
            .'/aogonek /cacute /eogonek /lslash /nacute /oacute /sacute /zacute /zdotaccent] >>';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [6 0 R] >>',
            3 => $encoding,
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding 3 0 R >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding 3 0 R >>',
            6 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 7 0 R >>',
            7 => '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"
            .'% PNR-STUDENT-ACCESS-NONSECRET '.self::RENDERER_VERSION."\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 8\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= 7; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size 8 /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
