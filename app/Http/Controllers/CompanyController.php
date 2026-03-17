<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Http\Requests\UpdatecompanyRequest;
use App\Helpers\ApiResponse;
use App\Helpers\SystemLogHelper;

class CompanyController extends Controller
{

    public function index()
    {
        $company = Company::firstOrFail();

        return ApiResponse::success($company);
    }

    public function update(UpdatecompanyRequest $request, company $company)
    {
        $company = Company::firstOrFail();
        $company->update($request->validated());

        SystemLogHelper::log('company.update.success', 'Company information updated successfully', [
            'company_id' => $company->id,
        ]);

        return ApiResponse::success($company);
    }
}
