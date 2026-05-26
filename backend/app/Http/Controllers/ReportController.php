<?php

namespace App\Http\Controllers;

use App\Http\Requests\Report\StoreReportRequest;
use App\Models\Image;
use App\Models\Report;
use App\Models\ReportImage;
use App\Services\AchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function __construct(private readonly AchievementService $achievements)
    {
    }

    public function userIndex(Request $request): JsonResponse
    {
        $reports = Report::query()
            ->with(['images'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Report $report) => $this->reportPayload($report));

        return response()->json(['data' => $reports]);
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        $report = DB::transaction(function () use ($request, $data) {
            $report = Report::create([
                'user_id' => $request->user()->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'created_at' => now(),
                'status' => 'new',
            ]);

            $files = $request->file('images', []);
            if (! is_array($files)) {
                $files = $files ? [$files] : [];
            }

            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $uuid = (string) Str::uuid();
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                if (! in_array($extension, $allowed, true)) {
                    continue;
                }

                $storedName = 'report-images/'.$uuid.'.'.$extension;
                $file->storeAs('report-images', $uuid.'.'.$extension, 'public');

                $image = Image::create([
                    'uuid' => $uuid,
                    'file_name' => $storedName,
                ]);

                ReportImage::create([
                    'report_id' => $report->id,
                    'image_id' => $image->id,
                ]);
            }

            return $report->load('images');
        });
        $this->achievements->sync($request->user());

        return response()->json([
            'message' => 'Zgloszenie utworzone.',
            'report' => $this->reportPayload($report),
        ], 201);
    }

    private function reportPayload(Report $report): array
    {
        return [
            'id' => $report->id,
            'title' => $report->title,
            'description' => $report->description,
            'status' => $report->status,
            'created_at' => $report->created_at,
            'resolved_at' => $report->resolved_at,
            'images' => $report->images->map(fn (Image $image) => [
                'id' => $image->id,
                'uuid' => $image->uuid,
                'file_name' => $image->file_name,
                'url' => $image->url(),
            ])->values(),
        ];
    }
}
