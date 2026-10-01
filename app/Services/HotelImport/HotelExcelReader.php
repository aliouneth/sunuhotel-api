<?php

namespace App\Services\HotelImport;

use DateInterval;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Options as ReaderOptions;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

/**
 * Streaming .xlsx reader for hotel imports.
 *
 * OpenSpout pulls one row at a time out of the sheet XML, so memory stays flat
 * no matter how many rows the workbook holds; nothing here materialises the
 * full sheet. Only the first worksheet is considered (a hotel list is a single
 * table, and silently merging extra tabs would import surprises).
 */
final class HotelExcelReader
{
    /** How many leading rows may be skipped before we call the sheet empty. */
    private const HEADER_SEARCH_DEPTH = 10;

    public function __construct(
        private readonly int $maxRows,
        private readonly int $previewRows,
    ) {}

    /**
     * Reject anything that is not a real .xlsx workbook *before* handing it to
     * the parser: .xls (OLE2), .csv and renamed text files all reach this
     * controller from the "Upload" form.
     */
    public function assertValidWorkbook(string $absolutePath, string $originalName = ''): void
    {
        if (! is_file($absolutePath) || filesize($absolutePath) === 0) {
            throw new HotelImportException('The uploaded file is empty.');
        }

        $extension = strtolower(pathinfo($originalName ?: $absolutePath, PATHINFO_EXTENSION));
        if ($extension !== '' && $extension !== 'xlsx') {
            throw new HotelImportException(
                "Unsupported file type '.{$extension}'. Only .xlsx Excel workbooks are accepted."
            );
        }

        $handle = fopen($absolutePath, 'rb');
        $magic = $handle ? fread($handle, 8) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($magic !== false && str_starts_with($magic, "\xD0\xCF\x11\xE0")) {
            throw new HotelImportException(
                'This looks like a legacy .xls workbook. Please re-save it as .xlsx before importing.'
            );
        }

        if ($magic === false || ! str_starts_with($magic, 'PK')) {
            throw new HotelImportException(
                'This is not an Excel (.xlsx) file. CSV and text files cannot be imported.'
            );
        }

        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new HotelImportException('The .xlsx file is corrupted and could not be opened.');
        }

        $hasWorkbook = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();

        if (! $hasWorkbook) {
            throw new HotelImportException(
                'This is not a valid .xlsx workbook (xl/workbook.xml is missing).'
            );
        }
    }

    /**
     * Single streaming pass that reports everything the mapping screen needs:
     * detected headers, per-column samples, the first N data rows and the total
     * number of data rows in the file.
     *
     * @return array{headers: array<int, array{index: int, label: string}>, preview: array<int, array<string, string>>, samples: array<int, string>, total_rows: int, truncated: bool, sheet: string}
     */
    public function detect(string $absolutePath): array
    {
        $headers = null;
        $headerRowNumber = null;
        $preview = [];
        $samples = [];
        $totalRows = 0;
        $truncated = false;
        $sheetName = '';

        $this->withReader($absolutePath, function (Reader $reader) use (
            &$headers, &$headerRowNumber, &$preview, &$samples,
            &$totalRows, &$truncated, &$sheetName
        ): void {
            $sheetName = $this->firstSheetName($reader);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    $values = $this->rowValues($row->toArray());

                    if ($headers === null) {
                        if ($this->isBlankRow($values)) {
                            continue;
                        }
                        if ($rowIndex >= self::HEADER_SEARCH_DEPTH) {
                            break;
                        }
                        $headers = $this->buildHeaders($values);
                        $headerRowNumber = $rowIndex;
                        $samples = array_fill(0, count($headers), '');

                        continue;
                    }

                    if ($this->isBlankRow($values)) {
                        continue;
                    }

                    if ($totalRows >= $this->maxRows) {
                        $truncated = true;
                        break 2;
                    }

                    $totalRows++;

                    if (count($preview) < $this->previewRows) {
                        $preview[] = [
                            'row_number' => $rowIndex,
                            'values' => $this->pickMappedValues($values, $headers),
                        ];
                    }

                    // Keep the first non-empty sample per column for the mapping UI.
                    foreach ($headers as $columnIndex => $_header) {
                        if (($samples[$columnIndex] ?? '') !== '') {
                            continue;
                        }
                        $value = trim((string) ($values[$columnIndex] ?? ''));
                        if ($value !== '') {
                            $samples[$columnIndex] = mb_substr($value, 0, 120);
                        }
                    }
                }

                break; // only the first sheet
            }
        });

        if ($headers === null || count($headers) === 0) {
            throw new HotelImportException(
                'No column headers were found. The first row of the sheet must contain the column names.'
            );
        }

        $sheetName = $sheetName !== '' ? $sheetName : 'Sheet1';

        return [
            'headers' => $headers,
            // OpenSpout's iterator keys are already 1-based row numbers.
            'header_row' => $headerRowNumber === null ? 1 : $headerRowNumber,
            'preview' => $preview,
            'samples' => $samples,
            'total_rows' => $totalRows,
            'truncated' => $truncated,
            'sheet' => $sheetName,
        ];
    }

    /**
     * Stream every data row of the workbook to the callback.
     *
     * The callback receives (rowNumber, values-by-header-index, rawValues).
     * Returning true from the callback aborts the pass early (used when the row
     * budget is hit).
     *
     * @param  callable(int, array<int, string>, array<int, string>): (bool|void)  $onRow
     * @return array{processed: int, total_rows: int, truncated: bool}
     */
    public function streamRows(string $absolutePath, array $headers, callable $onRow): array
    {
        $processed = 0;
        $totalRows = 0;
        $truncated = false;
        $headerRowNumber = null;

        $this->withReader($absolutePath, function (Reader $reader) use (
            $headers, $onRow, &$processed, &$totalRows, &$truncated, &$headerRowNumber
        ): void {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    $values = $this->rowValues($row->toArray());

                    if ($headerRowNumber === null) {
                        if ($this->isBlankRow($values)) {
                            continue;
                        }
                        $headerRowNumber = $rowIndex;

                        continue;
                    }

                    if ($this->isBlankRow($values)) {
                        continue;
                    }

                    $totalRows++;

                    if ($totalRows > $this->maxRows) {
                        $truncated = true;
                        break 2;
                    }

                    $processed++;

                    if ($onRow($rowIndex, $this->pickMappedValues($values, $headers), $values) === true) {
                        break 2;
                    }
                }

                break;
            }
        });

        return ['processed' => $processed, 'total_rows' => $totalRows, 'truncated' => $truncated];
    }

    /**
     * Build a downloadable starter workbook so administrators know which
     * columns are supported (the mapping UI still accepts any header names).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function writeTemplate(string $absolutePath, array $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($absolutePath);

        foreach ($rows as $values) {
            $writer->addRow(Row::fromValues($values));
        }

        $writer->close();
    }

    /**
     * @return array<int, array{index: int, label: string}>
     */
    private function buildHeaders(array $values): array
    {
        $headers = [];
        $used = [];

        foreach ($values as $index => $value) {
            $label = trim((string) $value);

            if ($label === '') {
                // Preserve positional alignment for headerless columns so the
                // mapping dropdowns stay aligned with the data.
                $label = 'Column '.$this->columnLetter((int) $index + 1);
            }

            $base = $label;
            $suffix = 2;
            while (isset($used[mb_strtolower($label)])) {
                $label = $base.' ('.$suffix++.')';
            }
            $used[mb_strtolower($label)] = true;

            $headers[(int) $index] = ['index' => (int) $index, 'label' => $label];
        }

        // Drop trailing blank columns but keep interior gaps addressable.
        $maxIndex = $headers === [] ? -1 : max(array_keys($headers));
        $trimmed = [];
        for ($i = 0; $i <= $maxIndex; $i++) {
            $trimmed[$i] = $headers[$i] ?? ['index' => $i, 'label' => 'Column '.$this->columnLetter($i + 1)];
        }

        return array_values($trimmed);
    }

    /**
     * @param  array<int, array{index: int, label: string}>  $headers
     * @return array<int, string>
     */
    private function pickMappedValues(array $values, array $headers): array
    {
        $out = [];
        foreach ($headers as $header) {
            $out[$header['index']] = (string) ($values[$header['index']] ?? '');
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function rowValues(array $cells): array
    {
        $out = [];

        foreach ($cells as $index => $cell) {
            $value = $cell instanceof Cell ? $cell->getValue() : $cell;
            $out[(int) $index] = $this->stringify($value);
        }

        return $out;
    }

    /**
     * Flatten whatever OpenSpout hands back into a plain string.
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof DateInterval) {
            return sprintf(
                '%d:%02d:%02d',
                $value->d,
                ($value->h * 24) + $value->i,
                $value->s
            );
        }

        if (is_array($value)) {
            // Rich text runs: concatenate their text.
            $text = '';
            foreach ($value as $run) {
                if (is_object($run) && method_exists($run, 'getText')) {
                    $text .= (string) $run->getText();
                } elseif (is_scalar($run)) {
                    $text .= (string) $run;
                }
            }

            return $text;
        }

        if (is_float($value)) {
            // Avoid "4.0" leaking into an integer/stars column.
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<int, string>  $values
     */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function firstSheetName(Reader $reader): string
    {
        foreach ($reader->getSheetIterator() as $sheet) {
            return $sheet->getName();
        }

        return '';
    }

    /**
     * Run a callback against an open reader and guarantee the handle is closed.
     *
     * @param  callable(Reader): void  $callback
     */
    private function withReader(string $absolutePath, callable $callback): void
    {
        $reader = new Reader(new ReaderOptions(SHOULD_FORMAT_DATES: true));
        $reader->open($absolutePath);

        try {
            $callback($reader);
        } catch (HotelImportException $e) {
            $reader->close();

            throw $e;
        } catch (OpenSpoutException $e) {
            // Only genuine parser failures are reported as "bad file"; anything
            // else is a bug and must surface as a 500 instead of a 422.
            $reader->close();

            throw new HotelImportException(
                'The .xlsx file could not be read ('.$e->getMessage().'). Please re-save it and try again.'
            );
        }

        $reader->close();
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter === '' ? 'A' : $letter;
    }
}
