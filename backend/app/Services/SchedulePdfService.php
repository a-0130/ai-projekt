<?php

namespace App\Services;

use DateTimeInterface;

final class SchedulePdfService
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN = 36.0;
    private const COLORS = [
        [5, 150, 105],
        [37, 99, 235],
        [220, 38, 38],
        [124, 58, 237],
        [234, 88, 12],
        [8, 145, 178],
    ];

    public function __construct(
        private readonly TransitPlannerService $planner,
    ) {
    }

    public function build(array $routeIds, DateTimeInterface $date): string
    {
        $routes = [];
        foreach (array_values(array_unique(array_map('intval', $routeIds))) as $routeId) {
            $pattern = $this->planner->routePattern($routeId);
            if ($pattern === null) {
                continue;
            }

            foreach ($pattern['directions'] as $directionIndex => $direction) {
                foreach ($direction['stops'] as $stopIndex => $stopRow) {
                    $pattern['directions'][$directionIndex]['stops'][$stopIndex]['departures'] =
                        $this->planner->routeStopDepartures(
                            $routeId,
                            (int) $stopRow['stop']['id'],
                            (int) $direction['direction_key'],
                            $date,
                            (bool) $pattern['endpoint_split'] ? (int) $direction['representative_trip_id'] : null,
                            (bool) $pattern['endpoint_split'],
                        );
                }
            }

            $routes[] = $pattern;
        }

        $pdf = new SimpleSchedulePdf();
        $pdf->addPage();
        $pdf->title('Rozklad jazdy linii');
        $pdf->text(36, 792, 'Data: '.$date->format('Y-m-d'), 10);

        if ($routes === []) {
            $pdf->text(36, 750, 'Nie znaleziono wybranych linii.', 12);

            return $pdf->output();
        }

        $this->drawMap($pdf, $routes);
        $y = 438;
        foreach ($routes as $routeIndex => $route) {
            $routeLabel = $this->routeLabel($route['route']);
            $color = self::COLORS[$routeIndex % count(self::COLORS)];
            if ($y < 120) {
                $pdf->addPage();
                $y = 790;
            }
            $pdf->setRgb(...$color);
            $pdf->text(36, $y, 'Linia '.$routeLabel, 15, true);
            $pdf->setGray(0);
            $y -= 20;

            foreach ($route['directions'] as $direction) {
                if ($y < 140) {
                    $pdf->addPage();
                    $y = 790;
                }
                $headsign = $direction['headsign'] ?: 'kierunek koncowy';
                $pdf->text(36, $y, 'Kierunek: '.$headsign, 11, true);
                $y -= 15;

                foreach ($direction['stops'] as $stopRow) {
                    if ($y < 58) {
                        $pdf->addPage();
                        $y = 790;
                    }
                    $times = array_map([$this, 'formatTime'], $stopRow['departures']);
                    $timesText = $times === [] ? 'brak kursow' : implode('  ', $times);
                    $stopName = ((int) $stopRow['stop_sequence']).'. '.$stopRow['stop']['stop_name'];
                    $lines = $pdf->wrap($stopName.' - '.$timesText, 95);
                    foreach ($lines as $line) {
                        if ($y < 42) {
                            $pdf->addPage();
                            $y = 790;
                        }
                        $pdf->text(48, $y, $line, 8.5);
                        $y -= 11;
                    }
                }
                $y -= 10;
            }
            $y -= 12;
        }

        return $pdf->output();
    }

    private function drawMap(SimpleSchedulePdf $pdf, array $routes): void
    {
        $boxX = 36.0;
        $boxY = 470.0;
        $boxW = 523.0;
        $boxH = 285.0;

        $pdf->setGray(0.95);
        $pdf->rect($boxX, $boxY, $boxW, $boxH, true);
        $pdf->setGray(0.82);
        $pdf->rect($boxX, $boxY, $boxW, $boxH);
        $pdf->setGray(0);
        $pdf->text($boxX + 14, $boxY + $boxH - 24, 'Mapa tras', 13, true);

        $points = [];
        foreach ($routes as $route) {
            foreach ($route['directions'] as $direction) {
                foreach ($direction['shape'] as $point) {
                    $points[] = [(float) $point['lat'], (float) $point['lon']];
                }
                foreach ($direction['stops'] as $stopRow) {
                    $points[] = [(float) $stopRow['stop']['stop_lat'], (float) $stopRow['stop']['stop_lon']];
                }
            }
        }

        if ($points === []) {
            $pdf->text($boxX + 14, $boxY + 136, 'Brak geometrii trasy.', 10);

            return;
        }

        $lats = array_column($points, 0);
        $lons = array_column($points, 1);
        $bounds = [
            'minLat' => min($lats),
            'maxLat' => max($lats),
            'minLon' => min($lons),
            'maxLon' => max($lons),
        ];
        $plot = static function (float $lat, float $lon) use ($bounds, $boxX, $boxY, $boxW, $boxH): array {
            $pad = 30.0;
            $lonRange = max(0.000001, $bounds['maxLon'] - $bounds['minLon']);
            $latRange = max(0.000001, $bounds['maxLat'] - $bounds['minLat']);
            $x = $boxX + $pad + (($lon - $bounds['minLon']) / $lonRange) * ($boxW - 2 * $pad);
            $y = $boxY + $pad + (($bounds['maxLat'] - $lat) / $latRange) * ($boxH - 2 * $pad - 32);

            return [$x, $y];
        };

        foreach ($routes as $routeIndex => $route) {
            $color = self::COLORS[$routeIndex % count(self::COLORS)];
            $pdf->setRgb(...$color);
            foreach ($route['directions'] as $direction) {
                $shape = $direction['shape'];
                for ($i = 1, $count = count($shape); $i < $count; $i++) {
                    [$x1, $y1] = $plot((float) $shape[$i - 1]['lat'], (float) $shape[$i - 1]['lon']);
                    [$x2, $y2] = $plot((float) $shape[$i]['lat'], (float) $shape[$i]['lon']);
                    $pdf->line($x1, $y1, $x2, $y2, 1.8);
                }

                foreach ($direction['stops'] as $index => $stopRow) {
                    if ($index !== 0 && $index !== count($direction['stops']) - 1) {
                        continue;
                    }
                    [$x, $y] = $plot((float) $stopRow['stop']['stop_lat'], (float) $stopRow['stop']['stop_lon']);
                    $pdf->circle($x, $y, 3.2, true);
                }
            }

            $legendY = $boxY + $boxH - 26 - ($routeIndex * 14);
            $pdf->line($boxX + 390, $legendY + 3, $boxX + 420, $legendY + 3, 2.2);
            $pdf->setGray(0);
            $pdf->text($boxX + 426, $legendY, 'Linia '.$this->routeLabel($route['route']), 8.5);
        }
        $pdf->setGray(0);
        $pdf->text($boxX + 14, $boxY + 14, 'Schemat wygenerowany z geometrii GTFS.', 8);
    }

    private function routeLabel(array $route): string
    {
        return trim((string) $route['short_name']) !== '' ? (string) $route['short_name'] : (string) $route['route_id'];
    }

    private function formatTime(string $raw): string
    {
        $parts = explode(':', $raw);

        return count($parts) >= 2 ? $parts[0].':'.$parts[1] : $raw;
    }
}

final class SimpleSchedulePdf
{
    private array $pages = [];
    private string $content = '';

    public function addPage(): void
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $this->content = '';
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
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
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
        $this->text(36, 812, $text, 18, true);
    }

    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $value = $this->escape($this->ascii($text));
        $this->content .= "BT /F1 ".$this->num($size)." Tf ".$this->num($x).' '.$this->num($y)." Td (".$value.") Tj ET\n";
        if ($bold) {
            $this->content .= "BT /F1 ".$this->num($size)." Tf ".$this->num($x + 0.35).' '.$this->num($y)." Td (".$value.") Tj ET\n";
        }
    }

    public function wrap(string $text, int $maxChars): array
    {
        $text = $this->ascii($text);
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if (strlen($candidate) > $maxChars && $line !== '') {
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

    public function rect(float $x, float $y, float $w, float $h, bool $fill = false): void
    {
        $this->content .= $this->num($x).' '.$this->num($y).' '.$this->num($w).' '.$this->num($h).' re '.($fill ? "f\n" : "S\n");
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 1): void
    {
        $this->content .= $this->num($width).' w '.$this->num($x1).' '.$this->num($y1).' m '.$this->num($x2).' '.$this->num($y2)." l S\n";
    }

    public function circle(float $x, float $y, float $r, bool $fill = false): void
    {
        $c = 0.5522847498 * $r;
        $this->content .= $this->num($x + $r).' '.$this->num($y).' m '
            .$this->num($x + $r).' '.$this->num($y + $c).' '.$this->num($x + $c).' '.$this->num($y + $r).' '.$this->num($x).' '.$this->num($y + $r).' c '
            .$this->num($x - $c).' '.$this->num($y + $r).' '.$this->num($x - $r).' '.$this->num($y + $c).' '.$this->num($x - $r).' '.$this->num($y).' c '
            .$this->num($x - $r).' '.$this->num($y - $c).' '.$this->num($x - $c).' '.$this->num($y - $r).' '.$this->num($x).' '.$this->num($y - $r).' c '
            .$this->num($x + $c).' '.$this->num($y - $r).' '.$this->num($x + $r).' '.$this->num($y - $c).' '.$this->num($x + $r).' '.$this->num($y).' c '
            .($fill ? "f\n" : "S\n");
    }

    public function setRgb(int $r, int $g, int $b): void
    {
        $this->content .= $this->num($r / 255).' '.$this->num($g / 255).' '.$this->num($b / 255)." RG\n";
        $this->content .= $this->num($r / 255).' '.$this->num($g / 255).' '.$this->num($b / 255)." rg\n";
    }

    public function setGray(float $gray): void
    {
        $this->content .= $this->num($gray)." G\n".$this->num($gray)." g\n";
    }

    private function ascii(string $text): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '', $text) ?? '' : $converted;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}
