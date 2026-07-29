<?php

namespace App\Services\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports a report result to downloadable files. XLSX is emitted as an
 * Excel-compatible spreadsheet XML document; PDF is a print-ready HTML document
 * so the browser can save/print it without adding a heavyweight renderer to the
 * inventory app.
 */
class ReportExportService
{
    /** Sanitize a report key into a safe filename stem (no path traversal). */
    public static function safeFilename(string $reportKey, string $ext): string
    {
        $stem = preg_replace('/[^a-z0-9\-]/i', '-', strtolower($reportKey));
        $stem = trim(preg_replace('/-+/', '-', $stem), '-') ?: 'report';
        $date = now()->format('Ymd-His');

        return "solastock-{$stem}-{$date}.{$ext}";
    }

    /** @param array{key:string,title:string,columns:array,rows:iterable,summary:array} $report */
    public function csv(array $report): StreamedResponse
    {
        $filename = self::safeFilename($report['key'], 'csv');
        $columns = $report['columns'];
        $columnLabels = $report['column_labels'] ?? [];
        $summaryLabels = $report['summary_labels'] ?? [];

        return new StreamedResponse(function () use ($report, $columns, $columnLabels, $summaryLabels) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM keeps Arabic headings intact when opened in Excel.
            fwrite($out, "\xEF\xBB\xBF");
            // Metadata header rows.
            fputcsv($out, [__('inventory.reports.report'), $report['title']]);
            fputcsv($out, [__('inventory.reports.generated'), now()->toDateTimeString()]);
            foreach (($report['summary'] ?? []) as $k => $v) {
                fputcsv($out, [
                    __('inventory.reports.summary', ['label' => $summaryLabels[$k] ?? InventoryReportService::fieldLabel($k)]),
                    is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE),
                ]);
            }
            fputcsv($out, []); // blank separator
            // Column headers + rows.
            fputcsv($out, array_map(fn ($column) => $columnLabels[$column] ?? InventoryReportService::fieldLabel($column), $columns));
            foreach ($report['rows'] as $row) {
                $arr = is_array($row) ? $row : (array) $row;
                fputcsv($out, array_map(fn ($c) => $arr[$c] ?? '', $columns));
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @param array{key:string,title:string,columns:array,rows:iterable,summary:array} $report */
    public function xlsx(array $report): StreamedResponse
    {
        $filename = self::safeFilename($report['key'], 'xls');
        $columns = $report['columns'];
        $columnLabels = $report['column_labels'] ?? [];
        $summaryLabels = $report['summary_labels'] ?? [];

        return new StreamedResponse(function () use ($report, $columns, $columnLabels, $summaryLabels) {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<?mso-application progid="Excel.Sheet"?>'."\n";
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
                .'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="'.e(__('inventory.reports.sheet')).'"><Table>';
            $this->xmlRow([__('inventory.reports.report'), $report['title']]);
            $this->xmlRow([__('inventory.reports.generated'), now()->toDateTimeString()]);
            foreach (($report['summary'] ?? []) as $k => $v) {
                $this->xmlRow([
                    __('inventory.reports.summary', ['label' => $summaryLabels[$k] ?? InventoryReportService::fieldLabel($k)]),
                    is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE),
                ]);
            }
            $this->xmlRow([]);
            $this->xmlRow(array_map(fn ($column) => $columnLabels[$column] ?? InventoryReportService::fieldLabel($column), $columns));
            foreach ($report['rows'] as $row) {
                $arr = is_array($row) ? $row : (array) $row;
                $this->xmlRow(array_map(fn ($c) => $arr[$c] ?? '', $columns));
            }
            echo '</Table></Worksheet></Workbook>';
        }, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @param array{key:string,title:string,columns:array,rows:iterable,summary:array} $report */
    public function pdf(array $report): StreamedResponse
    {
        $filename = self::safeFilename($report['key'], 'html');
        $columns = $report['columns'];
        $columnLabels = $report['column_labels'] ?? [];
        $summaryLabels = $report['summary_labels'] ?? [];
        $locale = app()->getLocale();
        $direction = $locale === 'ar' ? 'rtl' : 'ltr';

        return new StreamedResponse(function () use ($report, $columns, $columnLabels, $summaryLabels, $locale, $direction) {
            echo '<!doctype html><html lang="'.e($locale).'" dir="'.e($direction).'"><head><meta charset="utf-8"><title>'.e($report['title']).'</title>';
            echo '<style>body{font-family:"Noto Sans Arabic","DejaVu Sans",Tahoma,Arial,sans-serif;color:#1f2430;margin:24px;direction:'.e($direction).';text-align:start}h1{font-size:22px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #ddd;padding:6px;text-align:start}th{background:#f5f2eb}.meta{color:#666;margin-bottom:16px}.summary{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.summary div{border:1px solid #ddd;padding:8px 10px;border-radius:6px}bdi,.technical{direction:ltr;unicode-bidi:isolate}</style></head><body>';
            echo '<h1>'.e($report['title']).'</h1><div class="meta">'.e(__('inventory.reports.generated')).' <bdi>'.e(now()->toDateTimeString()).'</bdi></div>';
            if (! empty($report['summary'])) {
                echo '<div class="summary">';
                foreach ($report['summary'] as $k => $v) {
                    echo '<div><strong>'.e($summaryLabels[$k] ?? InventoryReportService::fieldLabel($k)).'</strong><br><bdi>'.e(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)).'</bdi></div>';
                }
                echo '</div>';
            }
            echo '<table><thead><tr>';
            foreach ($columns as $column) {
                echo '<th>'.e($columnLabels[$column] ?? InventoryReportService::fieldLabel($column)).'</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($report['rows'] as $row) {
                $arr = is_array($row) ? $row : (array) $row;
                echo '<tr>';
                foreach ($columns as $column) {
                    $value = $arr[$column] ?? '';
                    echo '<td><bdi>'.e(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)).'</bdi></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table><script>window.addEventListener("load",()=>setTimeout(()=>window.print(),200));</script></body></html>';
        }, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /** @param array<int,mixed> $values */
    private function xmlRow(array $values): void
    {
        echo '<Row>';
        foreach ($values as $value) {
            echo '<Cell><Data ss:Type="String">'.htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value), ENT_XML1 | ENT_COMPAT, 'UTF-8').'</Data></Cell>';
        }
        echo '</Row>';
    }
}
