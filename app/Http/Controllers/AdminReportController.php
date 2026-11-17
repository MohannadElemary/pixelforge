<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminReportRequest;
use App\Services\AdminReportService;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __construct(private readonly AdminReportService $adminReportService) {}

    public function index(AdminReportRequest $request): Response
    {
        return Inertia::render('AdminReport', $this->adminReportService->report(
            $request->reportFilters(), $request->integer('page', 1),
        ));
    }
}
