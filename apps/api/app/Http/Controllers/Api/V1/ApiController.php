<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class ApiController extends Controller
{
    public function include(string $relationship): bool
    {
        $param = request()->get('include');

        if (! isset($param)) {
            return false;
        }

        $relationship = strtolower($relationship);
        $includeValues = explode(',', strtolower($param));

        foreach ($includeValues as $value) {
            if ($value === $relationship || str_starts_with($value, "{$relationship}.")) {
                return true;
            }
        }

        return false;
    }
}
