<?php

namespace App\Modules\LearningAccess;

final class LearningAccessPdfRenderer
{
    /** @param array<string,mixed> $document */
    public function renderSingle(array $document): string
    {
        return $this->buildPdf([$this->accessPage($document)]);
    }

    /** @param list<array<string,mixed>> $documents */
    public function renderBulk(array $documents): string
    {
        $indexLines = ['PrawkoNaRaz - dane dostepu', ''];
        foreach ($documents as $index => $document) {
            $locale = mb_strtoupper((string) ($document['language_code'] ?? 'pl'));
            $identifier = (string) ($document['login_identifier'] ?? '');
            $indexLines[] = sprintf('%d. %s - %s - strona %d', $index + 1, $locale, $identifier, $index + 2);
        }

        $pages = [[
            'locale' => 'pl',
            'title' => 'Indeks dostepow',
            'lines' => $indexLines,
        ]];
        foreach ($documents as $document) {
            $pages[] = $this->accessPage($document);
        }

        return $this->buildPdf($pages);
    }

    /**
     * @param array<string,mixed> $document
     * @return array{locale:string,title:string,lines:list<string>}
     */
    private function accessPage(array $document): array
    {
        $locale = $this->supportedLocale((string) ($document['language_code'] ?? 'pl'));
        $copy = $this->copy($locale);
        $identifier = (string) ($document['login_identifier'] ?? '');
        $student = (string) ($document['student_full_name'] ?? '');
        $loginUrl = (string) ($document['login_url'] ?? 'https://prawkonaraz.pl/nauka');
        $status = (string) ($document['access_status'] ?? 'not_active');
        $statusLabel = $status === 'active' ? $copy['active'] : $copy['not_active'];

        return [
            'locale' => $locale,
            'title' => $copy['title'],
            'lines' => [
                $copy['student'].': '.$student,
                $copy['login'].': '.$identifier,
                $copy['password'].': '.$copy['password_state'],
                $copy['website'].': '.$loginUrl,
                $copy['status'].': '.$statusLabel,
                '',
                $copy['instructions'],
                '1. '.$copy['step_open'],
                '2. '.$copy['step_login'],
                '3. '.$copy['step_activate'],
                '',
                $copy['security'],
            ],
        ];
    }

    /**
     * @return array{
     *   title:string,student:string,login:string,password:string,password_state:string,
     *   website:string,status:string,active:string,not_active:string,instructions:string,
     *   step_open:string,step_login:string,step_activate:string,security:string
     * }
     */
    private function copy(string $locale): array
    {
        return match ($locale) {
            'en' => [
                'title' => 'Learning access',
                'student' => 'Student',
                'login' => 'Login',
                'password' => 'Password',
                'password_state' => 'Set by the user or issued earlier. It cannot be recovered by the office.',
                'website' => 'Login page',
                'status' => 'Access status',
                'active' => 'Active',
                'not_active' => 'Not active yet',
                'instructions' => 'Login instructions',
                'step_open' => 'Open the login page.',
                'step_login' => 'Enter your login and your current password.',
                'step_activate' => 'If the course is not active, activate it after signing in.',
                'security' => 'For security, an existing password is never printed or recovered.',
            ],
            'de' => [
                'title' => 'Lernzugang',
                'student' => 'Fahrschüler',
                'login' => 'Login',
                'password' => 'Passwort',
                'password_state' => 'Vom Nutzer festgelegt oder früher ausgegeben. Das Büro kann es nicht wiederherstellen.',
                'website' => 'Anmeldeseite',
                'status' => 'Zugangsstatus',
                'active' => 'Aktiv',
                'not_active' => 'Noch nicht aktiv',
                'instructions' => 'Anmeldung',
                'step_open' => 'Öffne die Anmeldeseite.',
                'step_login' => 'Gib deinen Login und dein aktuelles Passwort ein.',
                'step_activate' => 'Falls der Kurs nicht aktiv ist, aktiviere ihn nach der Anmeldung.',
                'security' => 'Aus Sicherheitsgründen wird ein bestehendes Passwort nie erneut angezeigt.',
            ],
            'ru' => [
                'title' => 'Доступ к обучению',
                'student' => 'Ученик',
                'login' => 'Логин',
                'password' => 'Пароль',
                'password_state' => 'Установлен пользователем или выдан ранее. Офис не может его восстановить.',
                'website' => 'Страница входа',
                'status' => 'Статус доступа',
                'active' => 'Активен',
                'not_active' => 'Еще не активен',
                'instructions' => 'Инструкция по входу',
                'step_open' => 'Откройте страницу входа.',
                'step_login' => 'Введите логин и текущий пароль.',
                'step_activate' => 'Если курс не активен, активируйте его после входа.',
                'security' => 'В целях безопасности существующий пароль никогда не печатается и не восстанавливается.',
            ],
            'uk' => [
                'title' => 'Доступ до навчання',
                'student' => 'Курсант',
                'login' => 'Логін',
                'password' => 'Пароль',
                'password_state' => 'Встановлений користувачем або виданий раніше. Офіс не може його відновити.',
                'website' => 'Сторінка входу',
                'status' => 'Статус доступу',
                'active' => 'Активний',
                'not_active' => 'Ще не активний',
                'instructions' => 'Інструкція входу',
                'step_open' => 'Відкрийте сторінку входу.',
                'step_login' => 'Введіть логін і поточний пароль.',
                'step_activate' => 'Якщо курс не активний, активуйте його після входу.',
                'security' => 'З міркувань безпеки наявний пароль ніколи не друкується і не відновлюється.',
            ],
            default => [
                'title' => 'Dostęp do nauki',
                'student' => 'Kursant',
                'login' => 'Login',
                'password' => 'Hasło',
                'password_state' => 'Ustawione przez użytkownika lub wydane wcześniej. Biuro nie może go odtworzyć.',
                'website' => 'Strona logowania',
                'status' => 'Status dostępu',
                'active' => 'Aktywny',
                'not_active' => 'Jeszcze nieaktywny',
                'instructions' => 'Instrukcja logowania',
                'step_open' => 'Otwórz stronę logowania.',
                'step_login' => 'Wpisz login i swoje aktualne hasło.',
                'step_activate' => 'Jeśli kurs nie jest aktywny, aktywuj go po zalogowaniu.',
                'security' => 'Ze względów bezpieczeństwa istniejące hasło nigdy nie jest drukowane ani odtwarzane.',
            ],
        };
    }

    private function supportedLocale(string $locale): string
    {
        $locale = mb_strtolower(trim($locale));

        return in_array($locale, ['pl', 'en', 'de', 'ru', 'uk'], true) ? $locale : 'pl';
    }

    /**
     * @param list<array{locale:string,title:string,lines:list<string>}> $pages
     */
    private function buildPdf(array $pages): string
    {
        $objects = [];
        $pageObjectIds = [];
        $firstDynamicObject = 5;

        foreach ($pages as $index => $_page) {
            $pageObjectIds[] = $firstDynamicObject + ($index * 2) + 1;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Count '.count($pages).' /Kids ['.
            implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $pageObjectIds)).
            '] >>';
        $objects[3] = $this->fontObject('cp1250');
        $objects[4] = $this->fontObject('cp1251');

        foreach ($pages as $index => $page) {
            $contentId = $firstDynamicObject + ($index * 2);
            $pageId = $contentId + 1;
            $content = $this->pageContent($page);
            $objects[$contentId] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '.
                '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentId.' 0 R >>';
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
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
        $pdf .= "startxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    /** @param array{locale:string,title:string,lines:list<string>} $page */
    private function pageContent(array $page): string
    {
        $font = in_array($page['locale'], ['ru', 'uk'], true) ? 'F2' : 'F1';
        $encoding = in_array($page['locale'], ['ru', 'uk'], true) ? 'Windows-1251' : 'Windows-1250';
        $commands = ['% locale='.$page['locale']];
        $y = 795;

        $title = $this->encodedHex($page['title'], $encoding);
        $commands[] = "BT /{$font} 18 Tf 50 {$y} Td <{$title}> Tj ET";
        $y -= 32;

        foreach ($page['lines'] as $line) {
            foreach ($this->wrap($line, 82) as $wrapped) {
                $hex = $this->encodedHex($wrapped, $encoding);
                $commands[] = "BT /{$font} 10 Tf 50 {$y} Td <{$hex}> Tj ET";
                $y -= 16;
            }
            if ($line === '') {
                $y -= 4;
            }
        }

        return implode("\n", $commands);
    }

    /** @return list<string> */
    private function wrap(string $line, int $limit): array
    {
        if ($line === '') {
            return [''];
        }
        $words = preg_split('/\s+/u', trim($line)) ?: [];
        $result = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($current !== '' && mb_strlen($candidate) > $limit) {
                $result[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $result[] = $current;
        }

        return $result === [] ? [''] : $result;
    }

    private function encodedHex(string $text, string $encoding): string
    {
        $encoded = iconv('UTF-8', $encoding.'//TRANSLIT//IGNORE', $text);
        if ($encoded === false) {
            $encoded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        }

        return strtoupper(bin2hex($encoded === false ? '' : $encoded));
    }

    private function fontObject(string $encoding): string
    {
        if ($encoding === 'cp1251') {
            return '<< /Type /Font /Subtype /Type1 /BaseFont /ArialMT /Encoding '.
                '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ['.
                '165 /uni0490 168 /uni0401 170 /uni0404 175 /uni0407 178 /uni0406 179 /uni0456 180 /uni0491 '.
                '184 /uni0451 186 /uni0454 191 /uni0457 '.
                '192 /uni0410 /uni0411 /uni0412 /uni0413 /uni0414 /uni0415 /uni0416 /uni0417 /uni0418 /uni0419 '.
                '/uni041A /uni041B /uni041C /uni041D /uni041E /uni041F /uni0420 /uni0421 /uni0422 /uni0423 '.
                '/uni0424 /uni0425 /uni0426 /uni0427 /uni0428 /uni0429 /uni042A /uni042B /uni042C /uni042D '.
                '/uni042E /uni042F /uni0430 /uni0431 /uni0432 /uni0433 /uni0434 /uni0435 /uni0436 /uni0437 '.
                '/uni0438 /uni0439 /uni043A /uni043B /uni043C /uni043D /uni043E /uni043F /uni0440 /uni0441 '.
                '/uni0442 /uni0443 /uni0444 /uni0445 /uni0446 /uni0447 /uni0448 /uni0449 /uni044A /uni044B '.
                '/uni044C /uni044D /uni044E /uni044F ] >> >>';
        }

        return '<< /Type /Font /Subtype /Type1 /BaseFont /ArialMT /Encoding '.
            '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ['.
            '140 /Sacute 143 /Zacute 156 /sacute 159 /zacute 163 /Lslash 165 /Aogonek '.
            '175 /Zdotaccent 179 /lslash 185 /aogonek 191 /zdotaccent '.
            '198 /Cacute 202 /Eogonek 209 /Nacute 230 /cacute 234 /eogonek 241 /nacute ] >> >>';
    }
}
