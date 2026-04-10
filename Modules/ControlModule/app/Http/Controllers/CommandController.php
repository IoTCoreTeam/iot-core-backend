<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ControlModule\QueryBuilders\CommandQueryBuilder;

class CommandController extends Controller
{
    public function __construct(
        private readonly CommandQueryBuilder $commandQueryBuilder
    ) {}

    public function index(Request $request)
    {
        return response()->json($this->commandQueryBuilder->paginate($request));
    }
}
