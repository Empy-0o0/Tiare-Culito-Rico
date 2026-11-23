<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\OrganizationRules;
use App\Http\Resources\ContactResource;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Services\Crm\OrganizationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContactController extends Controller
{
    public function store(Request $request, Organization $organization, OrganizationService $service, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageContacts', $organization);
        $data = $request->validate(['contact' => ['required', 'array']] + OrganizationRules::contact('contact', true))['contact'];

        $contact = $service->addContact($organization, $data);
        $audit->log('contacts.created', auditableType: 'organization', auditableId: $organization->public_id);

        return ApiResponse::ok(new ContactResource($contact), status: 201);
    }
}
