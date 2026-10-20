<?php

namespace Tests\Unit\Reports;

use App\Http\Controllers\Api\V1\ReportController;
use App\Models\Tenant\InventoryScheduledReport;
use App\Services\Access\WarehouseAccessService;
use App\Services\Preferences\AccountPreferenceSync;
use App\Services\Reports\{InventoryReportService, ReportExportService, ReportFilters, ScheduledReportRunner};
use App\Tenancy\OrganizationContext;
use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Facade, Mail};
use Illuminate\Translation\{FileLoader, Translator};
use Illuminate\Validation\{Factory, ValidationException};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/** Exercises real controller validation and delivery orchestration without DB or SMTP. */
final class ScheduledReportLocaleTest extends TestCase
{
    private Container $previousContainer;
    private mixed $previousFacadeApplication;
    private Application $application;
    private LocaleReportFixture $reports;
    private LocaleMailFixture $mail;
    private ScheduledReportRunner $runner;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->application = new Application(dirname(__DIR__, 3));
        Container::setInstance($this->application);
        $this->application->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'timezone' => 'UTC'],
            'inventory_entitlements' => ['feature_enforcement' => false],
        ]));
        $this->application->instance('events', new Dispatcher($this->application));
        $translator = new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'en');
        $translator->setFallback('en');
        $this->application->instance('translator', $translator);
        $this->application->instance('validator', new Factory($translator, $this->application));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->application);
        $this->reports = new LocaleReportFixture;
        $this->mail = new LocaleMailFixture;
        Mail::swap($this->mail);
        $this->runner = new ScheduledReportRunner($this->reports);
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
    }

    private function validateSchedule(array $filters, ?InventoryScheduledReport $saved = null): array
    {
        $request = LocaleScheduleRequest::create('/reports/schedules', 'POST', [
            'name' => 'Customer original schedule', 'report_key' => 'inventory-valuation',
            'filters' => $filters, 'frequency' => 'weekly', 'format' => 'xlsx',
            'recipients' => ['Original.Recipient@example.com'],
        ]);
        $controller = new ReportController($this->reports, new ReportExportService, $this->runner,
            new WarehouseAccessService(new OrganizationContext));

        return (new ReflectionMethod($controller, 'validatedSchedule'))->invoke($controller, $request, $saved);
    }

    public function test_creation_captures_all_supported_languages_and_preserves_filter_data(): void
    {
        foreach (AccountPreferenceSync::LOCALES as $locale) {
            app()->setLocale($locale);
            $data = $this->validateSchedule(['warehouse_id' => 7, 'days' => 30, 'locale' => 'fr']);
            $this->assertSame($locale, $data['filters']['locale']);
            $this->assertSame(7, $data['filters']['warehouse_id']);
            $this->assertSame(30, $data['filters']['days']);
            $this->assertSame(['Original.Recipient@example.com'], $data['recipients']);
            $this->assertSame('Customer original schedule', $data['name']);
            $this->assertSame('xlsx', $data['format']);
            $this->assertTrue($data['is_active']);
        }
    }

    public function test_invalid_incoming_locale_metadata_is_rejected_by_server_validation(): void
    {
        $this->expectException(ValidationException::class);
        $this->validateSchedule(['locale' => '../../ar']);
    }

    public function test_edit_keeps_saved_language_and_old_schedules_get_explicit_english_fallback(): void
    {
        app()->setLocale('tr');
        foreach (['zh', null, 'unsupported'] as $locale) {
            $saved = new LocaleScheduleFixture;
            $saved->filters = $locale === null ? ['days' => 30] : ['days' => 30, 'locale' => $locale];
            $data = $this->validateSchedule(['warehouse_id' => 8], $saved);
            $this->assertSame($locale === 'zh' ? 'zh' : 'en', $data['filters']['locale']);
            $this->assertSame(8, $data['filters']['warehouse_id']);
        }
    }

    public function test_background_mail_uses_saved_language_in_all_eleven_locales_and_restores_worker(): void
    {
        app()->setLocale('it');
        foreach (AccountPreferenceSync::LOCALES as $locale) {
            $this->mail->messages = [];
            $schedule = $this->schedule($locale);
            $result = $this->runner->run($schedule)['result'];
            $title = __('inventory.reports.titles.inventory-valuation', [], $locale);
            $copy = require dirname(__DIR__, 3).'/lang/'.$locale.'/scheduled_reports.php';
            $message = $this->mail->messages[0];
            $this->assertSame($locale, $this->reports->locale);
            $this->assertSame($locale, $message->locale);
            $this->assertSame(str_replace(':report', $title, $copy['subject']), $message->subject);
            $this->assertSame($title."\n".$copy['rows'].": 2\n".$copy['summary'].': '.json_encode(LocaleReportFixture::SUMMARY), $message->body);
            $this->assertSame('Original.Recipient@example.com', $message->recipient);
            $this->assertSame(LocaleReportFixture::ROWS, $result['rows']);
            $this->assertSame(LocaleReportFixture::SUMMARY, $result['summary']);
            $this->assertSame(LocaleReportFixture::SUMMARY, $schedule->last_payload['summary']);
            $this->assertSame(2, $schedule->last_payload['row_count']);
            $this->assertSame('delivered', $schedule->last_status);
            $this->assertSame('2026-10-20 15:00:00', $schedule->next_run_at->format('Y-m-d H:i:s'));
            $this->assertSame('it', app()->getLocale());
        }
    }

    public function test_existing_missing_or_invalid_saved_language_never_inherits_previous_job_language(): void
    {
        app()->setLocale('ar');
        foreach ([null, 'not-a-locale', ['fr']] as $locale) {
            $this->mail->messages = [];
            $this->runner->run($this->schedule($locale));
            $this->assertSame('en', $this->reports->locale);
            $this->assertSame('en', $this->mail->messages[0]->locale);
            $this->assertStringStartsWith('SolaStock scheduled report: ', $this->mail->messages[0]->subject);
            $this->assertSame('ar', app()->getLocale());
        }
    }

    public function test_locale_restores_after_report_exception_and_delivery_failure(): void
    {
        app()->setLocale('de');
        $this->reports->throw = true;
        try {
            $this->runner->run($this->schedule('zh'));
            $this->fail('Expected report failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Fixture report failure', $error->getMessage());
            $this->assertSame('de', app()->getLocale());
        }
        $this->reports->throw = false;
        $this->mail->throw = true;
        $schedule = $this->schedule('fr');
        $this->runner->run($schedule);
        $this->assertSame('failed', $schedule->last_status);
        $this->assertSame('Fixture mail failure', $schedule->last_error);
        $this->assertNull($schedule->last_delivered_at);
        $this->assertSame('de', app()->getLocale());
    }

    private function schedule(mixed $locale): LocaleScheduleFixture
    {
        $schedule = new LocaleScheduleFixture;
        $schedule->forceFill([
            'report_key' => 'inventory-valuation', 'frequency' => 'weekly',
            'recipients' => ['Original.Recipient@example.com'],
            'filters' => ['days' => 30, 'warehouse_id' => 7] + ($locale === null ? [] : ['locale' => $locale]),
        ]);

        return $schedule;
    }
}

final class LocaleScheduleRequest extends Request
{
    public function validate(array $rules): array
    {
        return app('validator')->make($this->all(), $rules)->validate();
    }
}

final class LocaleScheduleFixture extends InventoryScheduledReport
{
    protected $dateFormat = 'Y-m-d H:i:s';
    public function save(array $options = []) { return true; }
    public function fresh($with = []) { return $this; }
}

final class LocaleReportFixture extends InventoryReportService
{
    public const ROWS = [['sku' => 'CUSTOMER-SKU', 'amount' => '123.4500'], ['sku' => 'SECOND-SKU', 'amount' => '123.4500']];
    public const SUMMARY = ['value' => '246.9000', 'items' => 2];
    public bool $throw = false;
    public string $locale = '';
    public function run(string $key, ReportFilters $filters): array
    {
        $this->locale = app()->getLocale();
        if ($this->throw) { throw new RuntimeException('Fixture report failure'); }

        return ['title' => self::title($key), 'rows' => self::ROWS, 'summary' => self::SUMMARY];
    }
}

final class LocaleMailFixture
{
    public array $messages = [];
    public bool $throw = false;
    public function raw($body, $callback): void
    {
        if ($this->throw) { throw new RuntimeException('Fixture mail failure'); }
        $message = new class($body) {
            public string $recipient = '';
            public string $subject = '';
            public string $locale;
            public function __construct(public string $body) { $this->locale = app()->getLocale(); }
            public function to($recipient): self { $this->recipient = $recipient; return $this; }
            public function subject($subject): self { $this->subject = $subject; return $this; }
        };
        $callback($message);
        $this->messages[] = $message;
    }
}
