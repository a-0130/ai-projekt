<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UserDataExportService
{
    public function build(User $user): array
    {
        return [
            'export' => [
                'type' => 'gdpr_user_data',
                'generated_at' => now('Europe/Warsaw')->toIso8601String(),
                'format' => 'pdf',
                'excluded_tables' => [
                    'gtfs_calendars',
                    'gtfs_calendar_dates',
                    'gtfs_routes',
                    'gtfs_shape_groups',
                    'gtfs_shapes',
                    'gtfs_stops',
                    'gtfs_stop_times',
                    'gtfs_trips',
                    'gtfs_feed_versions',
                ],
            ],
            'account' => $this->account($user),
            'tickets' => $this->tickets($user),
            'ride_history' => $this->rideHistory($user),
            'reports' => $this->reports($user),
            'achievements' => $this->achievements($user),
            'discount_codes' => $this->discountCodes($user),
        ];
    }

    public function buildPdf(User $user): string
    {
        $data = $this->build($user);
        $pdf = new UserDataPdfDocument();
        $pdf->addPage();
        $pdf->title('Eksport danych RODO');
        $pdf->paragraph('Dokument zawiera dane powiazane z kontem uzytkownika w systemie. Pelne tabele GTFS nie sa eksportowane.');

        $this->renderAssoc($pdf, 'Informacje o eksporcie', [
            'generated_at' => $data['export']['generated_at'],
            'format' => 'PDF',
        ]);
        $this->renderAssoc($pdf, 'Konto uzytkownika', $data['account']);
        $this->renderRows($pdf, 'Bilety uzytkownika', $data['tickets']);
        $this->renderRows($pdf, 'Historia przejazdow', $data['ride_history']);
        $this->renderRows($pdf, 'Zgloszenia uzytkownika', $data['reports']);
        $this->renderRows($pdf, 'Osiagniecia', $data['achievements']);
        $this->renderRows($pdf, 'Kody rabatowe', $data['discount_codes']);

        return $pdf->output();
    }

    private function account(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'is_admin' => (bool) $user->is_admin,
            'created_at' => $this->dateValue($user->created_at),
        ];
    }

    private function tickets(User $user): array
    {
        if (! Schema::hasTable('user_tickets')) {
            return [];
        }

        return DB::table('user_tickets as ut')
            ->leftJoin('ticket_types as tt', 'tt.id', '=', 'ut.ticket_type_id')
            ->leftJoin('discount_codes as dc', 'dc.id', '=', 'ut.discount_code_id')
            ->where('ut.user_id', $user->id)
            ->orderBy('ut.purchase_date')
            ->select([
                'ut.id',
                'ut.purchase_date',
                'ut.discount_amount',
                'ut.final_price',
                'ut.valid_from',
                'ut.valid_until',
                'ut.is_active',
                'tt.id as ticket_type_id',
                'tt.name as ticket_type_name',
                'tt.price as ticket_type_price',
                'tt.validity_minutes',
                'tt.is_long_term',
                'dc.code as discount_code',
                'dc.discount_percent',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'ticket_type' => [
                    'id' => $row->ticket_type_id !== null ? (int) $row->ticket_type_id : null,
                    'name' => $row->ticket_type_name,
                    'price' => $this->decimalValue($row->ticket_type_price),
                    'validity_minutes' => $row->validity_minutes !== null ? (int) $row->validity_minutes : null,
                    'is_long_term' => (bool) $row->is_long_term,
                ],
                'purchase_date' => $this->dateValue($row->purchase_date),
                'discount_code' => $row->discount_code,
                'discount_percent' => $row->discount_percent !== null ? (int) $row->discount_percent : null,
                'discount_amount' => $this->decimalValue($row->discount_amount),
                'final_price' => $this->decimalValue($row->final_price),
                'valid_from' => $this->dateValue($row->valid_from),
                'valid_until' => $this->dateValue($row->valid_until),
                'is_active' => (bool) $row->is_active,
            ])
            ->all();
    }

    private function rideHistory(User $user): array
    {
        if (! Schema::hasTable('ride_history')) {
            return [];
        }

        return DB::table('ride_history as rh')
            ->leftJoin('gtfs_trips as t', 't.id', '=', 'rh.trip_id')
            ->leftJoin('gtfs_routes as r', 'r.id', '=', 't.route_id')
            ->leftJoin('gtfs_stops as fs', 'fs.id', '=', 'rh.from_stop_id')
            ->leftJoin('gtfs_stops as ts', 'ts.id', '=', 'rh.to_stop_id')
            ->where('rh.user_id', $user->id)
            ->orderBy('rh.created_at')
            ->select([
                'rh.id',
                'rh.created_at',
                'rh.duration_minutes',
                't.trip_id',
                'r.route_short_name',
                'r.route_long_name',
                'fs.stop_name as from_stop_name',
                'ts.stop_name as to_stop_name',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'created_at' => $this->dateValue($row->created_at),
                'duration_minutes' => $row->duration_minutes !== null ? (int) $row->duration_minutes : null,
                'trip_id' => $row->trip_id,
                'route' => [
                    'short_name' => $row->route_short_name,
                    'long_name' => $row->route_long_name,
                ],
                'from_stop' => $row->from_stop_name,
                'to_stop' => $row->to_stop_name,
            ])
            ->all();
    }

    private function reports(User $user): array
    {
        if (! Schema::hasTable('reports')) {
            return [];
        }

        $reports = DB::table('reports')
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get();

        $imagesByReport = $this->reportImages($reports->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $reports->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'description' => (string) $row->description,
            'status' => (string) $row->status,
            'created_at' => $this->dateValue($row->created_at),
            'resolved_at' => $this->dateValue($row->resolved_at ?? null),
            'images' => $imagesByReport[(int) $row->id] ?? [],
        ])->all();
    }

    private function reportImages(array $reportIds): array
    {
        if ($reportIds === [] || ! Schema::hasTable('report_images') || ! Schema::hasTable('images')) {
            return [];
        }

        $rows = DB::table('report_images as ri')
            ->join('images as i', 'i.id', '=', 'ri.image_id')
            ->whereIn('ri.report_id', $reportIds)
            ->select(['ri.report_id', 'i.id', 'i.uuid', 'i.file_name'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->report_id][] = [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'file_name' => (string) $row->file_name,
            ];
        }

        return $out;
    }

    private function achievements(User $user): array
    {
        if (! Schema::hasTable('user_achievements')) {
            return [];
        }

        return DB::table('user_achievements')
            ->where('user_id', $user->id)
            ->orderBy('earned_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'achievement_key' => (string) $row->achievement_key,
                'variant_key' => (string) $row->variant_key,
                'name' => (string) $row->name,
                'description' => (string) $row->description,
                'threshold' => (int) $row->threshold,
                'earned_at' => $this->dateValue($row->earned_at),
            ])
            ->all();
    }

    private function discountCodes(User $user): array
    {
        if (! Schema::hasTable('discount_codes')) {
            return [];
        }

        return DB::table('discount_codes')
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'user_achievement_id' => (int) $row->user_achievement_id,
                'code' => (string) $row->code,
                'discount_percent' => (int) $row->discount_percent,
                'expires_at' => $this->dateValue($row->expires_at),
                'used_at' => $this->dateValue($row->used_at),
                'created_at' => $this->dateValue($row->created_at),
                'updated_at' => $this->dateValue($row->updated_at),
            ])
            ->all();
    }

    private function renderRows(UserDataPdfDocument $pdf, string $title, array $rows): void
    {
        $pdf->section($title);
        if ($rows === []) {
            $pdf->line('Brak danych w tej sekcji.');

            return;
        }

        foreach ($rows as $index => $row) {
            $pdf->subsection('Rekord '.($index + 1));
            $this->renderValues($pdf, $row);
        }
    }

    private function renderAssoc(UserDataPdfDocument $pdf, string $title, array $values): void
    {
        $pdf->section($title);
        $this->renderValues($pdf, $values);
    }

    private function renderList(UserDataPdfDocument $pdf, string $title, array $values): void
    {
        $pdf->section($title);
        foreach ($values as $value) {
            $pdf->line('- '.$this->displayValue($value));
        }
    }

    private function renderValues(UserDataPdfDocument $pdf, array $values, string $prefix = ''): void
    {
        if ($values === []) {
            $pdf->line('Brak danych.');

            return;
        }

        foreach ($values as $key => $value) {
            $label = $this->label((string) $key);
            if ($prefix !== '') {
                $label = $prefix.' - '.$label;
            }

            if (is_array($value)) {
                if ($value === []) {
                    $pdf->entry($label, 'brak');
                } elseif (array_is_list($value)) {
                    $this->renderListValue($pdf, $label, $value);
                } else {
                    $pdf->subsection($label);
                    $this->renderValues($pdf, $value);
                }
            } else {
                $pdf->entry($label, $this->displayValue($value));
            }
        }
    }

    private function renderListValue(UserDataPdfDocument $pdf, string $label, array $values): void
    {
        if ($values === []) {
            $pdf->entry($label, 'brak');

            return;
        }

        if (! is_array($values[0] ?? null)) {
            $pdf->entry($label, implode(', ', array_map(fn (mixed $value): string => $this->displayValue($value), $values)));

            return;
        }

        foreach ($values as $index => $row) {
            $pdf->subsection($label.' '.($index + 1));
            $this->renderValues($pdf, is_array($row) ? $row : ['value' => $row]);
        }
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'brak';
        }
        if (is_bool($value)) {
            return $value ? 'tak' : 'nie';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => $this->displayValue($item), $value));
        }

        return (string) $value;
    }

    private function label(string $key): string
    {
        $labels = [
            'id' => 'ID',
            'name' => 'Nazwa',
            'email' => 'Email',
            'is_admin' => 'Administrator',
            'created_at' => 'Utworzono',
            'updated_at' => 'Zaktualizowano',
            'ticket_type' => 'Typ biletu',
            'price' => 'Cena',
            'validity_minutes' => 'Waznosc w minutach',
            'is_long_term' => 'Bilet dlugookresowy',
            'purchase_date' => 'Data zakupu',
            'discount_code' => 'Kod rabatowy',
            'discount_percent' => 'Procent rabatu',
            'discount_amount' => 'Kwota rabatu',
            'final_price' => 'Cena koncowa',
            'valid_from' => 'Wazny od',
            'valid_until' => 'Wazny do',
            'is_active' => 'Aktywny',
            'duration_minutes' => 'Czas przejazdu w minutach',
            'trip_id' => 'ID kursu',
            'route' => 'Trasa',
            'short_name' => 'Nazwa krotka',
            'long_name' => 'Nazwa trasy',
            'from_stop' => 'Przystanek poczatkowy',
            'to_stop' => 'Przystanek koncowy',
            'title' => 'Tytul',
            'description' => 'Opis',
            'status' => 'Status',
            'resolved_at' => 'Rozwiazano',
            'images' => 'Obrazy',
            'uuid' => 'UUID',
            'file_name' => 'Nazwa pliku',
            'achievement_key' => 'Klucz osiagniecia',
            'variant_key' => 'Wariant',
            'threshold' => 'Prog',
            'earned_at' => 'Zdobyto',
            'user_achievement_id' => 'ID osiagniecia uzytkownika',
            'code' => 'Kod',
            'expires_at' => 'Wygasa',
            'used_at' => 'Uzyto',
            'content' => 'Tresc',
            'published_at' => 'Opublikowano',
            'value' => 'Wartosc',
        ];

        return $labels[$key] ?? str_replace('_', ' ', $key);
    }

    private function dateValue(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function decimalValue(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

}

final class UserDataPdfDocument
{
    private array $pages = [];
    private string $content = '';
    private float $y = 800.0;

    public function addPage(): void
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $this->content = '';
        $this->y = 800.0;
        $this->setGray(0);
    }

    public function output(): string
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
            $this->content = '';
        }

        $objects = [];
        $pageCount = count($this->pages);
        $fontId = 3 + ($pageCount * 2);
        $encodingId = $fontId + 1;
        $kids = [];

        foreach ($this->pages as $index => $stream) {
            $pageId = 3 + ($index * 2);
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = "<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding '.$encodingId.' 0 R >>';
        $objects[$encodingId] = $this->encodingObject();
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xrefAt = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n".$xrefAt."\n%%EOF";

        return $pdf;
    }

    public function title(string $text): void
    {
        $this->text(36, $this->y, $text, 18, true);
        $this->y -= 24;
    }

    public function section(string $text): void
    {
        $this->ensureSpace(34);
        $this->y -= 8;
        $this->setGray(0.9);
        $this->rect(36, $this->y - 5, 523, 20, true);
        $this->setGray(0);
        $this->text(42, $this->y, $text, 12, true);
        $this->y -= 20;
    }

    public function subsection(string $text): void
    {
        $this->ensureSpace(20);
        $this->text(42, $this->y, $text, 9.5, true);
        $this->y -= 13;
    }

    public function paragraph(string $text): void
    {
        foreach ($this->wrap($text, 95) as $line) {
            $this->line($line);
        }
        $this->y -= 4;
    }

    public function entry(string $label, string $value): void
    {
        $lines = $this->wrap($label.': '.$value, 100);
        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    public function line(string $text): void
    {
        $this->ensureSpace(14);
        $this->text(48, $this->y, $text, 8.5);
        $this->y -= 11;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < 36) {
            $this->addPage();
        }
    }

    private function text(float $x, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $value = $this->escape($this->encodeText($text));
        $this->content .= 'BT /F1 '.$this->num($size).' Tf '.$this->num($x).' '.$this->num($y).' Td ('.$value.") Tj ET\n";
        if ($bold) {
            $this->content .= 'BT /F1 '.$this->num($size).' Tf '.$this->num($x + 0.35).' '.$this->num($y).' Td ('.$value.") Tj ET\n";
        }
    }

    private function wrap(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if (mb_strlen($candidate, 'UTF-8') > $maxChars && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function rect(float $x, float $y, float $w, float $h, bool $fill = false): void
    {
        $this->content .= $this->num($x).' '.$this->num($y).' '.$this->num($w).' '.$this->num($h).' re '.($fill ? "f\n" : "S\n");
    }

    private function setGray(float $gray): void
    {
        $this->content .= $this->num($gray)." G\n".$this->num($gray)." g\n";
    }

    private function encodingObject(): string
    {
        return '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [128 /Aogonek /aogonek /Cacute /cacute /Eogonek /eogonek /Lslash /lslash /Nacute /nacute /Sacute /sacute /Zacute /zacute /Zdotaccent /zdotaccent] >>';
    }

    private function encodeText(string $text): string
    {
        $encoded = '';

        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $encoded .= $this->encodeChar($char);
        }

        return $encoded;
    }

    private function encodeChar(string $char): string
    {
        $polish = [
            'Ą' => "\x80",
            'ą' => "\x81",
            'Ć' => "\x82",
            'ć' => "\x83",
            'Ę' => "\x84",
            'ę' => "\x85",
            'Ł' => "\x86",
            'ł' => "\x87",
            'Ń' => "\x88",
            'ń' => "\x89",
            'Ś' => "\x8A",
            'ś' => "\x8B",
            'Ź' => "\x8C",
            'ź' => "\x8D",
            'Ż' => "\x8E",
            'ż' => "\x8F",
        ];

        if (isset($polish[$char])) {
            return $polish[$char];
        }

        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $char);

        return $converted === false ? '' : $converted;
    }

    private function escape(string $text): string
    {
        $escaped = '';

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $byte = ord($text[$i]);
            if ($text[$i] === '\\' || $text[$i] === '(' || $text[$i] === ')' || $byte < 32 || $byte > 126) {
                $escaped .= '\\'.str_pad(decoct($byte), 3, '0', STR_PAD_LEFT);
            } else {
                $escaped .= $text[$i];
            }
        }

        return $escaped;
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}
