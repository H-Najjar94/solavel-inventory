<?php

namespace Tests\Feature\Ui;

use App\Services\Integration\IntegrationEvents;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentationReviewLabelsTest extends TestCase
{
    public function test_internal_consumption_ledger_source_label_is_translated(): void
    {
        foreach (['en' => 'Internal consumption', 'ar' => 'الصرف الداخلي'] as $locale => $expected) {
            app()->setLocale($locale);
            $this->assertSame($expected, __('inventory.consumption.title'));
        }
        app()->setLocale('en');
    }

    public function test_every_recorded_integration_event_type_has_a_filter_option_and_bilingual_label(): void
    {
        $page = File::get(resource_path('js/solastock/pages/IntegrationEventsPage.jsx'));
        $this->assertSame(1, preg_match('/const EVENT_TYPES = \[(.*?)\];/s', $page, $match));
        preg_match_all("/'([^']+)'/", $match[1], $options);

        $labels = File::get(resource_path('js/solastock/i18n/settingsPages.js'));
        $arStart = strpos($labels, 'export const ar = {');
        $this->assertNotFalse($arStart);
        [$en, $ar] = [substr($labels, 0, $arStart), substr($labels, $arStart)];

        foreach (array_keys(IntegrationEvents::TYPES) as $type) {
            $this->assertContains($type, $options[1], "Missing event filter option for {$type}");
            $key = "'integration.eventType.{$type}'";
            $this->assertStringContainsString($key, $en, "Missing English label for {$type}");
            $this->assertStringContainsString($key, $ar, "Missing Arabic label for {$type}");
        }
    }

    public function test_item_type_messages_use_the_item_form_type_names(): void
    {
        foreach (['tracking_type_controlled', 'tracking_history'] as $key) {
            app()->setLocale('en');
            $english = __("inventory.consumption.{$key}");
            $this->assertStringNotContainsString('Stock item', $english);
            $this->assertStringNotContainsString('Non-stock', $english);
            $this->assertStringContainsString('Inventory', $english);
            app()->setLocale('ar');
            $this->assertStringContainsString('غير مخزني', __("inventory.consumption.{$key}"));
            $this->assertStringNotContainsString('مادة', __("inventory.consumption.{$key}"));
        }
        app()->setLocale('en');
    }
}
