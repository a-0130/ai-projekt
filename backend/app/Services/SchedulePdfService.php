<?php

namespace App\Services;

use DateTimeInterface;

final class SchedulePdfService
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN = 36.0;
    private const TILE_SIZE = 256;
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
        $y = 386;
        foreach ($routes as $routeIndex => $route) {
            $routeLabel = $this->routeName($route['route']);
            $color = self::COLORS[$routeIndex % count(self::COLORS)];
            if ($y < 120) {
                $pdf->addPage();
                $y = 790;
            }
            $pdf->setRgb(...$color);
            $pdf->text(36, $y, $routeLabel, 14, true);
            $pdf->setGray(0);
            $y -= 20;

            foreach ($route['directions'] as $direction) {
                if ($y < 140) {
                    $pdf->addPage();
                    $y = 790;
                }
                $headsign = $direction['headsign'] ?: 'kierunek koncowy';
                $pdf->text(36, $y, 'Kierunek: '.$headsign, 11, true);
                $y -= 18;

                $this->drawTableHeader($pdf, $y);
                $y -= 18;

                foreach ($direction['stops'] as $index => $stopRow) {
                    if ($y < 70) {
                        $pdf->addPage();
                        $y = 790;
                        $pdf->text(36, $y, $routeLabel, 11, true);
                        $y -= 18;
                        $this->drawTableHeader($pdf, $y);
                        $y -= 18;
                    }
                    $times = array_map([$this, 'formatTime'], $stopRow['departures']);
                    $timesText = $times === [] ? 'brak kursow' : implode('  ', $times);
                    $stopName = ((int) $stopRow['stop_sequence']).'. '.$stopRow['stop']['stop_name'];
                    if ($y - $this->stopRowHeight($pdf, $stopName, $timesText) < 42) {
                        $pdf->addPage();
                        $y = 790;
                        $pdf->text(36, $y, $routeLabel, 11, true);
                        $y -= 18;
                        $this->drawTableHeader($pdf, $y);
                        $y -= 18;
                    }
                    $y -= $this->drawStopRow($pdf, $y, $stopName, $timesText, $index % 2 === 1);
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

        $titleY = $boxY + $boxH + 8;
        $pdf->setGray(0);
        $pdf->text($boxX, $titleY, 'Mapa tras', 13, true);

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

        $routeCount = max(1, count($routes));
        $mapX = $boxX;
        $mapY = $boxY;
        $mapW = $boxW;
        $mapH = $boxH;
        $mapRender = $this->buildMapBackground($bounds, (int) ($mapW * 2), (int) ($mapH * 2));
        if ($mapRender !== null) {
            $pdf->imageJpeg($mapX, $mapY, $mapW, $mapH, $mapRender['jpeg']);
            $plot = static function (float $lat, float $lon) use ($mapRender, $mapX, $mapY, $mapW, $mapH): array {
                [$px, $py] = SchedulePdfService::latLonToWorld($lat, $lon, $mapRender['zoom']);
                $x = $mapX + (($px - $mapRender['left']) / $mapRender['width']) * $mapW;
                $y = $mapY + $mapH - (($py - $mapRender['top']) / $mapRender['height']) * $mapH;

                return [$x, $y];
            };
        } else {
            $pdf->setGray(0.88);
            for ($x = $mapX + 16; $x < $mapX + $mapW; $x += 38) {
                $pdf->line($x, $mapY, $x, $mapY + $mapH, 0.25);
            }
            for ($gridY = $mapY + 16; $gridY < $mapY + $mapH; $gridY += 34) {
                $pdf->line($mapX, $gridY, $mapX + $mapW, $gridY, 0.25);
            }
            $plot = static function (float $lat, float $lon) use ($bounds, $mapX, $mapY, $mapW, $mapH): array {
                $pad = 30.0;
                $lonRange = max(0.000001, $bounds['maxLon'] - $bounds['minLon']);
                $latRange = max(0.000001, $bounds['maxLat'] - $bounds['minLat']);
                $x = $mapX + $pad + (($lon - $bounds['minLon']) / $lonRange) * ($mapW - 2 * $pad);
                $y = $mapY + $pad + (($bounds['maxLat'] - $lat) / $latRange) * ($mapH - 2 * $pad);

                return [$x, $y];
            };
        }

        $pdf->setGray(0.7);
        $pdf->rect($mapX, $mapY, $mapW, $mapH);

        $routeWidth = $routeCount > 4 ? 1.15 : 1.8;
        $endpointRadius = $routeCount > 4 ? 2.4 : 3.4;
        $stopRadius = $routeCount > 4 ? 0.9 : 1.55;

        $pdf->beginClipRect($mapX, $mapY, $mapW, $mapH);
        foreach ($routes as $routeIndex => $route) {
            $color = self::COLORS[$routeIndex % count(self::COLORS)];
            $pdf->setRgb(...$color);
            foreach ($route['directions'] as $direction) {
                $shape = $direction['shape'];
                for ($i = 1, $count = count($shape); $i < $count; $i++) {
                    [$x1, $y1] = $plot((float) $shape[$i - 1]['lat'], (float) $shape[$i - 1]['lon']);
                    [$x2, $y2] = $plot((float) $shape[$i]['lat'], (float) $shape[$i]['lon']);
                    $pdf->line($x1, $y1, $x2, $y2, $routeWidth);
                }

                foreach ($direction['stops'] as $index => $stopRow) {
                    [$x, $y] = $plot((float) $stopRow['stop']['stop_lat'], (float) $stopRow['stop']['stop_lon']);
                    $isEndpoint = $index === 0 || $index === count($direction['stops']) - 1;
                    $pdf->circle($x, $y, $isEndpoint ? $endpointRadius : $stopRadius, true);
                }
            }
        }
        $pdf->endClip();

        $legendY = $boxY - 16;
        $legendColW = $boxW / 4;
        foreach ($routes as $routeIndex => $route) {
            $col = $routeIndex % 4;
            $row = intdiv($routeIndex, 4);
            $itemY = $legendY - ($row * 10);
            if ($itemY < $boxY - 44) {
                $pdf->setGray(0);
                $pdf->text($boxX, $itemY, '+ '.($routeCount - $routeIndex).' kolejnych tras w PDF', 6.8);
                break;
            }
            $legendX = $boxX + ($col * $legendColW);

            $pdf->setRgb(...self::COLORS[$routeIndex % count(self::COLORS)]);
            $pdf->line($legendX, $itemY + 3, $legendX + 18, $itemY + 3, 2.0);
            $pdf->setGray(0);
            $pdf->text($legendX + 22, $itemY, $this->shorten($route['route']['short_name'] ?? $this->routeName($route['route']), 12), 6.8);
        }
        $pdf->setGray(0);
        $pdf->text($boxX, $boxY - 54, 'Tlo mapy: OpenStreetMap. Trasy wygenerowane z geometrii GTFS.', 7);
    }

    private function buildMapBackground(array $bounds, int $targetW, int $targetH): ?array
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $padLat = max(0.01, ($bounds['maxLat'] - $bounds['minLat']) * 0.2);
        $padLon = max(0.01, ($bounds['maxLon'] - $bounds['minLon']) * 0.2);
        $minLat = max(-85.0, $bounds['minLat'] - $padLat);
        $maxLat = min(85.0, $bounds['maxLat'] + $padLat);
        $minLon = $bounds['minLon'] - $padLon;
        $maxLon = $bounds['maxLon'] + $padLon;

        for ($zoom = 15; $zoom >= 10; $zoom--) {
            [$left, $top] = self::latLonToWorld($maxLat, $minLon, $zoom);
            [$right, $bottom] = self::latLonToWorld($minLat, $maxLon, $zoom);
            $width = max(1.0, $right - $left);
            $height = max(1.0, $bottom - $top);
            $tileCount = (floor($right / self::TILE_SIZE) - floor($left / self::TILE_SIZE) + 1)
                * (floor($bottom / self::TILE_SIZE) - floor($top / self::TILE_SIZE) + 1);
            if ($tileCount <= 12) {
                break;
            }
        }

        $centerX = ($left + $right) / 2;
        $centerY = ($top + $bottom) / 2;
        $neededRatio = $targetW / max(1, $targetH);
        $currentRatio = $width / $height;
        if ($currentRatio < $neededRatio) {
            $width = $height * $neededRatio;
        } else {
            $height = $width / $neededRatio;
        }
        $left = $centerX - ($width / 2);
        $right = $centerX + ($width / 2);
        $top = $centerY - ($height / 2);
        $bottom = $centerY + ($height / 2);

        $tileMinX = (int) floor($left / self::TILE_SIZE);
        $tileMaxX = (int) floor($right / self::TILE_SIZE);
        $tileMinY = max(0, (int) floor($top / self::TILE_SIZE));
        $tileMaxY = min((2 ** $zoom) - 1, (int) floor($bottom / self::TILE_SIZE));
        if (($tileMaxX - $tileMinX + 1) * ($tileMaxY - $tileMinY + 1) > 24) {
            return null;
        }

        $world = imagecreatetruecolor((int) (($tileMaxX - $tileMinX + 1) * self::TILE_SIZE), (int) (($tileMaxY - $tileMinY + 1) * self::TILE_SIZE));
        $bg = imagecolorallocate($world, 239, 242, 237);
        imagefilledrectangle($world, 0, 0, imagesx($world), imagesy($world), $bg);

        $expectedTiles = ($tileMaxX - $tileMinX + 1) * ($tileMaxY - $tileMinY + 1);
        $cacheRoot = function_exists('storage_path') ? storage_path('framework/cache/osm-tiles') : sys_get_temp_dir().'/osm-tiles';
        if (! is_dir($cacheRoot)) {
            @mkdir($cacheRoot, 0775, true);
        }

        $maxTile = 2 ** $zoom;
        $context = stream_context_create([
            'http' => [
                'timeout' => 1.2,
                'header' => "User-Agent: ai-projekt-schedule-pdf/1.0\r\n",
            ],
        ]);
        $deadline = microtime(true) + 12.0;
        $loadedTiles = 0;
        for ($x = $tileMinX; $x <= $tileMaxX; $x++) {
            $wrappedX = (($x % $maxTile) + $maxTile) % $maxTile;
            for ($y = $tileMinY; $y <= $tileMaxY; $y++) {
                if (microtime(true) > $deadline) {
                    break 2;
                }
                $tilePath = $cacheRoot.'/'.$zoom.'-'.$wrappedX.'-'.$y.'.png';
                $tileBytes = is_file($tilePath) ? @file_get_contents($tilePath) : false;
                if ($tileBytes === false) {
                    $tileBytes = @file_get_contents("https://tile.openstreetmap.org/{$zoom}/{$wrappedX}/{$y}.png", false, $context);
                    if ($tileBytes !== false) {
                        @file_put_contents($tilePath, $tileBytes);
                    }
                }
                if ($tileBytes === false) {
                    continue;
                }
                $tile = @imagecreatefromstring($tileBytes);
                if ($tile === false) {
                    continue;
                }
                imagecopy($world, $tile, (int) (($x - $tileMinX) * self::TILE_SIZE), (int) (($y - $tileMinY) * self::TILE_SIZE), 0, 0, self::TILE_SIZE, self::TILE_SIZE);
                $loadedTiles++;
                imagedestroy($tile);
            }
        }

        if ($loadedTiles < $expectedTiles) {
            imagedestroy($world);

            return null;
        }

        $cropX = max(0, (int) round($left - ($tileMinX * self::TILE_SIZE)));
        $cropY = max(0, (int) round($top - ($tileMinY * self::TILE_SIZE)));
        $cropW = min(imagesx($world) - $cropX, (int) round($width));
        $cropH = min(imagesy($world) - $cropY, (int) round($height));
        if ($cropW <= 0 || $cropH <= 0) {
            imagedestroy($world);

            return null;
        }

        $map = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($map, $world, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);
        ob_start();
        imagejpeg($map, null, 82);
        $jpeg = ob_get_clean();
        imagedestroy($map);
        imagedestroy($world);

        if (! is_string($jpeg) || $jpeg === '') {
            return null;
        }

        return [
            'jpeg' => $jpeg,
            'zoom' => $zoom,
            'left' => $left,
            'top' => $top,
            'width' => $width,
            'height' => $height,
        ];
    }

    public static function latLonToWorld(float $lat, float $lon, int $zoom): array
    {
        $lat = max(-85.05112878, min(85.05112878, $lat));
        $scale = self::TILE_SIZE * (2 ** $zoom);
        $x = (($lon + 180.0) / 360.0) * $scale;
        $sinLat = sin(deg2rad($lat));
        $y = (0.5 - log((1 + $sinLat) / (1 - $sinLat)) / (4 * M_PI)) * $scale;

        return [$x, $y];
    }

    private function drawTableHeader(SimpleSchedulePdf $pdf, float $y): void
    {
        $pdf->setGray(0.9);
        $pdf->rect(36, $y - 4, 523, 16, true);
        $pdf->setGray(0.62);
        $pdf->rect(36, $y - 4, 523, 16);
        $pdf->setGray(0);
        $pdf->text(42, $y + 1, 'Przystanek', 8.5, true);
        $pdf->text(232, $y + 1, 'Godziny odjazdow', 8.5, true);
        $pdf->line(224, $y - 4, 224, $y + 12, 0.4);
    }

    private function drawStopRow(SimpleSchedulePdf $pdf, float $y, string $stopName, string $timesText, bool $shade): float
    {
        $stopLines = $pdf->wrap($stopName, 35);
        $timeLines = $pdf->wrap($timesText, 62);
        $height = $this->stopRowHeight($pdf, $stopName, $timesText);

        if ($shade) {
            $pdf->setGray(0.97);
            $pdf->rect(36, $y - $height + 8, 523, $height, true);
        }

        $pdf->setGray(0.78);
        $pdf->rect(36, $y - $height + 8, 523, $height);
        $pdf->line(224, $y - $height + 8, 224, $y + 8, 0.35);
        $pdf->setGray(0);

        $textY = $y;
        foreach ($stopLines as $line) {
            $pdf->text(42, $textY, $line, 8);
            $textY -= 10;
        }

        $textY = $y;
        foreach ($timeLines as $line) {
            $pdf->text(232, $textY, $line, 8);
            $textY -= 10;
        }

        return $height;
    }

    private function stopRowHeight(SimpleSchedulePdf $pdf, string $stopName, string $timesText): float
    {
        $lineCount = max(count($pdf->wrap($stopName, 35)), count($pdf->wrap($timesText, 62)), 1);

        return max(18, 8 + ($lineCount * 10));
    }

    private function routeName(array $route): string
    {
        if (! empty($route['long_name'])) {
            return (string) $route['long_name'];
        }

        return trim((string) $route['short_name']) !== '' ? (string) $route['short_name'] : (string) $route['route_id'];
    }

    private function shorten(string $text, int $maxChars): string
    {
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $maxChars - 3), 'UTF-8')).'...';
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
    private array $images = [];
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
        $encodingId = $fontId + 1;
        $imageStartId = $fontId + 2;
        $xObjects = [];
        foreach ($this->images as $index => $image) {
            $xObjects[] = '/'.$image['name'].' '.($imageStartId + $index).' 0 R';
        }
        $xObjectResource = $xObjects === [] ? '' : ' /XObject << '.implode(' ', $xObjects).' >>';
        $kids = [];

        foreach ($this->pages as $index => $stream) {
            $pageId = 3 + ($index * 2);
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 '.$fontId.' 0 R >>'.$xObjectResource.' >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = "<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding '.$encodingId.' 0 R >>';
        $objects[$encodingId] = $this->encodingObject();
        foreach ($this->images as $index => $image) {
            $objects[$imageStartId + $index] = '<< /Type /XObject /Subtype /Image /Width '.$image['width'].' /Height '.$image['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($image['data'])." >>\nstream\n".$image['data']."\nendstream";
        }
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
        $value = $this->escape($this->encodeText($text));
        $this->content .= "BT /F1 ".$this->num($size)." Tf ".$this->num($x).' '.$this->num($y)." Td (".$value.") Tj ET\n";
        if ($bold) {
            $this->content .= "BT /F1 ".$this->num($size)." Tf ".$this->num($x + 0.35).' '.$this->num($y)." Td (".$value.") Tj ET\n";
        }
    }

    public function wrap(string $text, int $maxChars): array
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

    public function rect(float $x, float $y, float $w, float $h, bool $fill = false): void
    {
        $this->content .= $this->num($x).' '.$this->num($y).' '.$this->num($w).' '.$this->num($h).' re '.($fill ? "f\n" : "S\n");
    }

    public function beginClipRect(float $x, float $y, float $w, float $h): void
    {
        $this->content .= 'q '.$this->num($x).' '.$this->num($y).' '.$this->num($w).' '.$this->num($h)." re W n\n";
    }

    public function endClip(): void
    {
        $this->content .= "Q\n";
    }

    public function imageJpeg(float $x, float $y, float $w, float $h, string $jpeg): void
    {
        $size = @getimagesizefromstring($jpeg);
        if ($size === false) {
            return;
        }

        $name = 'Im'.(count($this->images) + 1);
        $this->images[] = [
            'name' => $name,
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'data' => $jpeg,
        ];
        $this->content .= 'q '.$this->num($w).' 0 0 '.$this->num($h).' '.$this->num($x).' '.$this->num($y).' cm /'.$name." Do Q\n";
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
