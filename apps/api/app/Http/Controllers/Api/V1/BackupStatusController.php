<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Services\BackupStatus;

class BackupStatusController extends ApiController
{
    public function index(BackupStatus $status)
    {
        return ApiResponse::success($status->report());
    }
}
