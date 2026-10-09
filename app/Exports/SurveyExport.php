<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

class SurveyExport extends StringValueBinder implements FromArray, WithCustomValueBinder, WithHeadings
{
    public function __construct(private array $rows, private array $columns) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->columns;
    }
}
