<?php

namespace App\Services\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports a report result to a downloadable file. CSV is implemented; XLSX/PDF
 * are explicit "coming soon" placeholders (never fake success).
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

        return new StreamedResponse(function () use ($report, $columns) {
            $out = fopen('php://output', 'w');
            // Metadata header rows.
            fputcsv($out, ['SolaStock report', $report['title']]);
            fputcsv($out, ['Generated', now()->toDateTimeString()]);
            foreach (($report['summary'] ?? []) as $k => $v) {
                fputcsv($out, ['Summary: '.$k, is_scalar($v) ? $v : json_encode($v)]);
            }
            fputcsv($out, []); // blank separator
            // Column headers + rows.
            fputcsv($out, $columns);
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
}
