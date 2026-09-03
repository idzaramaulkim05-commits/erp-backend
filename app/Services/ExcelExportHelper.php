<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelExportHelper
{
    /**
     * Generate an Excel XML (.xls) or CSV (.csv) streaming download response.
     */
    public static function streamExport(
        string $filenameBase,
        string $sheetName,
        array $headers,
        iterable $rows,
        string $format = 'excel'
    ): StreamedResponse {
        $format = strtolower($format);

        if ($format === 'csv') {
            $filename = "{$filenameBase}.csv";
            $headersResp = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
            ];

            return response()->stream(function () use ($headers, $rows) {
                $file = fopen('php://output', 'w');
                fputs($file, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
                fputcsv($file, $headers);
                foreach ($rows as $row) {
                    fputcsv($file, (array)$row);
                }
                fclose($file);
            }, 200, $headersResp);
        }

        // Default: Native XML Spreadsheet 2003 (.xls)
        $filename = "{$filenameBase}.xls";
        $headersResp = [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($sheetName, $headers, $rows) {
            $out = fopen('php://output', 'w');

            fputs($out, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
            fputs($out, "<?mso-application progid=\"Excel.Sheet\"?>\n");
            fputs($out, "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n");
            fputs($out, " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n");
            fputs($out, " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n");
            fputs($out, " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\"\n");
            fputs($out, " xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n");
            fputs($out, " <Styles>\n");
            fputs($out, "  <Style ss:ID=\"Default\" ss:Name=\"Normal\"><Alignment ss:Vertical=\"Center\"/><Font ss:FontName=\"Calibri\" ss:Size=\"11\" ss:Color=\"#000000\"/></Style>\n");
            fputs($out, "  <Style ss:ID=\"Header\"><Alignment ss:Horizontal=\"Center\" ss:Vertical=\"Center\"/><Borders><Border ss:Position=\"Bottom\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#0369A1\"/><Border ss:Position=\"Left\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#0369A1\"/><Border ss:Position=\"Right\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#0369A1\"/><Border ss:Position=\"Top\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#0369A1\"/></Borders><Font ss:FontName=\"Calibri\" ss:Size=\"11\" ss:Color=\"#FFFFFF\" ss:Bold=\"1\"/><Interior ss:Color=\"#0284C7\" ss:Pattern=\"Solid\"/></Style>\n");
            fputs($out, "  <Style ss:ID=\"Data\"><Borders><Border ss:Position=\"Bottom\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#CBD5E1\"/><Border ss:Position=\"Left\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#CBD5E1\"/><Border ss:Position=\"Right\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#CBD5E1\"/><Border ss:Position=\"Top\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#CBD5E1\"/></Borders><Font ss:FontName=\"Calibri\" ss:Size=\"10\" ss:Color=\"#0F172A\"/></Style>\n");
            fputs($out, "  <Style ss:ID=\"Number\"><Alignment ss:Horizontal=\"Right\" ss:Vertical=\"Center\"/><Borders><Border ss:Position=\"Bottom\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#CBD5E1\"/><Border ss:Position=\"Left\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#CBD5E1\"/><Border ss:Position=\"Right\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#CBD5E1\"/><Border ss:Position=\"Top\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#CBD5E1\"/></Borders><Font ss:FontName=\"Calibri\" ss:Size=\"10\" ss:Color=\"#0F172A\"/><NumberFormat ss:Format=\"#,##0\"/></Style>\n");
            fputs($out, " </Styles>\n");
            fputs($out, " <Worksheet ss:Name=\"" . htmlspecialchars($sheetName, ENT_XML1) . "\">\n");
            fputs($out, "  <Table>\n");

            // Header Row
            fputs($out, "   <Row ss:Height=\"24\">\n");
            foreach ($headers as $h) {
                fputs($out, "    <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">" . htmlspecialchars((string)$h, ENT_XML1) . "</Data></Cell>\n");
            }
            fputs($out, "   </Row>\n");

            // Data Rows
            foreach ($rows as $row) {
                fputs($out, "   <Row ss:Height=\"20\">\n");
                foreach ($row as $cell) {
                    if (is_numeric($cell) && !preg_match('/^0\d+/', (string)$cell)) {
                        fputs($out, "    <Cell ss:StyleID=\"Number\"><Data ss:Type=\"Number\">" . $cell . "</Data></Cell>\n");
                    } else {
                        fputs($out, "    <Cell ss:StyleID=\"Data\"><Data ss:Type=\"String\">" . htmlspecialchars((string)($cell ?? '-'), ENT_XML1) . "</Data></Cell>\n");
                    }
                }
                fputs($out, "   </Row>\n");
            }

            fputs($out, "  </Table>\n");
            fputs($out, " </Worksheet>\n");
            fputs($out, "</Workbook>\n");

            fclose($out);
        }, 200, $headersResp);
    }
}
