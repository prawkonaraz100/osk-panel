<?php

namespace Tests\Unit;

use App\Modules\LearningAccess\LearningAccessBulkCredentialsPdfRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LearningAccessBulkCredentialsPdfRendererTest extends TestCase
{
    public function test_renderer_preserves_page_locale_in_one_combined_pdf(): void
    {
        $renderer = new LearningAccessBulkCredentialsPdfRenderer();

        $bytes = $renderer->render([
            [
                'student_name' => 'Jan Kowalski',
                'login_identifier' => 'jan@example.test',
                'language_code' => 'pl',
                'password_is_set' => true,
                'login_url' => 'https://prawkonaraz.pl',
                'plaintext_password' => null,
            ],
            [
                'student_name' => 'John Smith',
                'login_identifier' => 'john@example.test',
                'language_code' => 'en',
                'password_is_set' => true,
                'login_url' => 'https://prawkonaraz.pl',
                'plaintext_password' => null,
            ],
        ], false);

        self::assertStringStartsWith('%PDF-1.4', $bytes);
        self::assertStringContainsString('% PNR-STUDENT-ACCESS-BULK', $bytes);
        self::assertStringContainsString('/Count 2', $bytes);
        self::assertStringContainsString(bin2hex('DANE DO LOGOWANIA'), $bytes);
        self::assertStringContainsString(bin2hex('LOGIN DETAILS'), $bytes);
    }

    public function test_secret_page_requires_fresh_plaintext_before_pdf_can_render(): void
    {
        $renderer = new LearningAccessBulkCredentialsPdfRenderer();

        $this->expectException(InvalidArgumentException::class);
        $renderer->render([[
            'student_name' => 'Jan Kowalski',
            'login_identifier' => 'jan@example.test',
            'language_code' => 'pl',
            'password_is_set' => true,
            'login_url' => 'https://prawkonaraz.pl',
            'plaintext_password' => null,
        ]], true);
    }
}
